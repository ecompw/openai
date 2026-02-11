<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 2.0.9
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
 * КРИТИЧЕСКАЯ МИГРАЦИЯ: Перенос настроек из wp_postmeta в wp_options
 * Соответствует структуре: post_id 26 -> meta_keys (openai_...)
 */
add_action('init', function () {
    // 1. Проверяем, не выполнялась ли миграция ранее
    if (get_option('openai_migration_v3_done')) {
        return;
    }

    // 2. Проверяем, есть ли уже данные в опциях. 
    // Если API ключ уже есть в options, значит миграция не нужна.
    $existing_key = get_option('openai_api_key');
    if (!empty($existing_key)) {
        update_option('openai_migration_v3_done', time());
        return;
    }

    global $wpdb;

    // 3. Ищем ID поста, где лежат старые настройки (на скриншоте это ID 26)
    $post_id = $wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'openai_api_key' LIMIT 1");

    if ($post_id) {
        $settings = [
            'openai_api_key', 
            'openai_post_prompt', 
            'openai_auto_interval',
            'openai_proxy', 
            'openai_proxy_username', 
            'openai_proxy_password'
        ];

        foreach ($settings as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (!empty($value)) {
                update_option($key, $value);
            }
        }

        // 4. Фиксируем успех
        update_option('openai_migration_v3_done', time());
    }
}, 5); // Приоритет 5, чтобы сработало раньше остальных функций плагина

/**
 * УЛУЧШЕННЫЙ ПОИСКОВИК + СИНХРОНИЗАТОР
 */
function sync_openai_widget_data() {
    $marker = '<!-- USEFUL_LINKS -->';
    $widget_options = ['widget_block', 'widget_custom_html', 'widget_text'];
    $found_content = '';

    foreach ($widget_options as $opt_name) {
        $data = get_option($opt_name);
        if (!is_array($data)) continue;
        $changed = false;

        foreach ($data as $key => $fields) {
            if ($key === '_multiwidget' || !is_array($fields)) continue;
            $content = $fields['content'] ?? ($fields['text'] ?? '');
            if (empty($content)) continue;

            if (strpos($content, $marker) !== false) {
                if (empty($found_content)) {
                    $found_content = str_replace($marker, '', $content);
                    $found_content = preg_replace('/<!-- \/?wp:html -->/s', '', $found_content);
                    
                    // КРИТИЧЕСКИЙ ФИКС: Удаляем пустые строки и лишние пробелы
                    $found_content = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $found_content);
                    $found_content = trim($found_content);
                }
                continue;
            }

            if (strpos($content, '<a ') !== false && strpos($content, $marker) === false && strpos($content, 'wp-block-search') === false) {
                if (isset($data[$key]['content'])) $data[$key]['content'] .= "\n" . $marker;
                else $data[$key]['text'] .= "\n" . $marker;
                $changed = true;
            }
        }
        if ($changed) update_option($opt_name, $data);
    }

    if (!empty($found_content)) {
        if (get_option('openai_remote_widget_content') !== $found_content) {
            update_option('openai_remote_widget_content', $found_content);
        }
    }
}

/**
 * СЕТТЕР: Когда Django присылает новый код, мы пишем его ПРЯМО в виджет
 */
add_filter('pre_update_option_openai_remote_widget_content', function($new_value, $old_value) {
    $new_value = wp_unslash($new_value);
    
    // КРИТИЧЕСКИЙ ФИКС: Чистим входящий код от пустых строк перед записью в виджеты
    $new_value = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $new_value);
    $new_value = trim($new_value);
    
    $marker = '<!-- USEFUL_LINKS -->';
    $widget_types = ['widget_block', 'widget_custom_html', 'widget_text'];

    foreach ($widget_types as $type) {
        $data = get_option($type);
        if (!is_array($data)) continue;
        $is_updated = false;

        foreach ($data as $key => $fields) {
            if (!is_array($fields) || $key === '_multiwidget') continue;
            $current_content = $fields['content'] ?? ($fields['text'] ?? '');

            if (strpos($current_content, $marker) !== false || (strpos($current_content, '<a ') !== false && strpos($current_content, 'wp-block-search') === false)) {
                $final_content = $new_value . "\n" . $marker;

                if ($type === 'widget_block') {
                    $final_content = "<!-- wp:html -->\n" . $final_content . "\n<!-- /wp:html -->";
                    $data[$key]['content'] = $final_content;
                } else {
                    if (isset($data[$key]['content'])) $data[$key]['content'] = $final_content;
                    else $data[$key]['text'] = $final_content;
                }
                $is_updated = true;
            }
        }
        if ($is_updated) update_option($type, $data);
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
/**
 * Регистрация эндпоинта для запуска генерации через API
 */
add_action('rest_api_init', function () {
    register_rest_route('openai/v1', '/generate', [
        'methods'             => 'POST',
        'callback'            => 'openai_rest_generate_handler',
        'permission_callback' => function () {
            // Проверка прав: только для тех, кто может публиковать посты
            return current_user_can('publish_posts');
        }
    ]);
});

/**
 * Кастомный эндпоинт для сохранения настроек, обходящий ограничения WP Core
 */
addaction('restapi_init', function () {
    registerrestroute('openai/v1', '/save-settings', [
        'methods'             => 'POST',
        'callback'            => 'openaicustomsavesettingshandler',
        'permission_callback' => function () {
            // Используем те же права, что и для создания постов
            return currentusercan('edit_posts');
        }
    ]);
});

function openaicustomsavesettingshandler($request) {
    $params = $request->getjsonparams();
    if (empty($params)) {
        return new WPError('nodata', 'Данные не получены', ['status' => 400]);
    }

    foreach ($params as $key => $value) {
        // update_option автоматически вызывает все фильтры, 
        // включая ваш syncopenaiwidget_data для виджетов.
        update_option($key, $value);
    }

    return new WPRESTResponse(['status' => 'success', 'message' => 'Settings updated'], 200);
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
