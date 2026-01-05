<?php

// Function to log errors or messages for debugging
function openai_auto_post_log($message) {
    $log_file = plugin_dir_path(__FILE__) . 'openai-error.log';
    $current_log = date("Y-m-d H:i:s") . " - " . $message . "\n";
    file_put_contents($log_file, $current_log, FILE_APPEND);
}

// Function to get content generation from OpenAI (GPT-4.1)
function openai_get_generation($api_key, $prompt, $max_tokens = 2048, $proxy = []) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_VERBOSE, true);

    // Setup proxy if required
    if (!empty($proxy['url'])) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy['url']);
        if (!empty($proxy['username']) && !empty($proxy['password'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$proxy['username']}:{$proxy['password']}");
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
        }
    }

    // Set headers for the OpenAI API
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=UTF-8',
        'Authorization: Bearer ' . $api_key,
    ]);

    // Build request payload with GPT-5 model
    $post_fields = json_encode([
        'model' => 'gpt-5-mini-2025-08-07',
    'messages' => [
        ['role' => 'system', 'content' => 'You are an expert copywriter. Create clear, compelling, and audience-appropriate content for any topic or purpose.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => $max_tokens
    ]);

    if ($post_fields === false) {
        openai_auto_post_log("JSON Encoding Error: " . json_last_error_msg());
        return "Failed to encode payload as JSON.";
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);

    // Capture verbose output
    $verbose_log = fopen('php://temp', 'rw+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose_log);

    // Execute API request
    $response = curl_exec($ch);

    if ($response === false) {
        $curl_error = curl_error($ch);
        openai_auto_post_log("cURL Error: $curl_error");

        rewind($verbose_log);
        $verbose_output = stream_get_contents($verbose_log);
        openai_auto_post_log("cURL Verbose Output: " . $verbose_output);

        fclose($verbose_log);
        curl_close($ch);

        return "OpenAI API Error: $curl_error.";
    }

    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response_data = json_decode($response, true);
    curl_close($ch);

    if ($httpcode != 200) {
        return "OpenAI API Error: Received HTTP code $httpcode.";
    }

    if (isset($response_data['error'])) {
        return "API Error: {$response_data['error']['message']}";
    }

    if (!empty($response_data['choices'][0]['message']['content'])) {
        return $response_data['choices'][0]['message']['content'];
    } else {
        openai_auto_post_log("Empty response content received.");
        return "OpenAI API Error: Content not found in response.";
    }
}

// Function to get a random image URL from WordPress Media Library
function get_random_media_image_url() {
    $query = new WP_Query([
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit', // Ensure visibility
        'posts_per_page' => -1 // Fetch all image attachments
    ]);

    if ($query->have_posts()) {
        $image_ids = [];
        while ($query->have_posts()) {
            $query->the_post();
            $image_id = get_the_ID();
            $filename = basename(get_attached_file($image_id));
            
            // Skip the image if filename contains "favicon"
            if (stripos($filename, 'favicon') !== false) {
                continue;
            }

            $image_ids[] = $image_id; // Collect IDs of images
        }
        wp_reset_postdata();

        if (!empty($image_ids)) {
            $random_image_id = $image_ids[array_rand($image_ids)];
            $image_url = wp_get_attachment_url($random_image_id);
            return $image_url; // Return the URL of the selected image
        }
    }
    openai_auto_post_log("No images found or no media posts to process.");
    return false; // Return false if no images found
}

?>
