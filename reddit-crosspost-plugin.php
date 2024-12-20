<?php
/*
Plugin Name: Reddit Cross-Post Plugin
Plugin URI: https://github.com/vestrainteractive/reddit-crosspost-plugin
Description: Cross-posts WordPress posts to specified subreddits based on category or custom input. Includes Reddit OAuth authentication, multiple subreddits per category, and error display on the post page.
Version: 1.0.9
Author: Vestra Interactive
Author URI: https://vestrainteractive.com
*/

// Define a fixed redirect URI as a constant
define('REDDIT_REDIRECT_URI', 'https://domain.com/wp-admin/admin.php?page=reddit-cross-poster'); // Replace with your exact site URL

// Add the admin menu for plugin settings
add_action('admin_menu', 'reddit_cross_poster_menu');
function reddit_cross_poster_menu() {
    add_menu_page('Reddit Cross Poster', 'Reddit Cross Poster', 'manage_options', 'reddit-cross-poster', 'reddit_cross_poster_admin_page');
}

// Plugin admin page
function reddit_cross_poster_admin_page() {
    // Check if OAuth button was clicked
    if (isset($_GET['reddit_oauth'])) {
        reddit_cross_poster_start_oauth();
    }

    if (isset($_GET['code']) && isset($_GET['state'])) {
        reddit_cross_poster_handle_oauth_response($_GET['code']);
    }

    ?>
    <div class="wrap">
        <h1>Reddit Cross Poster Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('reddit_cross_poster_options');
            do_settings_sections('reddit-cross-poster');
            submit_button();
            ?>
        </form>
        <h2>OAuth Authentication</h2>
        <a href="<?php echo esc_url(add_query_arg('reddit_oauth', '1')); ?>" class="button button-primary">Authenticate with Reddit</a>
        <p><?php echo esc_html(get_option('reddit_access_token') ? 'Logged in' : 'Not logged in'); ?></p>
    </div>
    <?php
}

// Start OAuth process with fixed redirect URI
function reddit_cross_poster_start_oauth() {
    $client_id = get_option('reddit_client_id');
    $state = wp_generate_uuid4(); // Generate a random state

    $oauth_url = "https://www.reddit.com/api/v1/authorize?client_id={$client_id}&response_type=code&state={$state}&redirect_uri=" . urlencode(REDDIT_REDIRECT_URI) . "&duration=permanent&scope=submit";

    // Redirect to Reddit for OAuth
    wp_redirect($oauth_url);
    exit;
}

// Handle OAuth response and store the access token
function reddit_cross_poster_handle_oauth_response($code) {
    $client_id = get_option('reddit_client_id');
    $client_secret = get_option('reddit_client_secret');

    $url = 'https://www.reddit.com/api/v1/access_token';

    $response = wp_remote_post($url, array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'User-Agent' => 'YourAppName/0.1 by YourUsername'
        ),
        'body' => array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => REDDIT_REDIRECT_URI
        )
    ));

    if (is_wp_error($response)) {
        error_log("Reddit Cross Poster: " . $response->get_error_message());
        return;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['access_token'])) {
        // Store the access token
        update_option('reddit_access_token', $body['access_token']);
        update_option('reddit_refresh_token', $body['refresh_token']);
        error_log("Reddit Cross Poster: Successfully retrieved access token.");
    } else {
        error_log("Reddit Cross Poster: Failed to retrieve access token - Response: " . print_r($body, true));
    }
}

// Register plugin settings
add_action('admin_init', 'reddit_cross_poster_settings');
function reddit_cross_poster_settings() {
    register_setting('reddit_cross_poster_options', 'reddit_client_id');
    register_setting('reddit_cross_poster_options', 'reddit_client_secret');
    register_setting('reddit_cross_poster_options', 'reddit_category_subreddit_map');

    add_settings_section('reddit_cross_poster_main', 'Reddit API Settings', null, 'reddit-cross-poster');
    
    add_settings_field('reddit_client_id', 'Client ID', 'reddit_client_id_callback', 'reddit-cross-poster', 'reddit_cross_poster_main');
    add_settings_field('reddit_client_secret', 'Client Secret', 'reddit_client_secret_callback', 'reddit-cross-poster', 'reddit_cross_poster_main');
    add_settings_field('reddit_category_subreddit_map', 'Category to Subreddit Mapping', 'reddit_category_subreddit_map_callback', 'reddit-cross-poster', 'reddit_cross_poster_main');
}

function reddit_client_id_callback() {
    echo '<input type="text" name="reddit_client_id" value="' . esc_attr(get_option('reddit_client_id')) . '" />';
}

function reddit_client_secret_callback() {
    echo '<input type="text" name="reddit_client_secret" value="' . esc_attr(get_option('reddit_client_secret')) . '" />';
}

function reddit_category_subreddit_map_callback() {
    echo '<textarea name="reddit_category_subreddit_map" rows="5" cols="50">' . esc_textarea(get_option('reddit_category_subreddit_map')) . '</textarea>';
    echo '<p>Enter in the format: category:subreddit1,subreddit2</p>';
}

// Add meta box in post editor
add_action('add_meta_boxes', 'reddit_cross_poster_meta_box');
function reddit_cross_poster_meta_box() {
    add_meta_box('reddit_cross_poster_meta', 'Reddit Cross Poster', 'reddit_cross_poster_meta_callback', 'post', 'side');
}

function reddit_cross_poster_meta_callback($post) {
    $value = get_post_meta($post->ID, '_reddit_cross_post_manual_subreddit', true);
    $enabled = get_post_meta($post->ID, '_reddit_cross_post_enabled', true);
    ?>
    <label for="reddit_cross_post_enabled">
        <input type="checkbox" name="reddit_cross_post_enabled" id="reddit_cross_post_enabled" value="1" <?php checked($enabled, '1'); ?>>
        Enable Auto-Post to Reddit
    </label>
    <br><br>
    <label for="reddit_cross_post_manual_subreddit">Manual Subreddit:</label>
    <input type="text" name="reddit_cross_post_manual_subreddit" id="reddit_cross_post_manual_subreddit" value="<?php echo esc_attr($value); ?>" />
    <br><br>
    <button type="button" id="reddit_cross_poster_send_now" class="button button-primary">Send Now</button>
    <script>
        document.getElementById('reddit_cross_poster_send_now').addEventListener('click', function() {
            document.getElementById('publish').click(); // Trigger WordPress save and send process
        });
    </script>
    <?php
}

// Save meta box data
add_action('save_post', 'reddit_cross_poster_save_postdata');
function reddit_cross_poster_save_postdata($post_id) {
    if (array_key_exists('reddit_cross_post_manual_subreddit', $_POST)) {
        update_post_meta($post_id, '_reddit_cross_post_manual_subreddit', sanitize_text_field($_POST['reddit_cross_post_manual_subreddit']));
    }
    $enabled = isset($_POST['reddit_cross_post_enabled']) ? '1' : '';
    update_post_meta($post_id, '_reddit_cross_post_enabled', $enabled);
}

// Post to Reddit on post publish
add_action('publish_post', 'reddit_cross_poster_publish_to_reddit');
function reddit_cross_poster_publish_to_reddit($post_id) {
    error_log("Reddit Cross Poster: Attempting to publish post ID $post_id"); // Debugging log

    $client_id = get_option('reddit_client_id');
    $client_secret = get_option('reddit_client_secret');
    $manual_subreddit = get_post_meta($post_id, '_reddit_cross_post_manual_subreddit', true);
    $enabled = get_post_meta($post_id, '_reddit_cross_post_enabled', true);
    $category_subreddit_map = get_option('reddit_category_subreddit_map');
    $token = get_option('reddit_access_token');

    if (!$token) {
        error_log("Reddit Cross Poster: No access token found for post ID $post_id");
        return; // Exit if no access token is available
    }

    if (!$enabled && empty($manual_subreddit)) {
        error_log("Reddit Cross Poster: Auto-posting is disabled and no manual subreddit provided for post ID $post_id");
        return; // Exit if auto-posting is disabled and no manual subreddit is set
    }

    // Retrieve post categories
    $categories = wp_get_post_categories($post_id, array('fields' => 'names'));
    error_log("Reddit Cross Poster: Categories for post ID $post_id - " . implode(', ', $categories)); // Log categories

    $target_subreddits = array();

    // Safely handle category to subreddit mapping
    if (!empty($category_subreddit_map)) {
        foreach (explode("\n", $category_subreddit_map) as $mapping) {
            if (strpos($mapping, ':') !== false) { // Ensure the mapping has a colon
                list($category, $subreddits) = explode(':', trim($mapping));
                $subreddits = array_map('trim', explode(',', $subreddits));
                if (in_array($category, $categories)) {
                    $target_subreddits = array_merge($target_subreddits, $subreddits);
                }
            }
        }
    }

    if (!empty($manual_subreddit)) {
        $target_subreddits[] = $manual_subreddit;
    }

    // Post to each matched subreddit
    if (!empty($target_subreddits)) {
        $title = get_the_title($post_id);
        $url = get_permalink($post_id);
        $excerpt = wp_trim_words(get_the_excerpt($post_id), 55);
        $image = ''; // Set this to the URL of your featured image if applicable

        foreach ($target_subreddits as $subreddit) {
            $success = reddit_cross_poster_submit_to_reddit($token, $title, $url, $excerpt, $image, $subreddit);
            if (!$success) {
                error_log("Reddit Cross Poster: Failed to post to r/$subreddit for post ID $post_id");
            }
        }
    } else {
        error_log("Reddit Cross Poster: No target subreddits found for post ID $post_id");
    }
}

// Get access token from Reddit
function reddit_cross_poster_get_access_token($client_id, $client_secret) {
    $url = 'https://www.reddit.com/api/v1/access_token';

    $response = wp_remote_post($url, array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'User-Agent' => 'YourAppName/0.1 by YourUsername'
        ),
        'body' => array(
            'grant_type' => 'client_credentials'
        )
    ));

    if (is_wp_error($response)) {
        error_log("Reddit Cross Poster: " . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['access_token'])) {
        return $body['access_token'];
    } else {
        error_log("Reddit Cross Poster: Failed to retrieve access token - Response: " . print_r($body, true));
        return false;
    }
}

// Submit the post to Reddit
function reddit_cross_poster_submit_to_reddit($token, $title, $url, $excerpt, $image, $subreddit) {
    $post_url = 'https://oauth.reddit.com/api/submit';

    $response = wp_remote_post($post_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'User-Agent' => 'YourAppName/0.1 by YourUsername'
        ),
        'body' => array(
            'title' => $title,
            'url' => $url,
            'sr' => $subreddit,
            'kind' => 'link', // 'self' if self-post
            'text' => $excerpt,
        )
    ));

    if (is_wp_error($response)) {
        error_log("Reddit Cross Poster: " . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['json']['data']['url'])) {
        error_log("Reddit Cross Poster: Successfully posted to r/$subreddit");
        return true;
    } else {
        error_log("Reddit Cross Poster: Failed to post to r/$subreddit - Response: " . print_r($body, true));
        return false;
    }
}
