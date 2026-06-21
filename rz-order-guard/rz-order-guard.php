<?php
/**
 * Plugin Name: RZ Order Guard
 * Description: Fraud check (local order history + external API, combined), lead capture, and Pixel/CAPI — built for RupZone's landing-page funnel. Personal use: no licensing, no SDK bloat.
 * Version: 0.1.0
 * Author: Alif / RupZone Beauty
 * Text Domain: rz-order-guard
 *
 * PHASE 1+2 of this plugin: DB schema + combined fraud-check logic +
 * settings page + courier integration (Pathao/Steadfast/RedX outbound
 * booking, status refresh, and webhook receivers with the order-status
 * transition fix the old plugin was missing). PHASE 3: order intake REST
 * endpoint (fraud check -> WC_Order, with blocked attempts captured as leads).
 * PHASE 4: lead capture AJAX (#dp-order-now style, input/change capture,
 * no submit needed) + daily WP-Cron cleanup of stale leads.
 * NOT YET BUILT (next phases):
 *   - Server-side CAPI sender (plain wp_remote_post, no Facebook SDK)
 *   - Manual-review admin list for leads/blocklist
 */

defined('ABSPATH') || exit;

define('RZOG_VERSION', '0.3.0');
define('RZOG_PATH', plugin_dir_path(__FILE__));
define('RZOG_URL', plugin_dir_url(__FILE__));

require_once RZOG_PATH . 'includes/class-db.php';
require_once RZOG_PATH . 'includes/class-encryption.php';
require_once RZOG_PATH . 'includes/class-license.php';
require_once RZOG_PATH . 'includes/class-fraud-check.php';
require_once RZOG_PATH . 'includes/class-status-bridge.php';
require_once RZOG_PATH . 'includes/class-admin-settings.php';
require_once RZOG_PATH . 'includes/class-leads.php';
require_once RZOG_PATH . 'includes/class-order-intake.php';
require_once RZOG_PATH . 'includes/class-lead-capture.php';
require_once RZOG_PATH . 'includes/CourierIntegration/Manager.php';
require_once RZOG_PATH . 'includes/CourierIntegration/PathaoClient.php';
require_once RZOG_PATH . 'includes/CourierIntegration/SteadfastClient.php';
require_once RZOG_PATH . 'includes/CourierIntegration/RedXClient.php';
require_once RZOG_PATH . 'includes/Webhooks/PathaoWebhook.php';
require_once RZOG_PATH . 'includes/Webhooks/SteadfastWebhook.php';
require_once RZOG_PATH . 'includes/Webhooks/RedXWebhook.php';

register_activation_hook(__FILE__, ['RZOG\\DB', 'install']);
// Deactivation only ever unregisters cron -- never touches rzog_leads/blocklist data (BLUEPRINT.md section 3).
register_deactivation_hook(__FILE__, ['RZOG\\Lead_Capture', 'deactivate']);

add_action('plugins_loaded', function () {
    // Settings page always loads -- you need it to enter/see the license key.
    (new RZOG\Admin_Settings())->register();

    // Everything functional is gated behind a valid, domain-matched license.
    if (!RZOG\License::is_valid()) {
        add_action('admin_notices', function () {
            if (!current_user_can('manage_options')) {
                return;
            }
            echo '<div class="notice notice-error"><p><strong>RZ Order Guard:</strong> no valid license for this domain -- fraud-check, courier webhooks, and everything else are disabled. Enter a license key under Settings &rarr; RZ Order Guard.</p></div>';
        });
        return;
    }

    (new RZOG\Webhooks\PathaoWebhook())->register();
    (new RZOG\Webhooks\SteadfastWebhook())->register();
    (new RZOG\Webhooks\RedXWebhook())->register();
    (new RZOG\Order_Intake())->register();
    (new RZOG\Lead_Capture())->register();
});
