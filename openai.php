<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 1.9.7
 Author: Maksim Safianov
 License: GPL 3.0
 Text Domain: openai-auto-post
*/

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Update Checker
 * Path: openai/includes/plugin-update-checker-master/plugin-update-checker.php
 */
$checker_file = plugin_dir_path(__FILE__) . 'includes/plugin-update-checker-master/plugin-update-checker.php';
if (file_exists($checker_file)) {
    require_once $checker_file;

    if (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/ecompw/openai',
            __FILE__,
            'openai' // IMPORTANT: slug should be "openai" (not "openai/openai.php")
        );
        $update_checker->setBranch('main');
    }
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'form.php';

/**
 * Register settings post type (so get_posts(['post_type'=>'openai_settings']) works reliably).
 */
add_action('init', function () {
    register_post_type('openai_settings', [
        'labels' => [
            'name'          => __('OpenAI Settings', 'openai-auto-post'),
            'singular_name' => __('OpenAI Settings', 'openai-auto-post'),
        ],
        'public'              => false,
        'show_ui'             => false,
        'show_in_menu'        => false,
        'capability_type'     => 'post',
        'supports'            => ['title'],
        'exclude_from_search' => true,
    ]);
});

/**
 * Format response to HTML (very lightweight markdown-ish conversion)
 */
function format_response($response) {
    $response = (string) $response;
    $response = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $response);
    $response = preg_replace('/### (.*?)\n/', '<h3>$1</h3>' . "\n", $response);
    $response = preg_replace('/## (.*?)\n/', '<h2>$1</h2>' . "\n", $response);
    $response = preg_replace('/# (.*?)\n/', '&nbsp;' . "\n", $response);
    return $response;
}

/**
 * Extract title and body from response (<title>...</title>)
 */
function extract_title_and_body($response_content) {
    if (preg_match('/<title>(.*?)<\/title>/is', $response_content, $matches)) {
        $title = trim($matches[1]);
        $body  = str_replace($matches[0], '', $response_content);
        return [$title, $body];
    }
    return ['', $response_content];
}

/**
 * Main generator (used by manual button + cron)
 */
function openai_generate_post() {
    $settings_posts = get_posts([
        'post_type'      => 'openai_settings',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);

    if (empty($settings_posts)) {
        return '<div class="error"><p>No API settings found. Please configure your API settings first.</p></div>';
    }

    $settings_id  = (int) $settings_posts[0]->ID;
    $api_key      = get_post_meta($settings_id, 'openai_api_key', true);
    $saved_prompt = get_post_meta($settings_id, 'openai_post_prompt', true);

    // Append hint (do NOT put an extra "; " in the hint)
    $prompt_hint = 'Enclose the article title with <title> and </title> tags. Format the output using standard Markdown structure (e.g., headings, bullets, emphasis), but do not enclose the output in Markdown blocks or code formatting. Do not include your comments in the output.';
    $prompt      = trim((string) $saved_prompt . "\n\n" . $prompt_hint);

    $proxy = [
        'url'      => get_post_meta($settings_id, 'openai_proxy', true),
        'username' => get_post_meta($settings_id, 'openai_proxy_username', true),
        'password' => get_post_meta($settings_id, 'openai_proxy_password', true),
    ];

    if (empty($api_key) || empty(trim((string) $saved_prompt))) {
        return '<div class="error"><p>API key or prompt not set. Please check your settings.</p></div>';
    }

    // Generate content using gpt-5-mini via Responses API
    $content_result = openai_get_generation_gpt5mini($api_key, $prompt, 2048, $proxy);

    // Reliable error detection (PHP 7.4 compatible helper in functions.php)
    if (is_string($content_result) && openai_string_starts_with($content_result, 'OpenAI API Error')) {
        openai_auto_post_log("Error during content generation: $content_result");
        return '<div class="error"><p>Failed to generate content: ' . esc_html($content_result) . '</p></div>';
    }

    // Extract title/body
    [$article_title, $article_body] = extract_title_and_body(format_response($content_result));
    if (empty($article_title)) {
        openai_auto_post_log("Failed to extract title from content.");
        return '<div class="error"><p>Failed to extract title from content.</p></div>';
    }

    $article_body = format_response($article_body);

    $new_post = [
        'post_title'   => wp_strip_all_tags($article_title),
        'post_content' => wp_kses_post(wpautop($article_body)),
        'post_status'  => 'publish',
        'post_author'  => 1,
    ];

    $post_id = wp_insert_post($new_post, true);
    if (is_wp_error($post_id)) {
        openai_auto_post_log("Failed to insert post: " . $post_id->get_error_message());
        return '<div class="error"><p>Failed to insert post: ' . esc_html($post_id->get_error_message()) . '</p></div>';
    }

    // Featured image
    $image_url = get_random_media_image_url();
    if ($image_url) {
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) {
            set_post_thumbnail($post_id, (int) $attachment_id);
        } else {
            openai_auto_post_log("Failed to derive attachment ID from URL: " . $image_url);
        }
    } else {
        openai_auto_post_log("No image URL found to set as post thumbnail.");
    }

    return '<div class="updated"><p>Post generated and published successfully!</p></div>';
}

/**
 * Admin menu pages
 */
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

/**
 * Hook for scheduled post generation
 * (Scheduling itself is done in form.php when settings are saved)
 */
add_action('openai_scheduled_post_event', 'openai_generate_post');

/**
 * Auto-update (safer matching)
 */
add_filter('auto_update_plugin', function ($update, $item) {
    if (!empty($item->plugin) && $item->plugin === plugin_basename(__FILE__)) {
        return true;
    }
    return $update;
}, 10, 2);