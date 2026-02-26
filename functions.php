<?php
if (!defined('ABSPATH')) exit;

/**
 * Простая проверка, начинается ли строка с подстроки
 */
function openai_string_starts_with($haystack, $needle) {
    $haystack = (string) $haystack;
    $needle = (string) $needle;
    return $needle === '' || substr($haystack, 0, strlen($needle)) === $needle;
}

/**
 * Логирование в uploads/openai-error.log
 */
function openai_auto_post_log($message) {
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']);
    if (!wp_mkdir_p($log_dir)) {
        // Если не удалось создать папку, попробуем записать в системный лог
        error_log('OpenAI Auto Post: cannot create upload dir: ' . $log_dir);
    }
    $log_file = $log_dir . 'openai-error.log';
    // Используем @ чтобы не вызывать предупреждений у конечных пользователей,
    // но при этом логируем в системный лог при неудаче.
    if (@file_put_contents($log_file, date("Y-m-d H:i:s") . " - " . $message . "\n", FILE_APPEND) === false) {
        error_log('OpenAI Auto Post: failed to write to log file: ' . $log_file . ' | msg: ' . $message);
    }
}

/**
 * Выполняет запрос к OpenAI (Responses API) и возвращает строку с текстом результата
 * При ошибке возвращает строку "OpenAI API Error (код): сообщение" или пустую строку.
 */
function openai_get_generation_gpt5mini($api_key, $prompt, $max_output_tokens = 10240, $proxy = []) {
    // Защита от отсутствия ключа
    if (empty($api_key)) {
        openai_auto_post_log('openai_get_generation_gpt5mini called without api_key');
        return 'OpenAI API Error (no_api_key)';
    }

    $payload = [
        'model' => 'gpt-5-mini',
        'input' => [
            ['role' => 'system', 'content' => 'You are an expert copywriter.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'max_output_tokens' => (int) $max_output_tokens,
    ];

    $url = 'https://api.openai.com/v1/responses';
    $headers = [
        'Content-Type' => 'application/json; charset=UTF-8',
        'Authorization' => 'Bearer ' . $api_key,
    ];

    $response_body = null;
    $httpcode = 0;

    // Попытка через cURL, если расширение доступно
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            openai_auto_post_log('cURL init failed');
            return 'OpenAI API Error (curl_init_failed)';
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, true);

        if (!empty($proxy['url'])) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy['url']);
            if (!empty($proxy['username'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$proxy['username']}:{$proxy['password']}");
            }
        }

        $http_headers = [];
        foreach ($headers as $k => $v) {
            $http_headers[] = $k . ': ' . $v;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $http_headers);

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);
        if ($response === false) {
            $curl_err = curl_error($ch);
            openai_auto_post_log('cURL exec failed: ' . $curl_err);
            curl_close($ch);
            return 'OpenAI API Error (curl_error): ' . $curl_err;
        }

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
        curl_close($ch);
        $response_body = $response;
    } else {
        // Fallback: используем WP HTTP API (рекомендуемый способ в плагинах WP)
        $args = [
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
            'timeout' => 120,
        ];

        // Поддержка простого proxy для wp_remote_post не реализована здесь; можно расширить при необходимости
        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) {
            openai_auto_post_log('wp_remote_post error: ' . $resp->get_error_message());
            return 'OpenAI API Error (wp_remote_post): ' . $resp->get_error_message();
        }
        $httpcode = (int) wp_remote_retrieve_response_code($resp);
        $response_body = wp_remote_retrieve_body($resp);
    }

    // Парсим JSON
    if (empty($response_body)) {
        openai_auto_post_log('OpenAI empty response, http code: ' . $httpcode);
        return 'OpenAI API Error (empty_response)';
    }

    $data = json_decode($response_body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        openai_auto_post_log('OpenAI JSON decode error: ' . json_last_error_msg() . ' | raw: ' . substr($response_body, 0, 1000));
        return 'OpenAI API Error (invalid_json)';
    }

    if ($httpcode < 200 || $httpcode >= 300) {
        // Попытка извлечь сообщение ошибки из тела
        $err_msg = '';
        if (isset($data['error'])) {
            $err_msg = is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
        } elseif (isset($data['message'])) {
            $err_msg = $data['message'];
        }
        openai_auto_post_log('OpenAI returned HTTP ' . $httpcode . ' : ' . $err_msg);
        return 'OpenAI API Error (' . $httpcode . '): ' . ($err_msg ?: 'no message');
    }

    // Извлекаем текст из возможных структур ответа
    $text = '';
    if (!empty($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $out) {
            if (!is_array($out)) continue;
            if (isset($out['content']) && is_array($out['content'])) {
                foreach ($out['content'] as $part) {
                    if (!is_array($part)) continue;
                    if (!empty($part['text'])) {
                        $text .= $part['text'];
                    } elseif (!empty($part['type']) && $part['type'] === 'message' && !empty($part['message'])) {
                        // вариация — если структура другая
                        $text .= (string)$part['message'];
                    }
                }
            } elseif (isset($out['text']) && is_string($out['text'])) {
                $text .= $out['text'];
            }
        }
    } elseif (!empty($data['choices']) && is_array($data['choices'])) {
        // на случай совместимости с другими эндпоинтами
        foreach ($data['choices'] as $choice) {
            if (isset($choice['text'])) $text .= $choice['text'];
            elseif (isset($choice['message']['content'])) $text .= $choice['message']['content'];
        }
    } elseif (isset($data['text']) && is_string($data['text'])) {
        $text .= $data['text'];
    }

    return trim((string)$text);
}

/**
 * Возвращает URL случайной изображения-attachment, исключая favicon'ы.
 * Возвращает false при отсутствии.
 */
function get_random_media_image_url() {
    // Получаем все ID изображений — на больших сайтах это может быть тяжело.
    // Можно ограничить выборку, если нужно.
    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'numberposts'    => -1,
        'fields'         => 'ids',
    ];
    $attachments = get_posts($args);
    if (empty($attachments) || !is_array($attachments)) {
        return false;
    }

    $image_ids = [];
    foreach ($attachments as $id) {
        $file_path = get_attached_file($id);
        if (empty($file_path)) continue;
        $base = basename($file_path);
        if (stripos($base, 'favicon') === false) {
            $image_ids[] = $id;
        }
    }

    if (empty($image_ids)) return false;

    $chosen_id = $image_ids[array_rand($image_ids)];
    $url = wp_get_attachment_url($chosen_id);
    return $url ?: false;
}


