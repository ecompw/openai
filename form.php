<?php
// Add a custom cron schedule interval
add_filter('cron_schedules', 'openai_custom_cron_schedules');
function openai_custom_cron_schedules($schedules) {
    $schedules['every_five_days'] = array(
        'interval' => 5 * 24 * 60 * 60, // 5 days in seconds
        'display' => __('Every 5 Days'),
    );
    return $schedules;
}

// Register the scheduled event
add_action('openai_scheduled_post_event', 'openai_generate_post');

// Callback for the main plugin page
function openai_auto_post_callback() {
    if (isset($_POST['generate_post'])) {
        check_admin_referer('openai_generate_post_nonce'); // Check nonce for security
        $result = openai_generate_post();
        echo $result;
    }
    ?>
    <div class="wrap">
        <h2>OpenAI Auto Post</h2>
        <form method="post">
            <?php wp_nonce_field('openai_generate_post_nonce'); ?>
            <input type="hidden" name="generate_post" value="1">
            <button type="submit" class="button button-primary">Generate and Publish Post</button>
        </form>
    </div>
    <?php
}

// Callback for the settings page
function openai_auto_post_settings_callback() {
    if (isset($_POST['save_openai_settings'])) {
        check_admin_referer('openai_save_settings_nonce'); // Check nonce for security

        // Retrieve and sanitize inputs
        $api_key = sanitize_text_field($_POST['openai_api_key']);
        $prompt = sanitize_textarea_field($_POST['openai_post_prompt']);
        $interval = sanitize_text_field($_POST['openai_auto_interval']);
        $proxy = sanitize_text_field($_POST['openai_proxy']);
        $proxy_username = sanitize_text_field($_POST['openai_proxy_username']);
        $proxy_password = sanitize_text_field($_POST['openai_proxy_password']);

        // Fetch or create the settings post
        $settings_posts = get_posts([
            'post_type' => 'openai_settings',
            'post_status' => 'publish',
            'numberposts' => 1
        ]);

        if (empty($settings_posts)) {
            $post_id = wp_insert_post([
                'post_title' => 'OpenAI Settings',
                'post_status' => 'publish',
                'post_type' => 'openai_settings',
            ]);
        } else {
            $post_id = $settings_posts[0]->ID;
        }

        // Update post meta
        update_post_meta($post_id, 'openai_api_key', $api_key);
        update_post_meta($post_id, 'openai_post_prompt', $prompt);
        update_post_meta($post_id, 'openai_auto_interval', $interval);
        update_post_meta($post_id, 'openai_proxy', $proxy);
        update_post_meta($post_id, 'openai_proxy_username', $proxy_username);
        update_post_meta($post_id, 'openai_proxy_password', $proxy_password);

        echo '<div class="updated"><p>Settings saved! Generating first post...</p></div>';
        $result = openai_generate_post();
        echo $result;

        // Define valid intervals and fallback
        $valid_intervals = array('hourly', 'twicedaily', 'daily', 'every_five_days');
        if (!in_array($interval, $valid_intervals)) {
            $interval = 'daily'; // Default fallback
        }

        // Schedule future posts
        if (wp_next_scheduled('openai_scheduled_post_event')) {
            wp_clear_scheduled_hook('openai_scheduled_post_event');
        }
        wp_schedule_event(time() + 60, $interval, 'openai_scheduled_post_event');
    }

    // Retrieve saved settings
    $settings_posts = get_posts([
        'post_type' => 'openai_settings',
        'post_status' => 'publish',
        'numberposts' => 1
    ]);

    $openai_api_key = '';
    $openai_post_prompt = '';
    $openai_auto_interval = 'every_five_days';
    $openai_proxy = '';
    $openai_proxy_username = '';
    $openai_proxy_password = '';

    if (!empty($settings_posts)) {
        $openai_api_key = get_post_meta($settings_posts[0]->ID, 'openai_api_key', true);
        $openai_post_prompt = get_post_meta($settings_posts[0]->ID, 'openai_post_prompt', true);
        $openai_auto_interval = get_post_meta($settings_posts[0]->ID, 'openai_auto_interval', true);
        $openai_proxy = get_post_meta($settings_posts[0]->ID, 'openai_proxy', true);
        $openai_proxy_username = get_post_meta($settings_posts[0]->ID, 'openai_proxy_username', true);
        $openai_proxy_password = get_post_meta($settings_posts[0]->ID, 'openai_proxy_password', true);
    }
    ?>
    <div class="wrap">
        <h2>OpenAI Auto Post Settings</h2>
        <form method="post">
            <?php wp_nonce_field('openai_save_settings_nonce'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">OpenAI API Key</th>
                    <td>
                        <input type="text" name="openai_api_key" value="<?php echo esc_attr($openai_api_key); ?>" size="50" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Post Prompt</th>
                    <td>
                        <textarea name="openai_post_prompt" rows="5" cols="50"><?php echo esc_textarea($openai_post_prompt); ?></textarea>
                        <p class="description">Enter the prompt text for generating posts.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Scheduling Interval</th>
                    <td>
                        <select name="openai_auto_interval">
                            <option value="hourly" <?php selected($openai_auto_interval, 'hourly'); ?>>Hourly</option>
                            <option value="twicedaily" <?php selected($openai_auto_interval, 'twicedaily'); ?>>Twice Daily</option>
                            <option value="daily" <?php selected($openai_auto_interval, 'daily'); ?>>Daily</option>
                            <option value="every_five_days" <?php selected($openai_auto_interval, 'every_five_days'); ?>>Every 5 Days</option>
                        </select>
                        <p class="description">Choose how often to automatically generate posts.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Proxy (optional)</th>
                    <td>
                        <input type="text" name="openai_proxy" value="<?php echo esc_attr($openai_proxy); ?>" size="50" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Proxy Username</th>
                    <td>
                        <input type="text" name="openai_proxy_username" value="<?php echo esc_attr($openai_proxy_username); ?>" size="50" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Proxy Password</th>
                    <td>
                        <input type="text" name="openai_proxy_password" value="<?php echo esc_attr($openai_proxy_password); ?>" size="50" />
                    </td>
                </tr>
            </table>
            <input type="hidden" name="save_openai_settings" value="1">
            <button type="submit" class="button button-primary">Save Settings and Generate First Post</button>
        </form>
    </div>
    <?php
}