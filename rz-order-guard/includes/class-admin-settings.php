<?php
namespace RZOG;

defined('ABSPATH') || exit;

class Admin_Settings {

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu(): void {
        add_options_page('RZ Order Guard', 'RZ Order Guard', 'manage_options', 'rzog-settings', [$this, 'render']);
    }

    public function register_settings(): void {
        register_setting('rzog_settings', 'rzog_license_key');
        register_setting('rzog_settings', 'rzog_fraud_api_key');
        register_setting('rzog_settings', 'rzog_success_threshold');
        register_setting('rzog_settings', 'rzog_block_no_history');
        register_setting('rzog_settings', 'rzog_cache_hours');
        register_setting('rzog_settings', 'rzog_contact_whatsapp');
        register_setting('rzog_settings', 'rzog_contact_phone');
        register_setting('rzog_settings', 'rzog_contact_messenger');

        // Steadfast
        register_setting('rzog_settings', 'rzog_ci_steadfast_enabled');
        register_setting('rzog_settings', 'rzog_ci_steadfast_api_key', ['sanitize_callback' => [$this, 'maybe_encrypt']]);
        register_setting('rzog_settings', 'rzog_ci_steadfast_secret_key', ['sanitize_callback' => [$this, 'maybe_encrypt']]);
        register_setting('rzog_settings', 'rzog_ci_steadfast_webhook_token', ['sanitize_callback' => [$this, 'maybe_encrypt']]);

        // Pathao
        register_setting('rzog_settings', 'rzog_ci_pathao_enabled');
        register_setting('rzog_settings', 'rzog_ci_pathao_environment');
        register_setting('rzog_settings', 'rzog_ci_pathao_client_id', ['sanitize_callback' => [$this, 'maybe_encrypt']]);
        register_setting('rzog_settings', 'rzog_ci_pathao_client_secret', ['sanitize_callback' => [$this, 'maybe_encrypt']]);
        register_setting('rzog_settings', 'rzog_ci_pathao_username', ['sanitize_callback' => [$this, 'maybe_encrypt']]);
        register_setting('rzog_settings', 'rzog_ci_pathao_password', ['sanitize_callback' => [$this, 'maybe_encrypt']]);
        register_setting('rzog_settings', 'rzog_ci_pathao_store_id');
        register_setting('rzog_settings', 'rzog_ci_pathao_webhook_secret', ['sanitize_callback' => [$this, 'maybe_encrypt']]);

        // RedX
        register_setting('rzog_settings', 'rzog_ci_redx_enabled');
        register_setting('rzog_settings', 'rzog_ci_redx_environment');
        register_setting('rzog_settings', 'rzog_ci_redx_token', ['sanitize_callback' => [$this, 'maybe_encrypt']]);
        register_setting('rzog_settings', 'rzog_ci_redx_pickup_store_id');
        register_setting('rzog_settings', 'rzog_ci_redx_webhook_token', ['sanitize_callback' => [$this, 'maybe_encrypt']]);
    }

    /** Encrypt secret fields before they hit the wp_options table. */
    public function maybe_encrypt(string $value): string {
        if ($value === '') {
            return '';
        }
        return Encryption::encrypt($value);
    }

    /** Decrypt for display in the settings form (so re-saving doesn't double-encrypt). */
    private function display_value(string $option_name): string {
        return Encryption::read_option($option_name);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>RZ Order Guard</h1>
            <p>Fraud-check core + courier integration (Pathao/Steadfast/RedX). Order intake, lead capture, and CAPI sender land in later phases.</p>
            <form method="post" action="options.php">
                <?php settings_fields('rzog_settings'); ?>
                <h2>License</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="rzog_license_key">License Key</label></th>
                        <td>
                            <input type="text" id="rzog_license_key" name="rzog_license_key" value="<?php echo esc_attr(get_option('rzog_license_key')); ?>" class="regular-text">
                            <p class="description">
                                Status: <strong><?php echo esc_html(\RZOG\License::status_message()); ?></strong><br>
                                This domain: <code><?php echo esc_html(preg_replace('/^www\./', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')))); ?></code> -- issue a key for exactly this domain with <code>issue-license.php</code>.
                            </p>
                        </td>
                    </tr>
                </table>
                <table class="form-table">
                    <tr>
                        <th><label for="rzog_fraud_api_key">Fraud API Key (iguazudigital)</label></th>
                        <td><input type="text" id="rzog_fraud_api_key" name="rzog_fraud_api_key" value="<?php echo esc_attr(get_option('rzog_fraud_api_key')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="rzog_success_threshold">Success Ratio Threshold (%)</label></th>
                        <td><input type="number" id="rzog_success_threshold" name="rzog_success_threshold" value="<?php echo esc_attr(get_option('rzog_success_threshold', 60)); ?>" min="0" max="100"></td>
                    </tr>
                    <tr>
                        <th><label for="rzog_block_no_history">Block customers with zero history</label></th>
                        <td>
                            <select id="rzog_block_no_history" name="rzog_block_no_history">
                                <option value="yes" <?php selected(get_option('rzog_block_no_history', 'yes'), 'yes'); ?>>Yes</option>
                                <option value="no" <?php selected(get_option('rzog_block_no_history', 'yes'), 'no'); ?>>No</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="rzog_cache_hours">Fraud-check cache (hours)</label></th>
                        <td><input type="number" id="rzog_cache_hours" name="rzog_cache_hours" value="<?php echo esc_attr(get_option('rzog_cache_hours', 24)); ?>" min="1"></td>
                    </tr>
                    <tr>
                        <th><label for="rzog_contact_whatsapp">WhatsApp link (blocked-order contact)</label></th>
                        <td><input type="text" id="rzog_contact_whatsapp" name="rzog_contact_whatsapp" value="<?php echo esc_attr(get_option('rzog_contact_whatsapp')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="rzog_contact_phone">Phone (blocked-order contact)</label></th>
                        <td><input type="text" id="rzog_contact_phone" name="rzog_contact_phone" value="<?php echo esc_attr(get_option('rzog_contact_phone')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="rzog_contact_messenger">Messenger link (blocked-order contact)</label></th>
                        <td><input type="text" id="rzog_contact_messenger" name="rzog_contact_messenger" value="<?php echo esc_attr(get_option('rzog_contact_messenger')); ?>" class="regular-text"></td>
                    </tr>
                </table>

                <h2>Steadfast</h2>
                <table class="form-table">
                    <tr>
                        <th>Enabled</th>
                        <td>
                            <select name="rzog_ci_steadfast_enabled">
                                <option value="no" <?php selected(get_option('rzog_ci_steadfast_enabled', 'no'), 'no'); ?>>No</option>
                                <option value="yes" <?php selected(get_option('rzog_ci_steadfast_enabled', 'no'), 'yes'); ?>>Yes</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th>API Key</th><td><input type="text" name="rzog_ci_steadfast_api_key" value="<?php echo esc_attr($this->display_value('rzog_ci_steadfast_api_key')); ?>" class="regular-text"></td></tr>
                    <tr><th>Secret Key</th><td><input type="text" name="rzog_ci_steadfast_secret_key" value="<?php echo esc_attr($this->display_value('rzog_ci_steadfast_secret_key')); ?>" class="regular-text"></td></tr>
                    <tr>
                        <th>Webhook Auth Token</th>
                        <td>
                            <input type="text" name="rzog_ci_steadfast_webhook_token" value="<?php echo esc_attr($this->display_value('rzog_ci_steadfast_webhook_token')); ?>" class="regular-text">
                            <p class="description">Separate from API Key above -- this is the "Auth Token (Bearer)" field in Steadfast's own dashboard webhook settings. Webhook URL: <code><?php echo esc_url(rest_url('rzog/v1/steadfast-webhook')); ?></code></p>
                        </td>
                    </tr>
                </table>

                <h2>Pathao</h2>
                <table class="form-table">
                    <tr>
                        <th>Enabled</th>
                        <td>
                            <select name="rzog_ci_pathao_enabled">
                                <option value="no" <?php selected(get_option('rzog_ci_pathao_enabled', 'no'), 'no'); ?>>No</option>
                                <option value="yes" <?php selected(get_option('rzog_ci_pathao_enabled', 'no'), 'yes'); ?>>Yes</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Environment</th>
                        <td>
                            <select name="rzog_ci_pathao_environment">
                                <option value="live" <?php selected(get_option('rzog_ci_pathao_environment', 'live'), 'live'); ?>>Live</option>
                                <option value="sandbox" <?php selected(get_option('rzog_ci_pathao_environment', 'live'), 'sandbox'); ?>>Sandbox</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th>Client ID</th><td><input type="text" name="rzog_ci_pathao_client_id" value="<?php echo esc_attr($this->display_value('rzog_ci_pathao_client_id')); ?>" class="regular-text"></td></tr>
                    <tr><th>Client Secret</th><td><input type="text" name="rzog_ci_pathao_client_secret" value="<?php echo esc_attr($this->display_value('rzog_ci_pathao_client_secret')); ?>" class="regular-text"></td></tr>
                    <tr><th>Username</th><td><input type="text" name="rzog_ci_pathao_username" value="<?php echo esc_attr($this->display_value('rzog_ci_pathao_username')); ?>" class="regular-text"></td></tr>
                    <tr><th>Password</th><td><input type="password" name="rzog_ci_pathao_password" value="<?php echo esc_attr($this->display_value('rzog_ci_pathao_password')); ?>" class="regular-text"></td></tr>
                    <tr><th>Store ID</th><td><input type="text" name="rzog_ci_pathao_store_id" value="<?php echo esc_attr(get_option('rzog_ci_pathao_store_id')); ?>" class="regular-text"></td></tr>
                    <tr>
                        <th>Webhook Secret</th>
                        <td>
                            <input type="text" name="rzog_ci_pathao_webhook_secret" value="<?php echo esc_attr($this->display_value('rzog_ci_pathao_webhook_secret')); ?>" class="regular-text">
                            <p class="description">Webhook URL: <code><?php echo esc_url(rest_url('rzog/v1/pathao-webhook')); ?></code></p>
                        </td>
                    </tr>
                </table>

                <h2>RedX</h2>
                <table class="form-table">
                    <tr>
                        <th>Enabled</th>
                        <td>
                            <select name="rzog_ci_redx_enabled">
                                <option value="no" <?php selected(get_option('rzog_ci_redx_enabled', 'no'), 'no'); ?>>No</option>
                                <option value="yes" <?php selected(get_option('rzog_ci_redx_enabled', 'no'), 'yes'); ?>>Yes</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Environment</th>
                        <td>
                            <select name="rzog_ci_redx_environment">
                                <option value="live" <?php selected(get_option('rzog_ci_redx_environment', 'live'), 'live'); ?>>Live</option>
                                <option value="sandbox" <?php selected(get_option('rzog_ci_redx_environment', 'live'), 'sandbox'); ?>>Sandbox</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th>Access Token</th><td><input type="text" name="rzog_ci_redx_token" value="<?php echo esc_attr($this->display_value('rzog_ci_redx_token')); ?>" class="regular-text"></td></tr>
                    <tr><th>Pickup Store ID</th><td><input type="text" name="rzog_ci_redx_pickup_store_id" value="<?php echo esc_attr(get_option('rzog_ci_redx_pickup_store_id')); ?>" class="regular-text"></td></tr>
                    <tr>
                        <th>Webhook Token</th>
                        <td>
                            <input type="text" name="rzog_ci_redx_webhook_token" value="<?php echo esc_attr($this->display_value('rzog_ci_redx_webhook_token')); ?>" class="regular-text">
                            <p class="description">RedX sends this as a query param (<code>?token=</code>) on the webhook URL: <code><?php echo esc_url(rest_url('rzog/v1/redx-webhook')); ?>?token=YOUR_TOKEN</code></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
