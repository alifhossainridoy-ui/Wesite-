<?php
namespace RZOG;

defined('ABSPATH') || exit;

// Only ever required from Leads_Admin::render() (admin context), so it's
// safe to assume wp-admin/includes is reachable, but WP_List_Table itself
// isn't autoloaded -- pull it in explicitly if nothing else already has.
if (!class_exists('\\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, sortable, status-filterable list of rzog_leads rows
 * (BLUEPRINT.md 4.4). Built on core's own WP_List_Table so pagination and
 * column sorting come for free instead of being reinvented.
 */
class Leads_List_Table extends \WP_List_Table {

    const PER_PAGE = 20;

    public function __construct() {
        parent::__construct([
            'singular' => 'lead',
            'plural'   => 'leads',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array {
        return [
            'name'       => 'Name',
            'phone'      => 'Phone',
            'address'    => 'Address',
            'product'    => 'Product',
            'value'      => 'Value',
            'status'     => 'Status',
            'created_at' => 'Created',
            'actions'    => 'Actions',
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'status'     => ['status', false],
            'created_at' => ['created_at', true],
        ];
    }

    /** Status filter links with counts -- "show me everything still new" in one click. */
    public function get_views(): array {
        global $wpdb;
        $table   = DB::table('leads');
        $current = isset($_GET['status_filter']) ? sanitize_key($_GET['status_filter']) : '';

        $counts    = $wpdb->get_results("SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A);
        $by_status = [];
        $total     = 0;
        foreach ($counts as $row) {
            $by_status[$row['status']] = (int) $row['c'];
            $total += (int) $row['c'];
        }

        $base_url = remove_query_arg(['status_filter', 'paged']);

        $views = [
            'all' => sprintf(
                '<a href="%s" class="%s">All <span class="count">(%d)</span></a>',
                esc_url($base_url),
                $current === '' ? 'current' : '',
                $total
            ),
        ];

        foreach (Leads::VALID_STATUSES as $status) {
            $count = $by_status[$status] ?? 0;
            $url   = esc_url(add_query_arg('status_filter', $status, $base_url));
            $views[$status] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                $url,
                $current === $status ? 'current' : '',
                esc_html(ucfirst($status)),
                $count
            );
        }

        return $views;
    }

    public function prepare_items(): void {
        global $wpdb;
        $table = DB::table('leads');

        $per_page     = self::PER_PAGE;
        $current_page = $this->get_pagenum();

        $status_filter = isset($_GET['status_filter']) ? sanitize_key($_GET['status_filter']) : '';
        $where         = '';
        $where_args    = [];
        if ($status_filter !== '' && in_array($status_filter, Leads::VALID_STATUSES, true)) {
            $where        = 'WHERE status = %s';
            $where_args[] = $status_filter;
        }

        $orderby = (isset($_GET['orderby']) && in_array($_GET['orderby'], ['status', 'created_at'], true))
            ? sanitize_key($_GET['orderby'])
            : 'created_at';
        $order = (isset($_GET['order']) && strtolower((string) $_GET['order']) === 'asc') ? 'ASC' : 'DESC';

        $total_sql   = "SELECT COUNT(*) FROM {$table} {$where}";
        $total_items = $where_args
            ? (int) $wpdb->get_var($wpdb->prepare($total_sql, $where_args))
            : (int) $wpdb->get_var($total_sql);

        $offset = ($current_page - 1) * $per_page;
        $sql    = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $args   = array_merge($where_args, [$per_page, $offset]);

        $this->items = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total_items / $per_page),
        ]);
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'name':
                return esc_html($item['name'] ?? '');
            case 'address':
                return esc_html($item['address'] ?? '');
            case 'value':
                return $item['value'] !== null && $item['value'] !== ''
                    ? esc_html(number_format((float) $item['value'], 2) . ' ' . ($item['currency'] ?? ''))
                    : '';
            case 'created_at':
                return esc_html($item['created_at'] ?? '');
            default:
                return '';
        }
    }

    public function column_phone($item) {
        $phone = $item['phone'] ?? '';
        if ($phone === '') {
            return '';
        }
        return sprintf('<a href="tel:%s">%s</a>', esc_attr($phone), esc_html($phone));
    }

    public function column_product($item) {
        $name = $item['product_name'] ?? '';
        if ($name === '' && !empty($item['product_id'])) {
            $product = function_exists('wc_get_product') ? wc_get_product((int) $item['product_id']) : false;
            $name    = $product ? $product->get_name() : ('#' . $item['product_id']);
        }
        return esc_html((string) $name);
    }

    public function column_status($item) {
        return esc_html(ucfirst($item['status'] ?? ''));
    }

    /** new -> called -> confirmed/rejected, per BLUEPRINT 4.4 -- manual only. */
    public function column_actions($item) {
        $id      = (int) $item['id'];
        $status  = $item['status'] ?? '';
        $buttons = [];

        if (in_array($status, ['new', 'abandoned', 'blocked'], true)) {
            $buttons[] = $this->status_button($id, 'called', 'Mark Called');
        }
        if ($status === 'called') {
            $buttons[] = $this->status_button($id, 'confirmed', 'Confirmed');
            $buttons[] = $this->status_button($id, 'rejected', 'Rejected');
        }

        return implode(' ', $buttons);
    }

    private function status_button(int $id, string $new_status, string $label): string {
        $url = wp_nonce_url(
            add_query_arg(
                [
                    'action'     => 'rzog_update_lead_status',
                    'lead_id'    => $id,
                    'new_status' => $new_status,
                ],
                admin_url('admin-post.php')
            ),
            Leads_Admin::NONCE_ACTION_PREFIX . $id
        );
        return sprintf('<a href="%s" class="button button-small">%s</a>', esc_url($url), esc_html($label));
    }
}
