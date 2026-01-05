<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PHP 7.4 compatible "starts_with"
 */
function openai_string_starts_with($haystack, $needle) {
    $haystack = (string) $haystack;
    $needle   = (string) $needle;
    if ($needle === '') {
        return true;
    }
    return substr($haystack, 0, strlen($needle)) === $needle;
}

/**
 * Log errors/messages
 */
function openai_auto_post_log($message) {
    $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
    $log_file   = ($upload_dir && !empty($upload_dir['basedir']))
        ? trailingslashit($upload_dir['basedir']) . 'openai-error.log'
        : __DIR__ . '/openai-error.log';

    $current_log = date("Y-m-d H:i:s") . " - " . (string) $message . "\n";
    @file_put_contents($log_file, $current_log, FILE_APPEND);
}

/**
 * Generate text with OpenAI using the Responses API (gpt-5-mini).
 */
function openai_get_generation_gpt5mini($api_key, $prompt, $max_output_tokens = 2048, $proxy = []) {
    $endpoint = 'https://api.openai.com/v1/responses';
    $ch = curl_init($endpoint);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_POST, true);

    // Proxy (optional)
    if (!empty($proxy['url'])) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy['url']);
        if (!empty($proxy['username']) && !empty($proxy['password'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$proxy['username']}:{$proxy['password']}");
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=UTF-8',
        'Accept: application/json',
        'Authorization: Bearer ' . $api_key,
    ]);

    $payload = [
        'model' => 'gpt-5-mini',
        'input' => [
            ['role' => 'system', 'content' => 'You are an expert copywriter. Create clear, compelling, and audience-appropriate content for any topic or purpose.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'max_output_tokens' => (int) $max_output_tokens,
    ];

    $post_fields = json_encode($payload);
    if ($post_fields === false) {
        openai_auto_post_log("JSON Encoding Error: " . json_last_error_msg());
        return "OpenAI API Error: Failed to encode payload as JSON.";
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);

    $response = curl_exec($ch);
    if ($response === false) {
        $curl_error = curl_error($ch);
        openai_auto_post_log("cURL Error: $curl_error");
        curl_close($ch);
        return "OpenAI API Error: $curl_error";
    }

    $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpcode < 200 || $httpcode >= 300) {
        openai_auto_post_log("HTTP $httpcode from OpenAI. Response body: " . $response);

        if (is_array($data) && isset($data['error']['message'])) {
            return "OpenAI API Error ($httpcode): " . $data['error']['message'];
        }
        return "OpenAI API Error: Received HTTP code $httpcode.";
    }

    if (!is_array($data)) {
        openai_auto_post_log("Invalid JSON response: " . $response);
        return "OpenAI API Error: Invalid JSON response.";
    }

    if (isset($data['error']['message'])) {
        return "OpenAI API Error: " . $data['error']['message'];
    }

    // Extract text from Responses output
    $text = '';
    if (!empty($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $out) {
            if (empty($out['content']) || !is_array($out['content'])) {
                continue;
            }
            foreach ($out['content'] as $part) {
                if (!empty($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }
    }

    $text = trim($text);
    if ($text === '') {
        openai_auto_post_log("Empty output text. Full response: " . $response);
        return "OpenAI API Error: Content not found in response.";
    }

    return $text;
}

/**
 * Random image URL from Media Library (skips "favicon").
 * NOTE: Your openai.php calls get_random_media_image_url(), so we provide it here.
 */
function get_random_media_image_url() {
    $query = new WP_Query([
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    if (empty($query->posts)) {
        openai_auto_post_log("No images found in Media Library.");
        return false;
    }

    $image_ids = [];
    foreach ($query->posts as $image_id) {
        $file = get_attached_file($image_id);
        if (!$file) {
            continue;
        }
        $filename = basename($file);

        if (stripos($filename, 'favicon') !== false) {
            continue;
        }

        $image_ids[] = (int) $image_id;
    }

    if (empty($image_ids)) {
        openai_auto_post_log("No usable images found (filtered out).");
        return false;
    }

    $random_image_id = $image_ids[array_rand($image_ids)];
    return wp_get_attachment_url($random_image_id);
}