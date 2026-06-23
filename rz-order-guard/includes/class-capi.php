<?php
namespace RZOG;

defined('ABSPATH') || exit;

/**
 * Sends server-side Purchase events to Meta's Conversions API. Order meta is
 * captured once, synchronously, at order-creation time via
 * woocommerce_checkout_create_order (already fired manually by
 * Order_Intake::create_order() per CLAUDE.md hard rule #4 -- classic
 * checkout fires it too). That is the only point where $_SERVER/$_COOKIE
 * reliably describe the customer who placed the order, not whatever later
 * request (e.g. a courier webhook) transitions its status. The actual send
 * is attempted on woocommerce_order_status_processing,
 * woocommerce_order_status_completed, and woocommerce_thankyou for
 * resilience, deduped by a per-order "already sent" meta flag.
 */
class CAPI {

    const API_VERSION = 'v19.0';

    const META_FBP         = '_rzog_fbp';
    const META_FBC          = '_rzog_fbc';
    const META_SOURCE_URL   = '_rzog_capi_source_url';
    const META_EVENT_ID     = '_rzog_capi_event_id';
    const META_CLIENT_IP    = '_rzog_capi_client_ip';
    const META_CLIENT_UA    = '_rzog_capi_client_ua';
    const META_SENT         = '_rzog_capi_sent';
    const META_LAST_ERROR   = '_rzog_capi_last_error';

    public function register(): void {
        add_action('woocommerce_checkout_create_order', [$this, 'capture_order_meta'], 20, 2);

        add_action('woocommerce_order_status_processing', [$this, 'maybe_send']);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_send']);
        add_action('woocommerce_thankyou', [$this, 'maybe_send']);
        add_action('woocommerce_thankyou', [$this, 'output_browser_pixel_event_id']);

        add_action('admin_notices', [$this, 'render_failure_notice']);
    }

    /**
     * $data is whatever the calling code passed -- Order_Intake passes its
     * sanitized $input array (has fbp/fbc/source_url keys); classic
     * WC_Checkout::create_order() passes its own posted-data array (none of
     * those keys exist there), so cookies are the only source in that case.
     */
    public function capture_order_meta(\WC_Order $order, $data): void {
        $data = is_array($data) ? $data : [];

        $fbp = $this->cookie_or_input('_fbp', (string) ($data['fbp'] ?? ''));
        $fbc = $this->cookie_or_input('_fbc', (string) ($data['fbc'] ?? ''));

        if ($fbp !== '') {
            $order->update_meta_data(self::META_FBP, $fbp);
        }
        if ($fbc !== '') {
            $order->update_meta_data(self::META_FBC, $fbc);
        }

        $source_url = sanitize_text_field((string) ($data['source_url'] ?? ''));
        if ($source_url === '') {
            $referer = wp_get_referer();
            $source_url = $referer ? sanitize_text_field($referer) : '';
        }
        if ($source_url !== '') {
            $order->update_meta_data(self::META_SOURCE_URL, $source_url);
        }

        $order->update_meta_data(self::META_EVENT_ID, wp_generate_uuid4());
        $order->update_meta_data(self::META_CLIENT_IP, isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '');
        $order->update_meta_data(self::META_CLIENT_UA, isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '');
    }

    private function cookie_or_input(string $cookie_key, string $fallback): string {
        if (isset($_COOKIE[$cookie_key])) {
            return sanitize_text_field(wp_unslash((string) $_COOKIE[$cookie_key]));
        }
        return sanitize_text_field($fallback);
    }

    public function maybe_send($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $this->send_event($order);
    }

    /**
     * This plugin doesn't own the base browser pixel code (theme snippet,
     * GTM, etc., set up separately) -- but for that code's Purchase call to
     * dedup against this class's server-side CAPI Purchase event, it needs
     * the SAME event_id. Exposing it here means whatever fires the browser
     * pixel just has to read window.RZOG.purchaseEventId instead of
     * generating its own.
     */
    public function output_browser_pixel_event_id($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $event_id = self::get_event_id($order);
        if ($event_id === '') {
            return;
        }

        printf(
            '<script>window.RZOG = window.RZOG || {}; window.RZOG.purchaseEventId = %s;</script>' . "\n",
            wp_json_encode($event_id)
        );
    }

    /** Single source of truth for this order's Purchase event_id -- used by both the CAPI send and the browser-pixel exposure above. */
    public static function get_event_id(\WC_Order $order): string {
        return (string) $order->get_meta(self::META_EVENT_ID);
    }

    /**
     * Per BLUEPRINT 4.3: dedup by an "already sent" meta flag so the three
     * trigger hooks don't send three Purchase events for the same order.
     */
    private function send_event(\WC_Order $order): void {
        if ($order->get_meta(self::META_SENT) === '1') {
            return;
        }

        $pixel_id     = (string) get_option('rzog_capi_pixel_id', '');
        $access_token = Encryption::read_option('rzog_capi_access_token');
        if ($pixel_id === '' || $access_token === '') {
            return; // not configured -- nothing to send, not a failure
        }

        $event = $this->build_event($order);

        $response = wp_remote_post(
            sprintf('https://graph.facebook.com/%s/%s/events', self::API_VERSION, rawurlencode($pixel_id)),
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode([
                    'data'         => [$event],
                    'access_token' => $access_token,
                ]),
                'timeout' => 15,
            ]
        );

        $this->log($order, $event, $response);

        if (is_wp_error($response)) {
            $this->record_failure($order, $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->record_failure($order, sprintf('HTTP %d: %s', $code, wp_remote_retrieve_body($response)));
            return;
        }

        $order->update_meta_data(self::META_SENT, '1');
        $order->delete_meta_data(self::META_LAST_ERROR);
        $order->save();
    }

    private function build_event(\WC_Order $order): array {
        $user_data = [];

        $email = $order->get_billing_email();
        if ($email !== '') {
            $user_data['em'] = [$this->hash(strtolower(trim($email)))];
        }

        $phone = $order->get_billing_phone();
        if ($phone !== '') {
            $user_data['ph'] = [$this->hash(self::to_capi_phone_format($phone))];
        }

        $ip = (string) $order->get_meta(self::META_CLIENT_IP);
        if ($ip !== '') {
            $user_data['client_ip_address'] = $ip;
        }
        $ua = (string) $order->get_meta(self::META_CLIENT_UA);
        if ($ua !== '') {
            $user_data['client_user_agent'] = $ua;
        }
        $fbp = (string) $order->get_meta(self::META_FBP);
        if ($fbp !== '') {
            $user_data['fbp'] = $fbp;
        }
        $fbc = (string) $order->get_meta(self::META_FBC);
        if ($fbc !== '') {
            $user_data['fbc'] = $fbc;
        }

        $content_ids = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $content_ids[] = (string) $product->get_id();
            }
        }

        $event_id = self::get_event_id($order);
        if ($event_id === '') {
            // Order existed before this feature shipped -- still send, just
            // without browser-pixel dedup for this one event.
            $event_id = wp_generate_uuid4();
        }

        $source_url = (string) $order->get_meta(self::META_SOURCE_URL);

        return [
            'event_name'       => 'Purchase',
            'event_time'       => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
            'event_id'         => $event_id,
            'event_source_url' => $source_url !== '' ? $source_url : home_url('/'),
            'action_source'    => 'website',
            'user_data'        => $user_data,
            'custom_data'      => [
                'value'        => (float) $order->get_total(),
                'currency'     => $order->get_currency(),
                'content_ids'  => $content_ids,
                'content_type' => 'product',
            ],
        ];
    }

    /** Meta requires em/ph as SHA-256 hashes of the lowercased, trimmed value. */
    private function hash(string $value): string {
        return hash('sha256', $value);
    }

    /**
     * CAPI wants the BD country-code format (8801...) -- the OPPOSITE of
     * Fraud_Check::normalize_phone()'s local 01... format used for
     * courier/fraud matching. Do not reuse that function here, they have
     * opposite target formats.
     */
    public static function to_capi_phone_format(string $phone): string {
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 11 && $phone[0] === '0') {
            $phone = '880' . substr($phone, 1);
        }
        return $phone;
    }

    private function record_failure(\WC_Order $order, string $message): void {
        $order->update_meta_data(self::META_LAST_ERROR, $message);
        $order->save();
        $order->add_order_note('RZ Order Guard: CAPI Purchase event failed -- ' . $message);
    }

    private function log(\WC_Order $order, array $event, $response): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        error_log(sprintf(
            '[RZOG][CAPI] order=%d event=%s response=%s',
            $order->get_id(),
            wp_json_encode($event),
            is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response)
        ));
    }

    /**
     * Lightweight failure visibility per BLUEPRINT 4.3 -- existence check
     * only (not a full count, to keep this cheap on every admin page load).
     * The dedicated leads/blocklist review UI lands in 4.4, not here.
     */
    public function render_failure_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $orders = wc_get_orders([
            'meta_query' => [
                ['key' => self::META_LAST_ERROR, 'compare' => 'EXISTS'],
            ],
            'limit'  => 1,
            'return' => 'ids',
        ]);

        if (empty($orders)) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>RZ Order Guard:</strong> one or more orders failed to send a Facebook Conversions API Purchase event. Check that order\'s notes for details.</p></div>';
    }
}
