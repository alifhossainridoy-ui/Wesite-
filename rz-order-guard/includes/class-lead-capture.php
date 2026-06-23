<?php
namespace RZOG;

defined('ABSPATH') || exit;

/**
 * Captures partial order-form data before submit, for the manual-call
 * review queue (BLUEPRINT.md 4.2/4.4). Frontend JS (assets/js/lead-capture.js)
 * debounces input on the order form and posts here via admin-ajax.php;
 * this validates, rate-limits, and upserts into rzog_leads via
 * Leads::upsert() -- the same helper Order_Intake uses for blocked
 * attempts, so a lead row looks the same regardless of which path wrote it.
 */
class Lead_Capture {

    const NONCE_ACTION = 'rzog_lead_capture';
    const CRON_HOOK     = 'rzog_leads_cleanup';

    // Looser than Order_Intake's throttle -- this fires on every debounced
    // keystroke across several fields, not once per order attempt.
    const RATE_LIMIT_MAX    = 20; // requests
    const RATE_LIMIT_WINDOW = 60; // seconds

    public function register(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_rzog_save_lead', [$this, 'handle_save_lead']);
        add_action('wp_ajax_nopriv_rzog_save_lead', [$this, 'handle_save_lead']);
        add_action('init', [$this, 'schedule_cleanup']);
        add_action(self::CRON_HOOK, [$this, 'run_cleanup']);
    }

    /**
     * Only unregisters the cron hook -- must never touch rzog_leads rows
     * (BLUEPRINT.md section 3, data durability).
     */
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function enqueue_assets(): void {
        wp_enqueue_script(
            'rzog-lead-capture',
            RZOG_URL . 'assets/js/lead-capture.js',
            [],
            RZOG_VERSION,
            true
        );

        wp_localize_script('rzog-lead-capture', 'RZOG_LEAD_CAPTURE', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'marker'   => '#dp-order-now',
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
        ]);
    }

    public function handle_save_lead(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if ($this->is_rate_limited()) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $get = function (string $key): string {
            return isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
        };

        $first_name = $get('billing_first_name');
        $last_name  = $get('billing_last_name');
        $phone      = $get('billing_phone');
        $email      = isset($_POST['billing_email']) ? sanitize_email(wp_unslash((string) $_POST['billing_email'])) : '';
        $address_1  = $get('billing_address_1');

        if (!$this->has_meaningful_data($phone, $email, $first_name, $address_1)) {
            wp_send_json_error(['message' => 'Not enough data to capture.']);
        }

        $lead_id = Leads::upsert([
            'session_id' => $get('session_id'),
            'name'       => trim($first_name . ' ' . $last_name),
            'phone'      => $phone,
            'address'    => $address_1,
            'product_id' => absint($get('product_id')),
            'value'      => (float) $get('value'),
            'currency'   => $get('currency'),
            'source_url' => $get('source_url'),
            'fbp'        => $get('fbp'),
            'fbc'        => $get('fbc'),
            'status'     => 'new',
        ]);

        wp_send_json_success(['lead_id' => $lead_id]);
    }

    private function has_meaningful_data(string $phone, string $email, string $name, string $address): bool {
        $digits = preg_replace('/\D/', '', $phone);
        return strlen($digits) >= 6
            || ($email !== '' && strpos($email, '@') !== false)
            || strlen($name) >= 3
            || strlen($address) >= 3;
    }

    /**
     * Independent of the frontend's 900ms debounce -- that's bypassable by
     * calling admin-ajax.php directly, so the real throttle has to live here.
     */
    private function is_rate_limited(): bool {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($ip === '') {
            return false;
        }

        $key   = 'rzog_lead_rl_' . md5($ip);
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT_MAX) {
            return true;
        }

        set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
        return false;
    }

    public function schedule_cleanup(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Daily: mark stale 'new' leads as 'abandoned'. Deletion of old
     * abandoned/rejected rows only runs if the business owner has set a
     * retention period above 0 -- defaults to off per the data-durability
     * principle in BLUEPRINT.md section 3.
     */
    public function run_cleanup(): void {
        global $wpdb;
        $table = DB::table('leads');

        $abandoned_after_minutes = max(1, (int) get_option('rzog_lead_abandoned_minutes', 30));
        $stale_cutoff = gmdate('Y-m-d H:i:s', time() - ($abandoned_after_minutes * 60));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'abandoned', updated_at = %s WHERE status = 'new' AND created_at < %s",
            current_time('mysql', true),
            $stale_cutoff
        ));

        $retention_days = (int) get_option('rzog_lead_retention_days', 0);
        if ($retention_days <= 0) {
            return;
        }

        $delete_cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN ('abandoned', 'rejected') AND updated_at < %s",
            $delete_cutoff
        ));
    }
}
