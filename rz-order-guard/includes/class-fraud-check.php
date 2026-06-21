<?php
namespace RZOG;

defined('ABSPATH') || exit;

class Fraud_Check {

    // Adjust these to match whatever status your Steadfast/Pathao/RedX webhook
    // handlers (Phase 2) actually set on delivery vs return. Confirm against
    // real webhook payloads before trusting this in production.
    const DELIVERED_STATUSES = ['wc-completed'];
    const FAILED_STATUSES    = ['wc-cancelled', 'wc-refunded', 'wc-returned', 'wc-failed'];
    const RESOLVED_STATUSES  = ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-returned', 'wc-failed'];

    public static function normalize_phone(string $phone): string {
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 13 && substr($phone, 0, 3) === '880') {
            $phone = '0' . substr($phone, 3);
        }
        return $phone;
    }

    /**
     * Local check: this customer's own delivery history across Steadfast/
     * Pathao/RedX orders already in YOUR store. Free, instant, no API call.
     * Catches repeat bad-actors who've ordered from RupZone/RupLota before.
     * Cannot catch first-time scammers who've only burned OTHER merchants —
     * that's what external_check() is for.
     */
    public static function local_check(string $phone): array {
        global $wpdb;
        $phone = self::normalize_phone($phone);
        $orders_table    = "{$wpdb->prefix}wc_orders";
        $addresses_table = "{$wpdb->prefix}wc_order_addresses";

        $placeholders = implode(',', array_fill(0, count(self::RESOLVED_STATUSES), '%s'));
        $sql = "SELECT o.status
                FROM {$orders_table} o
                INNER JOIN {$addresses_table} a
                    ON a.order_id = o.id AND a.address_type = 'billing' AND a.phone = %s
                WHERE o.status IN ({$placeholders})";

        $rows = $wpdb->get_col($wpdb->prepare($sql, array_merge([$phone], self::RESOLVED_STATUSES)));

        $delivered = 0;
        $cancelled = 0;
        foreach ($rows as $status) {
            if (in_array($status, self::DELIVERED_STATUSES, true)) {
                $delivered++;
            } elseif (in_array($status, self::FAILED_STATUSES, true)) {
                $cancelled++;
            }
        }

        return [
            'total'     => count($rows),
            'delivered' => $delivered,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * External check: iguazudigital fraud-check API — cross-merchant signal,
     * mainly useful for phone numbers with zero local history. Cached locally
     * per rzog_cache_hours to control API cost/rate-limit. Fails open (returns
     * null) on any error — local_check + threshold logic still decides outcome.
     */
    public static function external_check(string $phone): ?array {
        $phone = self::normalize_phone($phone);

        $cached = self::get_cache($phone, 'external');
        if ($cached !== null) {
            return $cached;
        }

        $api_key = get_option('rzog_fraud_api_key', '');
        if (empty($api_key)) {
            return null;
        }

        $response = wp_remote_post('https://fob-license.iguazudigital.com/api/v1/fraud-check/check', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'      => wp_json_encode(['phone' => $phone]),
            'timeout'   => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['status']) || $body['status'] !== 'success' || !isset($body['data']['aggregated'])) {
            return null;
        }

        $agg = $body['data']['aggregated'];
        $result = [
            'total'     => (int) ($agg['total_parcels'] ?? 0),
            'delivered' => (int) ($agg['total_delivered'] ?? 0),
            'cancelled' => (int) ($agg['total_cancelled'] ?? 0),
        ];

        self::set_cache($phone, 'external', $result);
        return $result;
    }

    /**
     * Combined check — ALWAYS runs both local and external (per your decision).
     * Raw counts are SUMMED before computing the ratio, not percentage-averaged.
     * Averaging percentages from two different-sized samples is statistically
     * wrong (a 1/1 local record shouldn't carry equal weight to a 40/50 external
     * record) — summing counts first weights each actual order correctly.
     */
    public static function combined_check(string $phone): array {
        $local    = self::local_check($phone);
        $external = self::external_check($phone) ?? ['total' => 0, 'delivered' => 0, 'cancelled' => 0];

        $total     = $local['total'] + $external['total'];
        $delivered = $local['delivered'] + $external['delivered'];
        $cancelled = $local['cancelled'] + $external['cancelled'];
        $ratio     = $total > 0 ? round(($delivered / $total) * 100, 2) : 0.0;

        return [
            'local'         => $local,
            'external'      => $external,
            'total'         => $total,
            'delivered'     => $delivered,
            'cancelled'     => $cancelled,
            'success_ratio' => $ratio,
        ];
    }

    /**
     * Decide block/allow against the configured threshold.
     */
    public static function should_block(string $phone): array {
        $data = self::combined_check($phone);
        $threshold         = (float) get_option('rzog_success_threshold', 60);
        $block_no_history  = get_option('rzog_block_no_history', 'yes') === 'yes';

        if ($data['total'] === 0) {
            return ['block' => $block_no_history, 'reason' => 'no_history', 'data' => $data];
        }

        if ($data['success_ratio'] < $threshold) {
            return ['block' => true, 'reason' => 'low_success_ratio', 'data' => $data];
        }

        return ['block' => false, 'reason' => 'ok', 'data' => $data];
    }

    private static function get_cache(string $phone, string $source): ?array {
        global $wpdb;
        $table = DB::table('fraud_cache');
        $hours = (int) get_option('rzog_cache_hours', 24);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT total, delivered, cancelled FROM {$table}
             WHERE phone_number = %s AND source = %s AND checked_at > %s",
            $phone, $source, gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS)
        ), ARRAY_A);

        if (!$row) {
            return null;
        }
        return array_map('intval', $row);
    }

    private static function set_cache(string $phone, string $source, array $data): void {
        global $wpdb;
        $table = DB::table('fraud_cache');
        $wpdb->replace($table, [
            'phone_number' => $phone,
            'source'       => $source,
            'total'        => $data['total'],
            'delivered'    => $data['delivered'],
            'cancelled'    => $data['cancelled'],
            'checked_at'   => current_time('mysql', true),
        ], ['%s', '%s', '%d', '%d', '%d', '%s']);
    }
}
