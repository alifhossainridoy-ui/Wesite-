<?php
namespace RZOG;

defined('ABSPATH') || exit;

/**
 * Shared rzog_leads upsert logic -- used by the order-intake endpoint
 * (blocked attempts are leads too) and by the lead-capture AJAX handler
 * (partial form data, no submit needed). One place so both stay in sync.
 */
class Leads {

    const VALID_STATUSES = ['new', 'called', 'confirmed', 'rejected', 'converted', 'abandoned', 'blocked'];

    /**
     * Insert a new lead row, or update the existing one for this session/phone.
     * Matches first by session_id, then by phone (excluding already-converted
     * rows, so a repeat visitor doesn't get merged into an old completed order).
     * Fields not present in $data are left untouched on update.
     *
     * @param array $data session_id, name, phone, address, product_id,
     *                     product_name, value, currency, fbp, fbc, source_url, status
     * @return int Lead row ID.
     */
    public static function upsert(array $data): int {
        global $wpdb;
        $table = DB::table('leads');
        $now   = current_time('mysql', true);

        $session_id = isset($data['session_id']) ? sanitize_text_field((string) $data['session_id']) : '';
        $phone      = isset($data['phone']) ? Fraud_Check::normalize_phone((string) $data['phone']) : '';

        $existing_id = self::find_open_id($session_id, $phone);

        $fields = [
            'session_id'   => $session_id !== '' ? $session_id : null,
            'name'         => isset($data['name']) ? sanitize_text_field((string) $data['name']) : null,
            'phone'        => $phone !== '' ? $phone : null,
            'address'      => isset($data['address']) ? sanitize_textarea_field((string) $data['address']) : null,
            'product_id'   => isset($data['product_id']) ? absint($data['product_id']) : null,
            'product_name' => isset($data['product_name']) ? sanitize_text_field((string) $data['product_name']) : null,
            'value'        => isset($data['value']) ? (float) $data['value'] : null,
            'currency'     => isset($data['currency']) ? sanitize_text_field((string) $data['currency']) : null,
            'fbp'          => isset($data['fbp']) ? sanitize_text_field((string) $data['fbp']) : null,
            'fbc'          => isset($data['fbc']) ? sanitize_text_field((string) $data['fbc']) : null,
            'source_url'   => isset($data['source_url']) ? sanitize_text_field((string) $data['source_url']) : null,
            'status'       => (isset($data['status']) && in_array($data['status'], self::VALID_STATUSES, true)) ? $data['status'] : 'new',
        ];

        if ($existing_id) {
            $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $existing_id), ARRAY_A);
            foreach ($fields as $key => $value) {
                if ($value === null && isset($current[$key]) && $current[$key] !== '') {
                    $fields[$key] = $current[$key];
                }
            }
            $fields['updated_at'] = $now;

            $wpdb->update($table, $fields, ['id' => $existing_id]);
            return $existing_id;
        }

        $fields['created_at'] = $now;
        $fields['updated_at'] = $now;

        $wpdb->insert($table, $fields);
        return (int) $wpdb->insert_id;
    }

    /**
     * Find an open (not yet converted) lead row by session_id first, then phone.
     */
    public static function find_open_id(string $session_id, string $phone): int {
        global $wpdb;
        $table = DB::table('leads');

        if ($session_id !== '') {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE session_id = %s AND status != 'converted' ORDER BY id DESC LIMIT 1",
                $session_id
            ));
            if ($id) {
                return $id;
            }
        }

        if ($phone !== '') {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE phone = %s AND status != 'converted' ORDER BY id DESC LIMIT 1",
                $phone
            ));
            if ($id) {
                return $id;
            }
        }

        return 0;
    }

    public static function mark_converted(int $lead_id, int $order_id): void {
        global $wpdb;
        $table = DB::table('leads');
        $wpdb->update(
            $table,
            [
                'status'     => 'converted',
                'order_id'   => $order_id,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $lead_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }
}
