<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 2.0.3
 Author: Maksim Safianov
 License: GPL 3.0
 Text Domain: openai-auto-post
*/

if (!defined('ABSPATH')) { exit; }

/**
 * Plugin Update Checker
 */
$checker_file = plugin_dir_path(__FILE__) . 'includes/plugin-update-checker-master/plugin-update-checker.php';
if (file_exists($checker_file)) {
    require_once $checker_file;
    if (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker('https://github.com/ecompw/openai', __FILE__, 'openai')->setBranch('main');
    }
}

require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'form.php';

/**
 * Регистрация настроек
 */
add_action('init', function () {
    $settings = [
        'openai_api_key', 'openai_post_prompt', 'openai_auto_interval',
        'openai_proxy', 'openai_proxy_username', 'openai_proxy_password'
    ];

    foreach ($settings as $setting) {
        register_setting('openai_settings_group', $setting, ['show_in_rest' => true, 'type' => 'string']);
    }

    // ВАЖНО: Добавили 'default', чтобы Django не получал None
    register_setting('openai_settings_group', 'openai_remote_widget_content', [
        'show_in_rest' => true,
        'type'         => 'string',
        'default'      => 'Виджет еще не определен. Зайдите в админку сайта.'
    ]);
});

/**
 * УЛУЧШЕННЫЙ ПОИСКОВИК: Ищет ТОЛЬКО в активных зонах
 */
function force_mark_openai_widget() {
    $marker = '<!-- USEFUL_LINKS -->';
    $sidebars = get_option('sidebars_widgets');
    if (!is_array($sidebars)) return null;

    $best_target = null;
    $max_links = -1;

    // 1. Сначала ищем, нет ли уже помеченного виджета среди АКТИВНЫХ
    foreach ($sidebars as $sidebar_id => $widgets) {
        if ($sidebar_id === 'wp_inactive_widgets' || !is_array($widgets)) continue;

        foreach ($widgets as $widget_id) {
            $target = get_widget_data_by_id($widget_id);
            if ($target && strpos($target['content'], $marker) !== false) {
                return $target; // Нашли уже помеченный!
            }
            
            // Считаем ссылки для выбора лучшего кандидата
            if ($target && strpos($target['content'], 'wp-block-search') === false) {
                $links = substr_count($target['content'], '<a ');
                if ($links > $max_links) {
                    $max_links = $links;
                    $best_target = $target;
                }
            }
        }
    }

    // 2. Если помеченного нет, помечаем лучшего кандидата
    if ($best_target) {
        $all_data = get_option($best_target['option']);
        $new_content = $best_target['content'] . "\n" . $marker;
        if ($best_target['option'] === 'widget_block') {
            $all_data[$best_target['key']]['content'] = $new_content;
        } else {
            $all_data[$best_target['key']]['text'] = $new_content;
        }
        
        update_option($best_target['option'], $all_data);
        return $best_target;
    }

    return null;
}

/**
 * Вспомогательная функция получения данных виджета по его ID (напр. 'block-10')
 */
function get_widget_data_by_id($widget_id) {
    $option_name = '';
    $key = '';
    if (strpos($widget_id, 'block-') === 0) {
        $option_name = 'widget_block';
        $key = (int) str_replace('block-', '', $widget_id);
    } elseif (strpos($widget_id, 'text-') === 0) {
        $option_name = 'widget_text';
        $key = (int) str_replace('text-', '', $widget_id);
    } elseif (strpos($widget_id, 'custom_html-') === 0) {
        $option_name = 'widget_custom_html';
        $key = (int) str_replace('custom_html-', '', $widget_id);
    }

    if ($option_name) {
        $data = get_option($option_name);
        $content = $data[$key]['content'] ?? ($data[$key]['text'] ?? '');
        if ($content) return ['option' => $option_name, 'key' => $key, 'content' => $content];
    }
    return null;
}

/**
 * ГЕТТЕР
 */
add_filter('option_openai_remote_widget_content', function($value) {
    $target = force_mark_openai_widget();
    if (!$target) return 'ВИДЖЕТ НЕ НАЙДЕН (НЕТ АКТИВНЫХ БЛОКОВ)';

    $data = get_option($target['option']);
    $content = $data[$target['key']]['content'] ?? $data[$target['key']]['text'];
    $content = str_replace('<!-- USEFUL_LINKS -->', '', $content);
    return trim(preg_replace('/<!-- \/?wp:html -->/s', '', $content));
});

/**
 * СЕТТЕР
 */
add_filter('pre_update_option_openai_remote_widget_content', function($new_value, $old_value) {
    $new_value = wp_unslash($new_value);
    $target = force_mark_openai_widget();
    if (!$target) return $new_value;

    $all_data = get_option($target['option']);
    $final_content = $new_value . "\n" . '<!-- USEFUL_LINKS -->';

    if ($target['option'] === 'widget_block') {
        $final_content = '<!-- wp:html -->' . "\n" . $final_content . "\n" . '<!-- /wp:html -->';
        $all_data[$target['key']]['content'] = $final_content;
    } else {
        $all_data[$target['key']]['text'] = $final_content;
    }

    update_option($target['option'], $all_data);
    return $new_value;
}, 10, 2);

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