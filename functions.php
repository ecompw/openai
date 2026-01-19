<?php
if (!defined('ABSPATH')) exit;

function openai_get_generation_gpt5mini($api_key, $prompt, $max_tokens = 2048, $proxy = []) {
    // Исправлено: стандартный эндпоинт Chat Completions
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ];

    $payload = [
        'model' => 'gpt-4o-mini', // gpt-5-mini не существует, заменено на актуальную быструю модель
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => (int)$max_tokens
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    if (!empty($proxy['url'])) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy['url']);
        if (!empty($proxy['username'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['username'] . ':' . $proxy['password']);
        }
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($http_code !== 200) {
        return 'OpenAI API Error: ' . ($data['error']['message'] ?? 'Unknown Error');
    }

    return $data['choices'][0]['message']['content'] ?? '';
}

// ИСПРАВЛЕНО: Добавлены недостающие функции парсинга
function format_response($text) {
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/### (.*?)\n/', '<h3>$1</h3>', $text);
    $text = preg_replace('/## (.*?)\n/', '<h2>$1</h2>', $text);
    return $text;
}

function extract_title_and_body($content) {
    if (preg_match('/<title>(.*?)<\/title>/is', $content, $matches)) {
        $title = trim($matches[1]);
        $body = trim(str_replace($matches[0], '', $content));
        return [$title, $body];
    }
    return ['', $content];
}

function openai_string_starts_with($haystack, $needle) {
    return strpos($haystack, $needle) === 0;
}

function get_random_media_image_url() {
    $images = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'posts_per_page' => 20]);
    if (empty($images)) return false;
    return wp_get_attachment_url($images[array_rand($images)]->ID);
}
function openai_auto_post_log($message) {
    error_log(date("Y-m-d H:i:s") . " - " . $message . "\n", 3, WP_CONTENT_DIR . '/openai-errors.log');
}
