<?php
if (!defined('ABSPATH')) exit;

function openai_string_starts_with($haystack, $needle) {
    $haystack = (string) $haystack; $needle = (string) $needle;
    return $needle === '' || substr($haystack, 0, strlen($needle)) === $needle;
}

function openai_auto_post_log($message) {
    $upload_dir = wp_upload_dir();
    $log_file = trailingslashit($upload_dir['basedir']) . 'openai-error.log';
    @file_put_contents($log_file, date("Y-m-d H:i:s") . " - " . $message . "\n", FILE_APPEND);
}

function openai_get_generation_gpt5mini($api_key, $prompt, $max_output_tokens = 2048, $proxy = []) {
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_POST, true);

    if (!empty($proxy['url'])) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy['url']);
        if (!empty($proxy['username'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$proxy['username']}:{$proxy['password']}");
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=UTF-8',
        'Authorization: Bearer ' . $api_key,
    ]);

    $payload = [
        'model' => 'gpt-5-mini',
        'input' => [['role' => 'system', 'content' => 'You are an expert copywriter.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'max_output_tokens' => (int) $max_output_tokens,
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpcode < 200 || $httpcode >= 300) return "OpenAI API Error ($httpcode)";

    $text = '';
    if (!empty($data['output'])) {
        foreach ($data['output'] as $out) {
            foreach ($out['content'] as $part) {
                if (!empty($part['text'])) $text .= $part['text'];
            }
        }
    }
    return trim($text);
}

function get_random_media_image_url() {
    $query = new WP_Query(['post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids']);
    if (empty($query->posts)) return false;
    $image_ids = [];
    foreach ($query->posts as $id) {
        if (stripos(basename(get_attached_file($id)), 'favicon') === false) $image_ids[] = $id;
    }
    return !empty($image_ids) ? wp_get_attachment_url($image_ids[array_rand($image_ids)]) : false;
}