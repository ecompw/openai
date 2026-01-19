<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 2.0.5
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
 * УЛУЧШЕННЫЙ ПОИСКОВИК + СИНХРОНИЗАТОР
 */
function sync_openai_widget_data() {
    $marker = '<!-- USEFUL_LINKS -->';
    $sidebars = get_option('sidebars_widgets');
    if (!is_array($sidebars)) return;

    $best_target = null;
    $max_links = -1;

    // 1. Ищем уже помеченный виджет в активных зонах
    foreach ($sidebars as $sidebar_id => $widgets) {
        if ($sidebar_id === 'wp_inactive_widgets' || !is_array($widgets)) continue;
        foreach ($widgets as $widget_id) {
            $target = get_widget_data_by_id($widget_id);
            if ($target && strpos($target['content'], $marker) !== false) {
                $best_target = $target;
                break 2; 
            }
            // Считаем ссылки для выбора кандидата
            if ($target && strpos($target['content'], 'wp-block-search') === false) {
                $links = substr_count($target['content'], '<a ');
                if ($links > $max_links) {
                    $max_links = $links;
                    $best_target = $target;
                }
            }
        }
    }

    if ($best_target) {
        // Если метки еще нет — ставим её
        if (strpos($best_target['content'], $marker) === false) {
            $all_data = get_option($best_target['option']);
            $new_content = $best_target['content'] . "\n" . $marker;
            if ($best_target['option'] === 'widget_block') {
                $all_data[$best_target['key']]['content'] = $new_content;
            } else {
                $all_data[$best_target['key']]['text'] = $new_content;
            }
            update_option($best_target['option'], $all_data);
            $best_target['content'] = $new_content;
        }

        // ФИЗИЧЕСКИ обновляем опцию, которую читает Django
        $clean_content = str_replace($marker, '', $best_target['content']);
        $clean_content = trim(preg_replace('/<!-- \/?wp:html -->/s', '', $clean_content));
        
        // Чтобы избежать бесконечного цикла, обновляем только если значение изменилось
        if (get_option('openai_remote_widget_content') !== $clean_content) {
            update_option('openai_remote_widget_content', $clean_content);
        }
    }
}

/**
 * СЕТТЕР: Когда Django присылает новый код, мы пишем его ПРЯМО в виджет
 */
add_filter('pre_update_option_openai_remote_widget_content', function($new_value, $old_value) {
    $new_value = wp_unslash($new_value);
    $marker = '<!-- USEFUL_LINKS -->';
    
    // Сначала находим, куда писать
    $sidebars = get_option('sidebars_widgets');
    $target = null;
    foreach ($sidebars as $sidebar_id => $widgets) {
        if ($sidebar_id === 'wp_inactive_widgets' || !is_array($widgets)) continue;
        foreach ($widgets as $widget_id) {
            $data = get_widget_data_by_id($widget_id);
            if ($data && strpos($data['content'], $marker) !== false) {
                $target = $data; break 2;
            }
        }
    }

    if ($target) {
        $all_data = get_option($target['option']);
        $final_content = $new_value . "\n" . $marker;
        if ($target['option'] === 'widget_block') {
            $final_content = '<!-- wp:html -->' . "\n" . $final_content . "\n" . '<!-- /wp:html -->';
            $all_data[$target['key']]['content'] = $final_content;
        } else {
            $all_data[$target['key']]['text'] = $final_content;
        }
        update_option($target['option'], $all_data);
    }

    return $new_value;
}, 10, 2);

// Запускаем синхронизацию при каждом обращении
add_action('init', 'sync_openai_widget_data');

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

/**
 * Вспомогательная функция получения данных виджета по его ID
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
