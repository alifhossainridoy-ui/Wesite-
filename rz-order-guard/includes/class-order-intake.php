<?php
namespace RZOG;

defined('ABSPATH') || exit;

/**
 * REST endpoint the landing-page order form submits to directly -- no
 * classic /checkout/ page involved. Validates input, runs the blocklist +
 * fraud check, and only then creates a real WC_Order, firing the same
 * checkout hooks core WooCommerce fires so other hooked behavior (current
 * or future) still runs. See BLUEPRINT.md section 4.1 for the full spec
 * this implements step-by-step.
 */
class Order_Intake {

    const BD_PHONE_REGEX = '/^01[3-9][0-9]{8}$/';

    // Throttle on raw request volume, independent of Fraud_Check -- that
    // answers "is this phone trustworthy", this answers "is this client
    // flooding the endpoint". Plain transient counter, no DB schema needed.
    const RATE_LIMIT_MAX    = 8;  // requests
    const RATE_LIMIT_WINDOW = 60; // seconds

    const SESSION_META_KEY = '_rzog_session_id';

    public function register(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('rzog/v1', '/order', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(\WP_REST_Request $request) {
        if ($this->is_rate_limited()) {
            return $this->response(['errors' => ['general' => 'Too many requests. Please try again shortly.']], 429);
        }

        $input = $this->collect_input($request);

        $errors = $this->validate($input);
        if (!empty($errors)) {
            return $this->response(['errors' => $errors], 422);
        }

        $input['phone'] = Fraud_Check::normalize_phone($input['phone']);
        if (!preg_match(self::BD_PHONE_REGEX, $input['phone'])) {
            return $this->response(['errors' => ['billing_phone' => 'Enter a valid Bangladeshi mobile number.']], 422);
        }

        if ($input['session_id'] !== '') {
            $existing_order = $this->find_order_by_session($input['session_id']);
            if ($existing_order) {
                return $this->response([
                    'order_id'   => $existing_order->get_id(),
                    'order_key'  => $existing_order->get_order_key(),
                    'total'      => $existing_order->get_total(),
                    'currency'   => $existing_order->get_currency(),
                    'idempotent' => true,
                ], 200);
            }
        }

        $product = $this->resolve_product($input);
        if (is_array($product) && isset($product['errors'])) {
            return $this->response($product, 422);
        }

        if ($this->is_blocklisted($input['phone'])) {
            return $this->blocked_response($input, 'blocklist');
        }

        $fraud = Fraud_Check::should_block($input['phone']);
        if ($fraud['block']) {
            return $this->blocked_response($input, $fraud['reason']);
        }

        // Give any other plugin hooked here (now or later) a chance to block too.
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices(); // start from a clean slate -- this is a stateless REST call
        }
        ob_start();
        do_action('woocommerce_checkout_process');
        ob_end_clean();

        if (function_exists('wc_notice_count') && wc_notice_count('error') > 0) {
            $notices = function_exists('wc_get_notices') ? wc_get_notices('error') : [];
            $message = $notices[0]['notice'] ?? 'This order could not be placed.';
            if (function_exists('wc_clear_notices')) {
                wc_clear_notices();
            }
            return $this->response(['errors' => ['general' => wp_strip_all_tags($message)]], 422);
        }

        $order = $this->create_order($input, $product);

        $lead_id = Leads::find_open_id($input['session_id'], $input['phone']);
        if ($lead_id) {
            Leads::mark_converted($lead_id, $order->get_id());
        }

        return $this->response([
            'order_id'  => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'total'     => $order->get_total(),
            'currency'  => $order->get_currency(),
        ], 201);
    }

    /**
     * Pulls every field we care about off the request, sanitizing as we go.
     * Accepts JSON or form-encoded bodies (WP_REST_Request::get_param()
     * handles both).
     */
    private function collect_input(\WP_REST_Request $request): array {
        $get = function (string $key) use ($request) {
            $value = $request->get_param($key);
            return $value === null ? '' : (string) $value;
        };

        return [
            'first_name'  => sanitize_text_field($get('billing_first_name')),
            'last_name'   => sanitize_text_field($get('billing_last_name')),
            'phone'       => sanitize_text_field($get('billing_phone')),
            'email'       => sanitize_email($get('billing_email')),
            'address_1'   => sanitize_text_field($get('billing_address_1')),
            'city'        => sanitize_text_field($get('billing_city')),
            'state'       => sanitize_text_field($get('billing_state')),
            'postcode'    => sanitize_text_field($get('billing_postcode')),
            'country'     => sanitize_text_field($get('billing_country')) ?: 'BD',
            'product_id'  => absint($get('product_id')),
            'variation_id' => absint($get('variation_id')),
            'quantity'    => max(1, absint($get('quantity')) ?: 1),
            'session_id'  => sanitize_text_field($get('session_id')),
            'source_url'  => sanitize_text_field($get('source_url')),
            'fbp'         => sanitize_text_field($get('fbp')),
            'fbc'         => sanitize_text_field($get('fbc')),
        ];
    }

    private function validate(array $input): array {
        $errors = [];

        if ($input['first_name'] === '') {
            $errors['billing_first_name'] = 'Name is required.';
        }
        if ($input['phone'] === '') {
            $errors['billing_phone'] = 'Phone number is required.';
        }
        if ($input['address_1'] === '') {
            $errors['billing_address_1'] = 'Address is required.';
        }
        if (!$input['product_id']) {
            $errors['product_id'] = 'No product selected.';
        }
        if ($input['quantity'] < 1) {
            $errors['quantity'] = 'Quantity must be at least 1.';
        }

        return $errors;
    }

    /**
     * @return \WC_Product|array Product on success, or ['errors' => [...]] on failure.
     */
    private function resolve_product(array $input) {
        $id      = $input['variation_id'] ?: $input['product_id'];
        $product = function_exists('wc_get_product') ? wc_get_product($id) : false;

        if (!$product || !$product->exists()) {
            return ['errors' => ['product_id' => 'Product not found.']];
        }
        if (!$product->is_purchasable()) {
            return ['errors' => ['product_id' => 'This product is not currently available.']];
        }

        return $product;
    }

    /**
     * IP source for the blocklist check. REMOTE_ADDR is correct for a site
     * with no reverse proxy in front of it. If this site ends up behind
     * Cloudflare or another proxy/CDN, switch this to read
     * CF-Connecting-IP / X-Forwarded-For instead -- otherwise every visitor
     * will appear to come from the proxy's IP and IP-based blocking will be
     * silently useless (it will never match, and never wrongly block, it'll
     * just do nothing).
     */
    private function client_ip(): string {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    /**
     * Per-IP request throttle, independent of Fraud_Check. A transient
     * counter is enough here -- no need for a DB table for something this
     * disposable, and it self-clears via the transient's own expiry.
     */
    private function is_rate_limited(): bool {
        $ip = $this->client_ip();
        if ($ip === '') {
            return false; // can't throttle what we can't identify
        }

        $key   = 'rzog_intake_rl_' . md5($ip);
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT_MAX) {
            return true;
        }

        set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
        return false;
    }

    /**
     * Idempotency: if this session_id already produced an order (double
     * submit, client-side retry-on-timeout), return that order instead of
     * creating a duplicate.
     */
    private function find_order_by_session(string $session_id): ?\WC_Order {
        $orders = wc_get_orders([
            'meta_key'   => self::SESSION_META_KEY,
            'meta_value' => $session_id,
            'limit'      => 1,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);

        return $orders[0] ?? null;
    }

    private function is_blocklisted(string $phone): bool {
        global $wpdb;
        $table = DB::table('blocklist');
        $ip    = $this->client_ip();
        $now   = current_time('mysql', true);

        $match_clause = $ip !== '' ? '(phone_number = %s OR ip_address = %s)' : '(phone_number = %s)';
        $match_values = $ip !== '' ? [$phone, $ip] : [$phone];

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE {$match_clause}
             AND block_start <= %s
             AND (block_end IS NULL OR block_end >= %s)
             LIMIT 1",
            array_merge($match_values, [$now, $now])
        ));

        return (bool) $row;
    }

    /**
     * Per BLUEPRINT 4.1 step 4: a blocked attempt is not a silent failure --
     * it becomes a lead for the manual-call review queue (4.4), and the
     * frontend is told to show the contact-modal instead of a generic error.
     */
    private function blocked_response(array $input, string $reason): \WP_REST_Response {
        Leads::upsert([
            'session_id'   => $input['session_id'],
            'name'         => trim($input['first_name'] . ' ' . $input['last_name']),
            'phone'        => $input['phone'],
            'address'      => $input['address_1'],
            'product_id'   => $input['product_id'],
            'source_url'   => $input['source_url'],
            'fbp'          => $input['fbp'],
            'fbc'          => $input['fbc'],
            'status'       => 'blocked',
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[RZOG] Order intake blocked (%s): phone=%s', $reason, $input['phone']));
        }

        return $this->response([
            'blocked' => true,
            'contact' => [
                'whatsapp'  => get_option('rzog_contact_whatsapp', ''),
                'phone'     => get_option('rzog_contact_phone', ''),
                'messenger' => get_option('rzog_contact_messenger', ''),
            ],
        ], 200);
    }

    /**
     * Builds the order entirely in memory, fires the same checkout hooks
     * core WooCommerce fires (CLAUDE.md hard rule #4), and only saves once
     * -- matching how WC_Checkout::create_order() itself sequences this.
     */
    private function create_order(array $input, \WC_Product $product): \WC_Order {
        $address = [
            'first_name' => $input['first_name'],
            'last_name'  => $input['last_name'],
            'address_1'  => $input['address_1'],
            'city'       => $input['city'],
            'state'      => $input['state'],
            'postcode'   => $input['postcode'],
            'country'    => $input['country'],
            'phone'      => $input['phone'],
            'email'      => $input['email'],
        ];

        $order = new \WC_Order();
        $order->set_created_via('rzog_order_intake');
        if ($input['session_id'] !== '') {
            $order->update_meta_data(self::SESSION_META_KEY, $input['session_id']);
        }
        $order->set_address($address, 'billing');
        $order->set_address($address, 'shipping'); // shipping = billing, no separate address in this funnel
        $order->add_product($product, $input['quantity']);
        $order->set_payment_method('cod');
        $order->set_payment_method_title('Cash on Delivery');
        $order->calculate_totals();

        do_action('woocommerce_checkout_create_order', $order, $input);

        $order->save();
        $order->update_status('processing', 'COD order via landing funnel.');

        return $order;
    }

    private function response(array $data, int $status = 200): \WP_REST_Response {
        $res = rest_ensure_response($data);
        $res->set_status($status);
        return $res;
    }
}
