<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 2.0.2
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
 * ЯДЕРНЫЙ ПОИСКОВИК: Ищет виджет по количеству ссылок
 */
function force_mark_openai_widget() {
    $marker = '<!-- USEFUL_LINKS -->';
    $types = ['widget_block', 'widget_custom_html', 'widget_text'];
    
    $best_option = null;
    $best_key = null;
    $max_links = -1;

    foreach ($types as $type) {
        $data = get_option($type);
        if (!is_array($data)) continue;

        foreach ($data as $key => $fields) {
            if (!is_array($fields) || $key === '_multiwidget') continue;
            
            $content = $fields['content'] ?? ($fields['text'] ?? '');
            if (empty($content)) continue;

            // Если метка уже стоит, мы нашли цель!
            if (strpos($content, $marker) !== false) return ['option' => $type, 'key' => $key];

            // Считаем количество ссылок в виджете
            $link_count = substr_count($content, '<a ');
            
            // Исключаем системные блоки (поиск и т.д.)
            if (strpos($content, 'wp-block-search') !== false) continue;

            // Нам нужен виджет, где ссылок больше всего (обычно это и есть SEO-блок)
            if ($link_count > $max_links) {
                $max_links = $link_count;
                $best_option = $type;
                $best_key = $key;
            }
        }
    }

    // Если нашли подходящий виджет — клеймим его!
    if ($best_option && $best_key !== null) {
        $data = get_option($best_option);
        $current_content = $data[$best_key]['content'] ?? $data[$best_key]['text'];
        
        $new_content = $current_content . "\n" . $marker;
        
        if (isset($data[$best_key]['content'])) $data[$best_key]['content'] = $new_content;
        else $data[$best_key]['text'] = $new_content;

        update_option($best_option, $data);
        return ['option' => $best_option, 'key' => $best_key];
    }

    return null;
}

/**
 * ГЕТТЕР: Теперь работает через Ядерный Поиск
 */
add_filter('option_openai_remote_widget_content', function($value) {
    $target = force_mark_openai_widget();
    if (!$target) return 'ВИДЖЕТ НЕ НАЙДЕН';

    $data = get_option($target['option']);
    $content = $data[$target['key']]['content'] ?? $data[$target['key']]['text'];
    
    $content = str_replace('<!-- USEFUL_LINKS -->', '', $content);
    $content = preg_replace('/<!-- \/?wp:html -->/s', '', $content);
    return trim($content);
});

/**
 * СЕТТЕР: Сохраняет точно в цель
 */
add_filter('pre_update_option_openai_remote_widget_content', function($new_value, $old_value) {
    $new_value = wp_unslash($new_value);
    $target = force_mark_openai_widget();
    if (!$target) return $new_value;

    $data = get_option($target['option']);
    $final_content = $new_value . "\n" . '<!-- USEFUL_LINKS -->';

    if ($target['option'] === 'widget_block') {
        $final_content = '<!-- wp:html -->' . "\n" . $final_content . "\n" . '<!-- /wp:html -->';
    }

    if (isset($data[$target['key']]['content'])) $data[$target['key']]['content'] = $final_content;
    else $data[$target['key']]['text'] = $final_content;

    update_option($target['option'], $data);
    return $new_value;
}, 10, 2);

// Запускаем поиск при каждом заходе в админку или на сайт (для теста)
add_action('init', 'force_mark_openai_widget');




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