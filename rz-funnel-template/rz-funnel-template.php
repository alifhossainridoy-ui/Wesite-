<?php
/**
 * Plugin Name: RZ Funnel Template
 * Description: Landing-page-style single-product template for RupZone's COD funnel -- order form at the top posting to RZ Order Guard's REST endpoint, benefit content pulled from the product itself, no Elementor. Personal use, BLUEPRINT.md section 4.5.
 * Version: 0.1.0
 * Author: Alif / RupZone Beauty
 * Text Domain: rz-funnel-template
 *
 * Display-only plugin: separate from rz-order-guard (which owns fraud/courier/
 * pixel logic). This plugin overrides WooCommerce's single-product template
 * for every product and consumes rz-order-guard's existing contracts --
 * POST /wp-json/rzog/v1/order for the order form, and the #dp-order-now
 * marker so rz-order-guard's own lead-capture JS binds automatically. It does
 * not duplicate either of those, and works whether or not rz-order-guard is
 * active (the order form simply won't be able to submit without it).
 */

defined('ABSPATH') || exit;

define('RZFT_VERSION', '0.1.0');
define('RZFT_PATH', plugin_dir_path(__FILE__));
define('RZFT_URL', plugin_dir_url(__FILE__));

require_once RZFT_PATH . 'includes/class-faq-meta-box.php';
require_once RZFT_PATH . 'includes/class-funnel-template.php';

add_action('plugins_loaded', function () {
    (new RZFT\FAQ_Meta_Box())->register();
    (new RZFT\Funnel_Template())->register();
});
