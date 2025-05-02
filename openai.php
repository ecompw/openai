<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 1.9
 Author: Maksim Safianov
 License: GPL 3.0
 Text Domain: openai-auto-post
 */


$file = plugin_dir_path(__FILE__) . 'includes/plugin-update-checker-master/plugin-update-checker.php';
if (!file_exists($file)) {
    die('File not found: ' . $file);
}
require_once $file;

if (!class_exists('PucFactory')) {
    die('PucFactory class not found.');
}

$update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/ecompw/openai',
    __FILE__,
    'openai/openai.php'  // plugin-folder/plugin-main-file in lowercase folder name
);

$update_checker->setBranch('main');

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'form.php';


// Format response to HTML
function format_response($response) {
    $response = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $response);
    $response = preg_replace('/### (.*?)\n/', '<h3>$1</h3>', $response);
    $response = preg_replace('/## (.*?)\n/', '<h2>$1</h2>', $response);
    $response = preg_replace('/# (.*?)\n/', '&nbsp;', $response);
    return $response;
}

// Extract title and body from response
function extract_title_and_body($response_content) {
    if (preg_match('/<title>(.*?)<\/title>/is', $response_content, $matches)) {
        $title = trim($matches[1]);
        $body = str_replace($matches[0], '', $response_content);
        return [$title, $body];
    }
    return ['', $response_content];
}

// Validate prompt and log errors
function validate_and_log_prompt($prompt) {
    if (empty($prompt)) {
        openai_auto_post_log("Prompt is empty or invalid.");
        return false;
    }
    return true;
}

function openai_generate_post() {
    $settings_posts = get_posts([
        'post_type' => 'openai_settings',
        'post_status' => 'publish',
        'numberposts' => 1
    ]);

    // Verify settings post exists
    if (empty($settings_posts)) {
        return '<div class="error"><p>No API settings found. Please configure your API settings first.</p></div>';
    }

    // Retrieve API settings
    $api_key = get_post_meta($settings_posts[0]->ID, 'openai_api_key', true);
    $prompt_hint = 'Заголовок статьи выдели тегами <title> и </title>. Ответ представь, пожалуйста, в виде отформатированного текста статьи - без твоих комментариев.; ';
    $prompt = get_post_meta($settings_posts[0]->ID, 'openai_post_prompt', true) . $prompt_hint;

    $proxy = [
        'url' => get_post_meta($settings_posts[0]->ID, 'openai_proxy', true),
        'username' => get_post_meta($settings_posts[0]->ID, 'openai_proxy_username', true),
        'password' => get_post_meta($settings_posts[0]->ID, 'openai_proxy_password', true),
    ];

    if (empty($api_key) || empty($prompt)) {
        return '<div class="error"><p>API key or prompt not set. Please check your settings.</p></div>';
    }

    // Generate content using API
    $content_result = openai_get_generation($api_key, $prompt, 2048, $proxy);
    if (strpos($content_result, 'Error:') !== false) {
        openai_auto_post_log("Error during content generation: $content_result");
        return '<div class="error"><p>Failed to generate content: ' . $content_result . '</p></div>';
    }

    // Process content to extract title and body
    list($article_title, $article_body) = extract_title_and_body(format_response($content_result));
    if (empty($article_title)) {
        openai_auto_post_log("Failed to extract title from content.");
        return '<div class="error"><p>Failed to extract title from content.</p></div>';
    }

    // Format the article body
    $article_body = format_response($article_body);

    // Prepare post data
    $new_post = [
        'post_title'   => wp_strip_all_tags($article_title),
        'post_content' => wpautop($article_body),
        'post_status'  => 'publish',
        'post_author'  => 1,
    ];

    // Insert the post into the database
    $post_id = wp_insert_post($new_post);
    if (is_wp_error($post_id)) {
        openai_auto_post_log("Failed to insert post: " . $post_id->get_error_message());
        return '<div class="error"><p>Failed to insert post: ' . $post_id->get_error_message() . '</p></div>';
    }

    // Get a random image from the media library
    $image_url = get_random_media_image_url();
    if ($image_url) {
        openai_auto_post_log("Setting post thumbnail for post ID " . $post_id . " with image URL: " . $image_url);
        
        // Get the attachment ID for the image URL
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
            openai_auto_post_log("Successfully set post thumbnail for post ID " . $post_id);
        } else {
            openai_auto_post_log("Failed to derive attachment ID from URL: " . $image_url);
        }
    } else {
        openai_auto_post_log("No image URL found to set as post thumbnail.");
    }
    
    // Return success message (if necessary per context)
    return '<div class="updated"><p>Post generated and published successfully!</p></div>';
}


// Add admin menu pages
function openai_auto_post_menu() {
    add_menu_page(
        'OpenAI Auto Post',
        'OpenAI Auto Post',
        'manage_options',
        'openai-auto-post',
        'openai_auto_post_callback'
    );
    add_submenu_page(
        'openai-auto-post',
        'Settings',
        'Settings',
        'manage_options',
        'openai-auto-post-settings',
        'openai_auto_post_settings_callback'
    );
}

add_action('admin_menu', 'openai_auto_post_menu');

// Custom interval for every five days
add_filter('cron_schedules', 'openai_custom_cron_schedule');
function openai_custom_cron_schedule($schedules) {
    $schedules['every_five_days'] = [
        'interval' => 5 * DAY_IN_SECONDS,
        'display' => __('Every 5 Days')
    ];
    return $schedules;
}

// Hook for scheduled post generation
add_action('openai_scheduled_post_event', 'openai_generate_post');

// AutoUpdate
add_filter('auto_update_plugin', function($update, $item) {
    if ($item->slug === 'openai') {
        return true;
    }
    return $update;
}, 10, 2);

?>
