<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 1.9.8
 Author: Maksim Safianov
 License: GPL 3.0
 Text Domain: openai-auto-post
*/

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Update Checker - СОХРАНЕНО СТРОГО ПО ИНСТРУКЦИИ
 */
$checker_file = plugin_dir_path(__FILE__) . 'includes/plugin-update-checker-master/plugin-update-checker.php';
if (file_exists($checker_file)) {
    require_once $checker_file;
    if (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/ecompw/openai',
            __FILE__,
            'openai'
        );
        $update_checker->setBranch('main');
    }
}

require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'form.php';

/**
 * Register settings for REST API
 */
add_action('init', function () {
    // Регистрируем тип поста, чтобы миграция могла найти старые данные
    register_post_type('openai_settings', [
        'public' => false,
        'show_ui' => false,
        'supports' => ['title']
    ]);

    $settings = [
        'openai_api_key', 'openai_post_prompt', 'openai_auto_interval',
        'openai_proxy', 'openai_proxy_username', 'openai_proxy_password'
    ];

    foreach ($settings as $setting) {
        register_setting('openai_settings_group', $setting, [
            'show_in_rest' => true,
            'type'         => 'string',
        ]);
    }
});

/**
 * Миграция данных в wp_options
 */
add_action('admin_init', function () {
    if (get_option('openai_migration_completed')) {
        return;
    }

    $old_settings_posts = get_posts([
        'post_type'      => 'openai_settings',
        'posts_per_page' => 1,
        'post_status'    => 'any'
    ]);

    if (!empty($old_settings_posts)) {
        $post_id = $old_settings_posts[0]->ID;
        $keys = ['openai_api_key', 'openai_post_prompt', 'openai_auto_interval', 'openai_proxy', 'openai_proxy_username', 'openai_proxy_password'];

        foreach ($keys as $key) {
            $old_value = get_post_meta($post_id, $key, true);
            if ($old_value) {
                update_option($key, $old_value);
            }
        }
        update_option('openai_migration_completed', time());
    }
});
/**
 * Main generator - ИСПРАВЛЕНО: Читает из options (связь с Django)
 */
function openai_generate_post() {
    $api_key      = get_option('openai_api_key');
    $saved_prompt = get_option('openai_post_prompt');

    if (empty($api_key) || empty(trim((string) $saved_prompt))) {
        return '<div class="error"><p>API key or prompt not set.</p></div>';
    }

    $proxy = [
        'url'      => get_option('openai_proxy'),
        'username' => get_option('openai_proxy_username'),
        'password' => get_option('openai_proxy_password'),
    ];

    // Вызов функции из functions.php
    $content_result = openai_get_generation_gpt5mini($api_key, $saved_prompt, 2048, $proxy);

    if (is_string($content_result) && openai_string_starts_with($content_result, 'OpenAI API Error')) {
        return '<div class="error"><p>' . esc_html($content_result) . '</p></div>';
    }
    // ИСПРАВЛЕНО: Добавлены вызовы функций парсинга (теперь они есть в functions.php)
    [$article_title, $article_body] = extract_title_and_body(format_response($content_result));
    
    if (empty($article_title)) {
        return '<div class="error"><p>Failed to extract title.</p></div>';
    }

    $post_id = wp_insert_post([
        'post_title'   => wp_strip_all_tags($article_title),
        'post_content' => wp_kses_post(wpautop($article_body)),
        'post_status'  => 'publish',
        'post_author'  => 1,
    ]);

    if (is_wp_error($post_id)) return $post_id->get_error_message();

    $image_url = get_random_media_image_url();
    if ($image_url) {
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) set_post_thumbnail($post_id, (int) $attachment_id);
    }

    return '<div class="updated"><p>Post generated successfully!</p></div>';
}

function openai_auto_post_menu() {
    add_menu_page('OpenAI Auto Post', 'OpenAI Auto Post', 'manage_options', 'openai-auto-post', 'openai_auto_post_callback');
    add_submenu_page('openai-auto-post', 'Settings', 'Settings', 'manage_options', 'openai-auto-post-settings', 'openai_auto_post_settings_callback');
}
add_action('admin_menu', 'openai_auto_post_menu');
add_action('openai_scheduled_post_event', 'openai_generate_post');