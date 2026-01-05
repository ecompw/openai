<?php

// Function to log errors or messages for debugging
function openai_auto_post_log($message) {
    // Prefer uploads dir to avoid permission issues on many hosts
    $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
    $log_file   = ($upload_dir && !empty($upload_dir['basedir']))
        ? trailingslashit($upload_dir['basedir']) . 'openai-error.log'
        : __DIR__ . '/openai-error.log';

    $current_log = date("Y-m-d H:i:s") . " - " . $message . "\n";
    file_put_contents($log_file, $current_log, FILE_APPEND);
}

/**
 * Generate text with OpenAI using the Responses API (works with models like gpt-5-mini).
 *
 * @param string $api_key
 * @param string $prompt
 * @param int    $max_output_tokens
 * @param array  $proxy ['url' => '', 'username' => '', 'password' => '']
 * @return string
 */
function openai_get_generation_gpt5mini($api_key, $prompt, $max_output_tokens = 2048, $proxy = []) {
    $endpoint = 'https://api.openai.com/v1/responses';
    $ch = curl_init($endpoint);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_POST, true);

    // Setup proxy if required
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

    // Responses API payload:
    // - model: gpt-5-mini
    // - input: role-based array (system + user), similar concept to Chat Completions
    // - max_output_tokens: equivalent of max_tokens in Responses API
    $payload = [
        'model' => 'gpt-5-mini',
        'input' => [
            ['role' => 'system', 'content' => 'You are an expert copywriter. Create clear, compelling, and audience-appropriate content for any topic or purpose.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'max_output_tokens' => (int) $max_output_tokens,
    ];

    $post_fields = json_encode($payload);
    if ($post_fields === false) {
        openai_auto_post_log("JSON Encoding Error: " . json_last_error_msg());
        return "Failed to encode payload as JSON.";
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);

    $response = curl_exec($ch);
    if ($response === false) {
        $curl_error = curl_error($ch);
        openai_auto_post_log("cURL Error: $curl_error");
        curl_close($ch);
        return "OpenAI API Error: $curl_error.";
    }

    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    // Log body when not successful (this is crucial for debugging)
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

    // Responses API error format (just in case it returns 200 with an embedded error)
    if (isset($data['error']['message'])) {
        return "API Error: {$data['error']['message']}";
    }

    /**
     * Typical Responses API output shape resembles:
     * $data['output'][0]['content'][0]['text']
     * but we parse defensively.
     */
    $text = '';

    if (!empty($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $out) {
            if (empty($out['content']) || !is_array($out['content'])) {
                continue;
            }
            foreach ($out['content'] as $content_part) {
                // Common key is 'text'
                if (!empty($content_part['text']) && is_string($content_part['text'])) {
                    $text .= $content_part['text'];
                }
                // Some variants may use 'type' => 'output_text' with 'text'
            }
        }
    }

    $text = trim($text);

    if ($text !== '') {
        return $text;
    }

    openai_auto_post_log("Empty output text. Full response: " . $response);
    return "OpenAI API Error: Content not found in response.";
}
?>