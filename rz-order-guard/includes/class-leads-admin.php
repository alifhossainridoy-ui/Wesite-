<?php
namespace RZOG;

defined('ABSPATH') || exit;

/**
 * Manual-review admin list for rzog_leads (BLUEPRINT.md 4.4). Blocked
 * checkout attempts and abandoned carts land here for the business owner
 * to call manually -- no SMS, no automated outreach, confirmed manual-only.
 * Not license-gated: this only manages data already collected, same as the
 * settings screen (CLAUDE.md rule 7 -- the license gate is for functional/
 * external hooks, not admin screens).
 */
class Leads_Admin {

    const NONCE_ACTION_PREFIX = 'rzog_update_lead_status_';

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_rzog_update_lead_status', [$this, 'handle_status_update']);
    }

    public function add_menu(): void {
        add_submenu_page(
            'options-general.php',
            'RZ Order Guard Leads',
            'RZ Order Guard Leads',
            'manage_options',
            'rzog-leads',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        require_once RZOG_PATH . 'includes/class-leads-list-table.php';

        $table = new Leads_List_Table();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1>RZ Order Guard -- Leads</h1>
            <p>Blocked checkout attempts and abandoned carts, for manual phone follow-up. No SMS or automated outreach -- call manually and update status as you go.</p>
            <?php if (isset($_GET['rzog_notice'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Status updated.</p></div>
            <?php endif; ?>
            <form method="get">
                <input type="hidden" name="page" value="rzog-leads">
                <?php $table->views(); ?>
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Nonce-verified status transition for a single lead row's quick-action
     * button. The actual UPDATE (prepared statement) lives in
     * Leads::update_status() -- this just authorizes and validates input.
     */
    public function handle_status_update(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.', 403);
        }

        $lead_id    = isset($_GET['lead_id']) ? absint($_GET['lead_id']) : 0;
        $new_status = isset($_GET['new_status']) ? sanitize_key($_GET['new_status']) : '';

        check_admin_referer(self::NONCE_ACTION_PREFIX . $lead_id);

        if ($lead_id && in_array($new_status, Leads::VALID_STATUSES, true)) {
            Leads::update_status($lead_id, $new_status);
        }

        $redirect = wp_get_referer() ?: admin_url('options-general.php?page=rzog-leads');
        $redirect = remove_query_arg(['action', 'lead_id', 'new_status', '_wpnonce'], $redirect);
        wp_safe_redirect(add_query_arg('rzog_notice', '1', $redirect));
        exit;
    }
}
