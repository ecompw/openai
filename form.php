<?php
if (!defined('ABSPATH')) exit;

add_filter('cron_schedules', function($s) {
    $s['every_five_days'] = ['interval' => 5 * DAY_IN_SECONDS, 'display' => __('Every 5 Days', 'openai-auto-post')];
    return $s;
});

function openai_auto_post_callback() {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['generate_post'])) {
        check_admin_referer('openai_generate_post_nonce');
        echo openai_generate_post();
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

function openai_auto_post_settings_callback() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['save_openai_settings'])) {
        check_admin_referer('openai_save_settings_nonce');

        update_option('openai_api_key', sanitize_text_field($_POST['openai_api_key']));
        update_option('openai_post_prompt', wp_unslash($_POST['openai_post_prompt']));
        update_option('openai_auto_interval', sanitize_key($_POST['openai_auto_interval']));
        update_option('openai_proxy', sanitize_text_field($_POST['openai_proxy']));
        update_option('openai_proxy_username', sanitize_text_field($_POST['openai_proxy_username']));
        update_option('openai_proxy_password', sanitize_text_field($_POST['openai_proxy_password']));

        $interval = in_array($_POST['openai_auto_interval'], ['hourly', 'twicedaily', 'daily', 'every_five_days']) ? $_POST['openai_auto_interval'] : 'daily';
        wp_clear_scheduled_hook('openai_scheduled_post_event');
        wp_schedule_event(time() + 60, $interval, 'openai_scheduled_post_event');
        echo '<div class="updated"><p>Settings saved!</p></div>';
    }

    $openai_api_key        = get_option('openai_api_key');
    $openai_post_prompt    = get_option('openai_post_prompt');
    $openai_auto_interval  = get_option('openai_auto_interval', 'every_five_days');
    $openai_proxy          = get_option('openai_proxy');
    $openai_proxy_username = get_option('openai_proxy_username');
    $openai_proxy_password = get_option('openai_proxy_password');
    ?>
    <div class="wrap">
        <h2>Settings</h2>
        <form method="post">
            <?php wp_nonce_field('openai_save_settings_nonce'); ?>
            <table class="form-table">
                <tr><th>API Key</th><td><input type="password" name="openai_api_key" value="<?php echo esc_attr($openai_api_key); ?>" size="50"></td></tr>
                <tr><th>Prompt</th><td><textarea name="openai_post_prompt" rows="5" cols="50"><?php echo esc_textarea($openai_post_prompt); ?></textarea></td></tr>
                <tr><th>Interval</th><td>
                    <select name="openai_auto_interval">
                        <option value="hourly" <?php selected($openai_auto_interval, 'hourly'); ?>>Hourly</option>
                        <option value="twicedaily" <?php selected($openai_auto_interval, 'twicedaily'); ?>>Twice Daily</option>
                        <option value="daily" <?php selected($openai_auto_interval, 'daily'); ?>>Daily</option>
                        <option value="every_five_days" <?php selected($openai_auto_interval, 'every_five_days'); ?>>Every 5 Days</option>
                    </select>
                </td></tr>
                <tr><th>Proxy URL</th><td><input type="text" name="openai_proxy" value="<?php echo esc_attr($openai_proxy); ?>" size="50"></td></tr>
                <tr><th>Proxy User</th><td><input type="text" name="openai_proxy_username" value="<?php echo esc_attr($openai_proxy_username); ?>" size="50"></td></tr>
                <tr><th>Proxy Pass</th><td><input type="password" name="openai_proxy_password" value="<?php echo esc_attr($openai_proxy_password); ?>" size="50"></td></tr>
            </table>
            <input type="hidden" name="save_openai_settings" value="1">
            <button type="submit" class="button button-primary">Save Settings</button>
        </form>
    </div>
    <?php
}