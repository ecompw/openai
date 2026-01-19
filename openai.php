<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 2.0.0
 Author: Maksim Safianov
 License: GPL 3.0
 Text Domain: openai-auto-post
*/

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Update Checker - СТРОГО СОХРАНЕНО
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
 * Register settings for REST API and migration support
 */
add_action('init', function () {
    register_post_type('openai_settings', [
        'public'              => false,
        'show_ui'             => false,
        'capability_type'     => 'post',
        'supports'            => ['title'],
    ]);

    $settings = [
        'openai_api_key', 
        'openai_post_prompt', 
        'openai_auto_interval',
        'openai_proxy', 
        'openai_proxy_username', 
        'openai_proxy_password'
    ];

    // Регистрируем основные настройки в цикле
    foreach ($settings as $setting) {
        register_setting('openai_settings_group', $setting, [
            'show_in_rest' => true,
            'type'         => 'string',
        ]);
    }

    // Регистрируем настройку для виджета ОДИН РАЗ (вне цикла)
    register_setting('openai_settings_group', 'openai_remote_widget_content', [
        'show_in_rest' => true,
        'type'         => 'string',
    ]);
});


/**
 * ЗАПЛАТКА: Автоматическая миграция данных для 500 сайтов
 */
add_action('admin_init', function () {
    if (get_option('openai_migration_v2_complete')) return;

    $old_settings = get_posts(['post_type' => 'openai_settings', 'posts_per_page' => 1, 'post_status' => 'any']);
    if (!empty($old_settings)) {
        $sid = $old_settings[0]->ID;
        $keys = ['openai_api_key', 'openai_post_prompt', 'openai_auto_interval', 'openai_proxy', 'openai_proxy_username', 'openai_proxy_password'];
        foreach ($keys as $key) {
            $val = get_post_meta($sid, $key, true);
            if ($val) update_option($key, $val);
        }
        update_option('openai_migration_v2_complete', time());
    }
});

/**
 * УНИВЕРСАЛЬНЫЙ ПОИСКОВИК "ПУШКА"
 * Ищет первый активный виджет, содержащий ссылки, исключая системные блоки.
 */
function find_openai_seo_widget() {
    $sidebars = get_option('sidebars_widgets');
    if (!is_array($sidebars)) return null;

    // Список того, что мы точно НЕ трогаем
    $exclude_markers = [
        'wp-block-search', 'widget_search', 
        'wp-block-latest-posts', 'widget_recent_entries',
        'wp-block-archives', 'wp-block-categories', 
        'wp-block-latest-comments', 'widget_nav_menu'
    ];

    foreach ($sidebars as $sidebar_id => $widgets) {
        if ($sidebar_id === 'wp_inactive_widgets' || !is_array($widgets)) continue;

        foreach ($widgets as $widget_id) {
            $content = '';
            $option_name = '';
            $key = '';

            // ОПРЕДЕЛЯЕМ ТИП И ПОЛУЧАЕМ КОНТЕНТ
            if (strpos($widget_id, 'block-') === 0) {
                $key = (int) str_replace('block-', '', $widget_id);
                $option_name = 'widget_block';
                $data = get_option($option_name);
                $content = $data[$key]['content'] ?? '';
            } 
            elseif (strpos($widget_id, 'custom_html-') === 0) {
                $key = (int) str_replace('custom_html-', '', $widget_id);
                $option_name = 'widget_custom_html';
                $data = get_option($option_name);
                $content = $data[$key]['content'] ?? '';
            }
            elseif (strpos($widget_id, 'text-') === 0) {
                $key = (int) str_replace('text-', '', $widget_id);
                $option_name = 'widget_text';
                $data = get_option($option_name);
                $content = $data[$key]['text'] ?? ''; // В текстовых виджетах поле называется 'text'
            }

            if (empty($content)) continue;

            // ПРОВЕРКА: Это наш виджет?
            $is_system = false;
            foreach ($exclude_markers as $marker) {
                if (strpos($content, $marker) !== false) { $is_system = true; break; }
            }

            // Если есть ссылка И это не системный блок — МЫ НАШЛИ ЕГО!
            if (!$is_system && strpos($content, '<a ') !== false) {
                return [
                    'option' => $option_name,
                    'key'    => $key,
                    'content'=> $content
                ];
            }
        }
    }
    return null;
}

/**
 * ГЕТТЕР: Вызывается при запросе из Django
 */
add_filter('option_openai_remote_widget_content', function($value) {
    $target = find_openai_seo_widget();
    if (!$target) return '';

    $content = $target['content'];
    // Очищаем от блочных комментариев для чистого отображения в Django
    $content = preg_replace('/<!-- \/?wp:html -->/s', '', $content);
    return trim($content);
});

/**
 * СЕТТЕР: Вызывается при сохранении из Django
 */
add_filter('pre_update_option_openai_remote_widget_content', function($new_value, $old_value) {
    $new_value = wp_unslash($new_value);
    $target = find_openai_seo_widget();
    if (!$target) return $new_value;

    $all_data = get_option($target['option']);
    
    if ($target['option'] === 'widget_block') {
        // Для блоков всегда добавляем обертку, чтобы WP не ломал верстку
        $all_data[$target['key']]['content'] = '<!-- wp:html -->' . "\n" . $new_value . "\n" . '<!-- /wp:html -->';
    } 
    elseif ($target['option'] === 'widget_text') {
        $all_data[$target['key']]['text'] = $new_value;
    }
    else {
        $all_data[$target['key']]['content'] = $new_value;
    }

    update_option($target['option'], $all_data);
    return $new_value;
}, 10, 2);





function format_response($response) {
    $response = (string) $response;
    $response = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $response);
    $response = preg_replace('/### (.*?)\n/', '<h3>$1</h3>' . "\n", $response);
    $response = preg_replace('/## (.*?)\n/', '<h2>$1</h2>' . "\n", $response);
    $response = preg_replace('/# (.*?)\n/', '&nbsp;' . "\n", $response);
    return $response;
}

function extract_title_and_body($response_content) {
    if (preg_match('/<title>(.*?)<\/title>/is', $response_content, $matches)) {
        $title = trim($matches[1]);
        $body  = str_replace($matches[0], '', $response_content);
        return [$title, $body];
    }
    return ['', $response_content];
}

function openai_generate_post() {
    // СТРОГОЕ СОБЛЮДЕНИЕ ИМЕН ПЕРЕМЕННЫХ И ФУНКЦИЙ
    $api_key      = get_option('openai_api_key');
    $saved_prompt = get_option('openai_post_prompt');
    if (empty($api_key) || empty(trim((string) $saved_prompt))) {
        return '<div class="error"><p>API key or prompt not set.</p></div>';
    }

    $prompt_hint = 'Enclose the article title with <title> and </title> tags. Format the output using standard Markdown structure (e.g., headings, bullets, emphasis), but do not enclose the output in Markdown blocks or code formatting. Do not include your comments in the output.';
    $prompt      = trim((string) $saved_prompt . "\n\n" . $prompt_hint);

    $proxy = [
        'url'      => get_option('openai_proxy'),
        'username' => get_option('openai_proxy_username'),
        'password' => get_option('openai_proxy_password'),
    ];

    $content_result = openai_get_generation_gpt5mini($api_key, $prompt, 2048, $proxy);

    if (is_string($content_result) && openai_string_starts_with($content_result, 'OpenAI API Error')) {
        openai_auto_post_log("Error during content generation: $content_result");
        return '<div class="error"><p>Failed to generate content: ' . esc_html($content_result) . '</p></div>';
    }

    [$article_title, $article_body] = extract_title_and_body(format_response($content_result));
    if (empty($article_title)) {
        openai_auto_post_log("Failed to extract title from content.");
        return '<div class="error"><p>Failed to extract title from content.</p></div>';
    }

    $article_body = format_response($article_body);

    $post_id = wp_insert_post([
        'post_title'   => wp_strip_all_tags($article_title),
        'post_content' => wp_kses_post(wpautop($article_body)),
        'post_status'  => 'publish',
        'post_author'  => 1,
    ], true);

    if (is_wp_error($post_id)) {
        return '<div class="error"><p>Failed to insert post: ' . esc_html($post_id->get_error_message()) . '</p></div>';
    }

    $image_url = get_random_media_image_url();
    if ($image_url) {
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) set_post_thumbnail($post_id, (int) $attachment_id);
    }

    return '<div class="updated"><p>Post generated and published successfully!</p></div>';
}

function openai_auto_post_menu() {
    add_menu_page('OpenAI Auto Post', 'OpenAI Auto Post', 'manage_options', 'openai-auto-post', 'openai_auto_post_callback');
    add_submenu_page('openai-auto-post', 'Settings', 'Settings', 'manage_options', 'openai-auto-post-settings', 'openai_auto_post_settings_callback');
}
add_action('admin_menu', 'openai_auto_post_menu');
add_action('openai_scheduled_post_event', 'openai_generate_post');