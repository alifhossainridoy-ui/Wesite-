<?php
namespace RZFT;

defined('ABSPATH') || exit;

/**
 * Overrides WooCommerce's single-product template for every product via
 * `template_include`, so the landing-page layout applies automatically with
 * zero per-product setup (BLUEPRINT.md 4.5) -- no Elementor, no page-builder
 * runtime. Also enqueues the funnel's own CSS/JS, only on product pages.
 */
class Funnel_Template {

    public function register(): void {
        add_filter('template_include', [$this, 'maybe_override_template']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function maybe_override_template(string $template): string {
        if (!function_exists('is_product') || !is_product()) {
            return $template;
        }

        return RZFT_PATH . 'templates/single-product.php';
    }

    public function enqueue_assets(): void {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        // Self-hosting these isn't possible without bundling font files into
        // the plugin; Google Fonts is the pragmatic default here -- swap for
        // a self-hosted copy later if the render-blocking request shows up
        // as a real problem on live mobile-network testing.
        wp_enqueue_style(
            'rzft-fonts',
            'https://fonts.googleapis.com/css2?family=Noto+Serif+Bengali:wght@600;700&family=Hind+Siliguri:wght@400;500;600&family=Playfair+Display:wght@700&display=swap',
            [],
            null
        );

        wp_enqueue_style('rzft-funnel', RZFT_URL . 'assets/css/funnel.css', ['rzft-fonts'], RZFT_VERSION);

        wp_enqueue_script('rzft-order-form', RZFT_URL . 'assets/js/order-form.js', [], RZFT_VERSION, true);

        $checkout_url  = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('checkout') : home_url('/');
        $thankyou_base = function_exists('wc_get_endpoint_url')
            ? wc_get_endpoint_url('order-received', 'RZFT_ORDER_ID', $checkout_url)
            : '';

        wp_localize_script('rzft-order-form', 'RZFT_ORDER_FORM', [
            'rest_url'       => rest_url('rzog/v1/order'),
            'thankyou_url'   => $thankyou_base,
            'currency'       => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'contact'        => [
                'whatsapp'  => get_option('rzog_contact_whatsapp', ''),
                'phone'     => get_option('rzog_contact_phone', ''),
                'messenger' => get_option('rzog_contact_messenger', ''),
            ],
        ]);
    }
}
