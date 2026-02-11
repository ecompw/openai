<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 2.0.20
 Author: Maksim Safianov
 License: GPL 3.0
 Text Domain: openai-auto-post
*/

if (!defined('ABSPATH')) { exit; }

/**
 * Plugin Update Checker
 */
$checker_file = plugin_dir_path(__FILE__) . 'includes/plugin-update-checker-master/plugin-update-checker.php';

if (is_admin() && is_readable($checker_file)) {
    include_once $checker_file;
    $puc_class = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
    if (class_exists($puc_class)) {
        try {
            $checker = call_user_func([$puc_class, 'buildUpdateChecker'], 'https://github.com/ecompw/openai', __FILE__, 'openai');
            if ($checker && method_exists($checker, 'setBranch')) {
                $checker->setBranch('main');
            }
        } catch (Throwable $e) {
            error_log('OpenAI Auto Post: Update checker failed to initialize: ' . $e->getMessage());
        }
    } else {
        error_log('OpenAI Auto Post: Update checker class not found in included file.');
    }
}

// Подключаем дополнительные файлы, только если они существуют (избежать фатальной ошибки)
$functions_file = plugin_dir_path(__FILE__) . 'functions.php';
$form_file = plugin_dir_path(__FILE__) . 'form.php';
if (file_exists($functions_file)) {
    require_once $functions_file;
}
if (file_exists($form_file)) {
    require_once $form_file;
}

/**
 * БЕЗОПАСНОЕ ВКЛЮЧЕНИЕ BASIC AUTH ДЛЯ REST API
 *
 * Если Basic auth входит в заголовки, пытаемся аутентифицировать пользователя
 * и устанавливаем текущего пользователя (wp_set_current_user).
 */
add_filter('determine_current_user', function ($user_id) {
    if ($user_id) {
        return $user_id;
    }

    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
        return $user_id;
    }

    $user = wp_authenticate($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);

    if (is_wp_error($user) || !is_object($user)) {
        return $user_id;
    }

    // Явно устанавливаем текущего пользователя в WordPress
    wp_set_current_user($user->ID);
    return $user->ID;
}, 20);

/**
 * Регистрация эндпоинтов для REST API
 */
add_action('rest_api_init', function () {
    // Эндпоинт для генерации поста
    register_rest_route('openai/v1', '/generate', [
        'methods'             => 'POST',
        'callback'            => 'openai_rest_generate_handler',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ]);

    // Эндпоинт для управления настройками и виджетами (GET и POST)
    register_rest_route('openai/v1', '/save-settings', [
        'methods'             => ['GET', 'POST'],
        'callback'            => 'openai_rest_settings_handler',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ]);

    // Доп. кастомный обработчик (если нужен) — можно зарегистрировать отдельно с проверкой прав
    register_rest_route('openai/v1', '/custom-save-settings', [
        'methods'             => 'POST',
        'callback'            => 'openai_custom_save_settings_handler',
        'permission_callback' => function () {
            return current_user_can('manage_options'); // более строгая проверка
        }
    ]);
});

/**
 * Обработчик генерации
 * Принимаем $request для совместимости (можно не использовать)
 */
function openai_rest_generate_handler($request = null) {
    if (!function_exists('openai_generate_post')) {
        return new WP_Error('missing_function', 'Функция openai_generate_post не найдена в functions.php', ['status' => 500]);
    }

    $result = openai_generate_post();
    return new WP_REST_Response(['status' => 'success', 'message' => $result], 200);
}

/**
 * Обработчик настроек
 */
function openai_rest_settings_handler($request) {
    $keys = [
        'openai_api_key',
        'openai_post_prompt',
        'openai_auto_interval',
        'openai_proxy',
        'openai_proxy_username',
        'openai_proxy_password',
        'openai_remote_widget_content'
    ];

    if ($request->get_method() === 'POST') {
        $params = $request->get_json_params();
        if (is_array($params)) {
            foreach ($keys as $key) {
                if (isset($params[$key])) {
                    // Приводим к строке и безопасно сохраняем
                    update_option($key, (string)$params[$key]);
                }
            }
        }
    }

    $res = [];
    foreach ($keys as $key) {
        // Гарантируем, что REST API получит строку
        $res[$key] = (string)get_option($key, '');
    }
    return new WP_REST_Response($res, 200);
}

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

    // Добавили 'default', чтобы REST и внешний код не получали null
    register_setting('openai_settings_group', 'openai_remote_widget_content', [
        'show_in_rest' => true,
        'type'         => 'string',
        'default'      => 'Виджет еще не определен. Зайдите в админку сайта.'
    ]);
});

/**
 * КРИТИЧЕСКАЯ МИГРАЦИЯ: Перенос настроек из wp_postmeta в wp_options
 */
add_action('init', function () {
    // 1. Проверяем, не выполнялась ли миграция ранее
    if (get_option('openai_migration_v3_done')) {
        return;
    }

    // 2. Если ключ уже есть в options, миграция не нужна
    $existing_key = get_option('openai_api_key');
    if (!empty($existing_key)) {
        update_option('openai_migration_v3_done', time());
        return;
    }

    global $wpdb;

    // 3. Ищем ID поста, где лежат старые настройки
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
}, 5);

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

            // Получаем содержимое безопасно
            $content = '';
            if (isset($fields['content'])) {
                $content = (string)$fields['content'];
            } elseif (isset($fields['text'])) {
                $content = (string)$fields['text'];
            } else {
                continue;
            }

            if ($content === '') continue;

            if (strpos($content, $marker) !== false) {
                if ($found_content === '') {
                    $found_content = str_replace($marker, '', $content);
                    // Удаляем обёртки <!-- wp:html --> и пустые строки
                    $found_content = preg_replace('/<!--\s*\/?wp:html\s*-->/is', '', $found_content);
                    $found_content = preg_replace("/(\r\n|\n|\r){2,}/", "\n", $found_content);
                    $found_content = trim($found_content);
                }
                continue;
            }

            // Если есть ссылки, но нет маркера и не является поисковым блоком, добавляем маркер
            if (strpos($content, '<a ') !== false && strpos($content, $marker) === false && strpos($content, 'wp-block-search') === false) {
                if (isset($data[$key]['content'])) {
                    $data[$key]['content'] = $data[$key]['content'] . "\n" . $marker;
                } else {
                    // безопасно обновляем text
                    $data[$key]['text'] = (isset($data[$key]['text']) ? $data[$key]['text'] : '') . "\n" . $marker;
                }
                $changed = true;
            }
        }

        if ($changed) {
            update_option($opt_name, $data);
        }
    }

    if ($found_content !== '') {
        if (get_option('openai_remote_widget_content') !== $found_content) {
            update_option('openai_remote_widget_content', $found_content);
        }
    }
}

/**
 * СЕТТЕР: Когда внешний сервис присылает новый код, мы пишем его в виджеты
 */
add_filter('pre_update_option_openai_remote_widget_content', function($new_value, $old_value) {
    $new_value = wp_unslash($new_value);

    // Чистим входящий код от лишних пустых строк
    $new_value = preg_replace("/(\r\n|\n|\r){2,}/", "\n", $new_value);
    $new_value = trim($new_value);

    $marker = '<!-- USEFUL_LINKS -->';
    $widget_types = ['widget_block', 'widget_custom_html', 'widget_text'];

    foreach ($widget_types as $type) {
        $data = get_option($type);
        if (!is_array($data)) continue;
        $is_updated = false;

        foreach ($data as $key => $fields) {
            if (!is_array($fields) || $key === '_multiwidget') continue;

            $current_content = '';
            if (isset($fields['content'])) {
                $current_content = (string)$fields['content'];
            } elseif (isset($fields['text'])) {
                $current_content = (string)$fields['text'];
            }

            if ($current_content === '') continue;

            if (strpos($current_content, $marker) !== false || (strpos($current_content, '<a ') !== false && strpos($current_content, 'wp-block-search') === false)) {
                $final_content = $new_value . "\n" . $marker;

                if ($type === 'widget_block') {
                    $final_content = "<!-- wp:html -->\n" . $final_content . "\n<!-- /wp:html -->";
                    $data[$key]['content'] = $final_content;
                } else {
                    if (isset($data[$key]['content'])) {
                        $data[$key]['content'] = $final_content;
                    } else {
                        $data[$key]['text'] = $final_content;
                    }
                }
                $is_updated = true;
            }
        }

        if ($is_updated) {
            update_option($type, $data);
        }
    }

    return $new_value;
}, 10, 2);

// Запускаем синхронизацию при каждом обращении (можно переместить в cron при необходимости)
add_action('init', 'sync_openai_widget_data');

/**
 * Форматируем ответ (markdown -> HTML)
 * Улучшенные регулярные выражения: многострочная обработка заголовков и жирного текста.
 */
function format_response($response) {
    $response = (string) $response;

    // Жирный текст **text**
    $response = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $response);

    // Заголовки — многострочный режим
    // ### -> h3
    $response = preg_replace('/^###\s*(.+)$/m', '<h3>$1</h3>', $response);
    // ## -> h2
    $response = preg_replace('/^##\s*(.+)$/m', '<h2>$1</h2>', $response);
    // # -> h1
    $response = preg_replace('/^#\s*(.+)$/m', '<h1>$1</h1>', $response);

    // Можно добавить дополнительные правила (списки, ссылки и т.д.) если нужно

    return $response;
}

/**
 * Извлекает <title>...</title> из контента и возвращает [title, body]
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
 * Генерация и публикация поста
 */
function openai_generate_post() {
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

    // Проверяем наличие функции генерации (в functions.php)
    if (!function_exists('openai_get_generation_gpt5mini')) {
        return '<div class="error"><p>Generation function not available.</p></div>';
    }

    $content_result = openai_get_generation_gpt5mini($api_key, $prompt, 2048, $proxy);

    if (is_string($content_result) && function_exists('openai_string_starts_with') && openai_string_starts_with($content_result, 'OpenAI API Error')) {
        if (function_exists('openai_auto_post_log')) {
            openai_auto_post_log("Error during content generation: $content_result");
        }
        return '<div class="error"><p>Failed to generate content: ' . esc_html($content_result) . '</p></div>';
    }

    // Один раз форматируем ответ
    $formatted = format_response($content_result);

    [$article_title, $article_body] = extract_title_and_body($formatted);
    if (empty($article_title)) {
        if (function_exists('openai_auto_post_log')) {
            openai_auto_post_log("Failed to extract title from content.");
        }
        return '<div class="error"><p>Failed to extract title from content.</p></div>';
    }

    // Автор поста — текущий пользователь, если есть, иначе admin (1)
    $author_id = get_current_user_id();
    if (empty($author_id)) $author_id = 1;

    $post_id = wp_insert_post([
        'post_title'   => wp_strip_all_tags($article_title),
        'post_content' => wp_kses_post(wpautop($article_body)),
        'post_status'  => 'publish',
        'post_author'  => (int)$author_id,
    ], true);

    if (is_wp_error($post_id)) {
        return '<div class="error"><p>Failed to insert post: ' . esc_html($post_id->get_error_message()) . '</p></div>';
    }

    // Присвоение миниатюры (если есть URL и он соответствует загруженному attachment)
    if (function_exists('get_random_media_image_url')) {
        $image_url = get_random_media_image_url();
        if ($image_url) {
            $attachment_id = attachment_url_to_postid($image_url);
            if ($attachment_id) {
                set_post_thumbnail($post_id, (int) $attachment_id);
            }
        }
    }

    return '<div class="updated"><p>Post generated and published successfully!</p></div>';
}

/**
 * Кастомный обработчик сохранения настроек — более строгая проверка прав
 */
function openai_custom_save_settings_handler($request) {
    if (!current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'Нет прав для выполнения операции', ['status' => 403]);
    }

    $params = $request->get_json_params();
    if (empty($params) || !is_array($params)) {
        return new WP_Error('no_data', 'Данные не получены', ['status' => 400]);
    }

    foreach ($params as $key => $value) {
        // Для безопасности можно ограничить список допустимых ключей.
        update_option($key, $value);
    }

    return new WP_REST_Response(['status' => 'success', 'message' => 'Settings updated'], 200);
}

/**
 * Админ-меню (callback-функции должны быть определены в подключённых файлах)
 */
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

    if ($option_name !== '') {
        $data = get_option($option_name);
        if (is_array($data) && array_key_exists($key, $data) && is_array($data[$key])) {
            $content = isset($data[$key]['content']) ? $data[$key]['content'] : (isset($data[$key]['text']) ? $data[$key]['text'] : '');
            if ($content !== '') {
                return ['option' => $option_name, 'key' => $key, 'content' => $content];
            }
        }
    }
    return null;
}