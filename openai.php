<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 2.0.1
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
 * 1. ПОИСК И МАРКИРОВКА (Внутренняя функция)
 * Ищет виджет с ссылками и ставит на него невидимую метку.
 */
function get_openai_marked_widget($force_mark = false) {
    $widget_options = ['widget_block', 'widget_custom_html', 'widget_text'];
    $marker = '<!-- USEFUL_LINKS -->';

    foreach ($widget_options as $opt_name) {
        $data = get_option($opt_name);
        if (!is_array($data)) continue;

        foreach ($data as $key => $fields) {
            if (!is_array($fields)) continue;
            
            // Проверяем контент (в разных виджетах поле называется по-разному)
            $content = $fields['content'] ?? ($fields['text'] ?? '');
            if (empty($content)) continue;

            // ШАГ 1: Если метка уже есть — это наш виджет!
            if (strpos($content, $marker) !== false) {
                return ['option' => $opt_name, 'key' => $key, 'content' => $content];
            }

            // ШАГ 2: Если метки нет, но мы в режиме поиска (force_mark)
            if ($force_mark && strpos($content, '<a ') !== false && strpos($content, 'wp-block-search') === false) {
                // Ставим метку и сохраняем
                $new_content = $content . "\n" . $marker;
                if (isset($data[$key]['content'])) $data[$key]['content'] = $new_content;
                else $data[$key]['text'] = $new_content;
                
                update_option($opt_name, $data);
                return ['option' => $opt_name, 'key' => $key, 'content' => $new_content];
            }
        }
    }
    return null;
}

/**
 * 2. ГЕТТЕР: Вызывается при запросе из Django
 */
add_filter('option_openai_remote_widget_content', function($value) {
    // Пытаемся найти помеченный виджет, если нет — ищем и помечаем
    $target = get_openai_marked_widget(true); 
    if (!$target) return '';

    $content = $target['content'];
    // Убираем метку и обертки блоков, чтобы в Django был чистый HTML
    $content = str_replace('<!-- USEFUL_LINKS -->', '', $content);
    $content = preg_replace('/<!-- \/?wp:html -->/s', '', $content);
    
    return trim($content);
});

/**
 * 3. СЕТТЕР: Вызывается при сохранении из Django
 */
add_filter('pre_update_option_openai_remote_widget_content', function($new_value, $old_value) {
    $new_value = wp_unslash($new_value);
    $marker = '<!-- USEFUL_LINKS -->';
    
    $target = get_openai_marked_widget(true);
    if (!$target) return $new_value;

    $all_data = get_option($target['option']);
    
    // Формируем финальный контент с меткой
    $final_content = $new_value . "\n" . $marker;

    // Если это блок, оборачиваем в стандарты WP
    if ($target['option'] === 'widget_block') {
        $final_content = '<!-- wp:html -->' . "\n" . $final_content . "\n" . '<!-- /wp:html -->';
    }

    // Записываем в нужное поле
    if (isset($all_data[$target['key']]['content'])) {
        $all_data[$target['key']]['content'] = $final_content;
    } else {
        $all_data[$target['key']]['text'] = $final_content;
    }

    update_option($target['option'], $all_data);
    return $new_value;
}, 10, 2);



add_action('admin_init', function() {
    get_openai_marked_widget(true);
});




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