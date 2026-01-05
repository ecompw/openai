<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom cron schedule interval (only defined here to avoid duplicates)
 */
add_filter('cron_schedules', 'openai_custom_cron_schedules');
function openai_custom_cron_schedules($schedules) {
    $schedules['every_five_days'] = [
        'interval' => 5 * DAY_IN_SECONDS,
        'display'  => __('Every 5 Days', 'openai-auto-post'),
    ];
    return $schedules;
}

/**
 * Callback for the main plugin page
 */
function openai_auto_post_callback() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['generate_post'])) {
        check_admin_referer('openai_generate_post_nonce');

        $result = openai_generate_post();
        echo $result; // safe: plugin-generated HTML notices
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

/**
 * Callback for the settings page
 */
function openai_auto_post_settings_callback() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['save_openai_settings'])) {
        check_admin_referer('openai_save_settings_nonce');

        // Sanitize (prompt stored raw to avoid stripping <title> etc.)
        $api_key        = sanitize_text_field(wp_unslash($_POST['openai_api_key'] ?? ''));
        $prompt         = (string) wp_unslash($_POST['openai_post_prompt'] ?? '');
        $interval       = sanitize_key(wp_unslash($_POST['openai_auto_interval'] ?? 'every_five_days'));
        $proxy          = sanitize_text_field(wp_unslash($_POST['openai_proxy'] ?? ''));
        $proxy_username = sanitize_text_field(wp_unslash($_POST['openai_proxy_username'] ?? ''));
        $proxy_password = sanitize_text_field(wp_unslash($_POST['openai_proxy_password'] ?? ''));

        // Fetch or create the settings post
        $settings_posts = get_posts([
            'post_type'      => 'openai_settings',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        if (empty($settings_posts)) {
            $post_id = wp_insert_post([
                'post_title'  => 'OpenAI Settings',
                'post_status' => 'publish',
                'post_type'   => 'openai_settings',
            ], true);

            if (is_wp_error($post_id)) {
                echo '<div class="error"><p>Failed to create settings post: ' . esc_html($post_id->get_error_message()) . '</p></div>';
                return;
            }
        } else {
            $post_id = (int) $settings_posts[0]->ID;
        }

        // Update post meta
        update_post_meta($post_id, 'openai_api_key', $api_key);
        update_post_meta($post_id, 'openai_post_prompt', $prompt);
        update_post_meta($post_id, 'openai_auto_interval', $interval);
        update_post_meta($post_id, 'openai_proxy', $proxy);
        update_post_meta($post_id, 'openai_proxy_username', $proxy_username);
        update_post_meta($post_id, 'openai_proxy_password', $proxy_password);

        echo '<div class="updated"><p>Settings saved!</p></div>';

        // Validate interval
        $valid_intervals = ['hourly', 'twicedaily', 'daily', 'every_five_days'];
        if (!in_array($interval, $valid_intervals, true)) {
            $interval = 'daily';
        }

        // Reschedule future posts
        if (wp_next_scheduled('openai_scheduled_post_event')) {
            wp_clear_scheduled_hook('openai_scheduled_post_event');
        }
        wp_schedule_event(time() + 60, $interval, 'openai_scheduled_post_event');
    }

    // Retrieve saved settings
    $settings_posts = get_posts([
        'post_type'      => 'openai_settings',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);

    $openai_api_key        = '';
    $openai_post_prompt    = '';
    $openai_auto_interval  = 'every_five_days';
    $openai_proxy          = '';
    $openai_proxy_username = '';
    $openai_proxy_password = '';

    if (!empty($settings_posts)) {
        $sid = (int) $settings_posts[0]->ID;
        $openai_api_key        = get_post_meta($sid, 'openai_api_key', true);
        $openai_post_prompt    = get_post_meta($sid, 'openai_post_prompt', true);
        $openai_auto_interval  = get_post_meta($sid, 'openai_auto_interval', true);
        $openai_proxy          = get_post_meta($sid, 'openai_proxy', true);
        $openai_proxy_username = get_post_meta($sid, 'openai_proxy_username', true);
        $openai_proxy_password = get_post_meta($sid, 'openai_proxy_password', true);
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
                        <input type="password" name="openai_api_key" value="<?php echo esc_attr($openai_api_key); ?>" size="50" autocomplete="off" />
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
                        <input type="password" name="openai_proxy_password" value="<?php echo esc_attr($openai_proxy_password); ?>" size="50" autocomplete="off" />
                    </td>
                </tr>
            </table>
            <input type="hidden" name="save_openai_settings" value="1">
            <button type="submit" class="button button-primary">Save Settings</button>
        </form>
    </div>
    <?php
}