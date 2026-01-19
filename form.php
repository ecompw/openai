<?php
if (!defined('ABSPATH')) exit;

add_filter('cron_schedules', function ($schedules) {
    $schedules['every_five_days'] = ['interval' => 432000, 'display' => 'Every 5 Days'];
    return $schedules;
});

function openai_auto_post_callback() {
    if (isset($_POST['generate_post'])) {
        check_admin_referer('openai_generate_action', 'openai_generate_nonce');
        echo openai_generate_post();
    }
    ?>
    <div class="wrap">
        <h1>OpenAI Auto Post</h1>
        <form method="post">
            <?php wp_nonce_field('openai_generate_action', 'openai_generate_nonce'); ?>
            <input type="hidden" name="generate_post" value="1">
            <?php submit_button('Generate and Publish Post Now'); ?>
        </form>
    </div>
    <?php
}

function openai_auto_post_settings_callback() {
    if (isset($_POST['save_settings'])) {
        check_admin_referer('openai_settings_action', 'openai_settings_nonce');

        // ИСПРАВЛЕНО: Сохранение в options для синхронизации с Django
        update_option('openai_api_key', sanitize_text_field($_POST['openai_api_key']));
        update_option('openai_post_prompt', wp_unslash($_POST['openai_post_prompt']));
        update_option('openai_auto_interval', sanitize_text_field($_POST['openai_auto_interval']));
        update_option('openai_proxy', sanitize_text_field($_POST['openai_proxy']));
        update_option('openai_proxy_username', sanitize_text_field($_POST['openai_proxy_username']));
        update_option('openai_proxy_password', sanitize_text_field($_POST['openai_proxy_password']));

        wp_clear_scheduled_hook('openai_scheduled_post_event');
        wp_schedule_event(time(), get_option('openai_auto_interval'), 'openai_scheduled_post_event');

        echo '<div class="updated"><p>Settings saved!</p></div>';
    }

    $api_key = get_option('openai_api_key');
    $prompt  = get_option('openai_post_prompt');
    $interval = get_option('openai_auto_interval', 'every_five_days');
    ?>
    <div class="wrap">
        <h1>Settings</h1>
        <form method="post">
            <?php wp_nonce_field('openai_settings_action', 'openai_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>API Key</th>
                    <td><input type="password" name="openai_api_key" value="<?php echo esc_attr($api_key); ?>" size="50"></td>
                </tr>
                <tr>
                    <th>Prompt</th>
                    <td><textarea name="openai_post_prompt" rows="10" cols="50"><?php echo esc_textarea($prompt); ?></textarea></td>
                </tr>
                <tr>
                    <th>Interval</th>
                    <td>
                        <select name="openai_auto_interval">
                            <option value="daily" <?php selected($interval, 'daily'); ?>>Daily</option>
                            <option value="every_five_days" <?php selected($interval, 'every_five_days'); ?>>Every 5 Days</option>
                        </select>
                    </td>
                </tr>
            </table>
            <input type="hidden" name="save_settings" value="1">
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}