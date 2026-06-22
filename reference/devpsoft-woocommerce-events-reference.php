<?php
/**
 * WooCommerce event hooks.
 *
 * @package DevPsoft_FB_Pixel_CAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.PHP.YodaConditions

/**
 * WooCommerce event tracking controller.
 */
class DPFB_WooCommerce_Events {
	/**
	 * Register WooCommerce hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'track_complete_registration' ), 20, 1 );
		add_action( 'wp_login', array( __CLASS__, 'mark_user_login' ), 10, 2 );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'track_login_server_fallback' ), 5 );
			return;
		}

		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'queue_purchase_tracking' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'queue_purchase_tracking' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'queue_purchase_tracking' ), 15, 1 );
		add_action( 'dpfb_track_purchase_async', array( __CLASS__, 'send_purchase_event' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'capture_order_meta' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'capture_store_api_order_meta' ), 20, 1 );

		$is_rest_request = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( ( is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) || $is_rest_request ) {
			return;
		}

		add_action( 'template_redirect', array( __CLASS__, 'track_login_server_fallback' ), 5 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'track_add_to_cart_server_fallback' ), 10, 6 );
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'track_remove_from_cart_server_fallback' ), 10, 2 );
	}

	/**
	 * Mark user login event for tracking.
	 *
	 * @param string  $user_login User login.
	 * @param WP_User $user       User object.
	 * @return void
	 */
	public static function mark_user_login( string $user_login, WP_User $user ): void {
		unset( $user_login );

		if ( ! DPFB_Utils::setting( 'enable_login', 1 ) ) {
			return;
		}

		if ( empty( $user->ID ) ) {
			return;
		}

		update_user_meta( $user->ID, 'dpfb_just_login', 1 );
		update_user_meta( $user->ID, '_dpfb_login_event_id', DPFB_Utils::generate_event_id( 'login' ) );
	}

	/**
	 * Track login from server when browser tracking is off.
	 *
	 * @return void
	 */
	public static function track_login_server_fallback(): void {
		if (
			! DPFB_Server_CAPI::is_enabled()
			|| ! DPFB_Utils::setting( 'enable_login', 1 )
			|| DPFB_Utils::setting( 'enable_browser', 1 )
			|| ! is_user_logged_in()
		) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! get_user_meta( $user_id, 'dpfb_just_login', true ) ) {
			return;
		}

		$event_id = sanitize_text_field( (string) get_user_meta( $user_id, '_dpfb_login_event_id', true ) );
		if ( $event_id === '' ) {
			$event_id = DPFB_Utils::generate_event_id( 'login' );
		}

		$source_url = DPFB_Utils::get_current_url();
		if ( $source_url === '' ) {
			$source_url = DPFB_Utils::get_tracking_source_url( (string) wp_get_referer() );
		}

		DPFB_Server_CAPI::send_event(
			'login',
			$event_id,
			array(),
			DPFB_Utils::build_registered_user_data( $user_id, $source_url ),
			$source_url,
			time(),
			0,
			'login',
			true,
			array(),
			$user_id
		);

		delete_user_meta( $user_id, 'dpfb_just_login' );
		delete_user_meta( $user_id, '_dpfb_login_event_id' );
	}

	/**
	 * Capture order meta on checkout.
	 *
	 * @param WC_Order             $order Order instance.
	 * @param array<string, mixed> $data  Checkout data.
	 * @return void
	 */
	public static function capture_order_meta( WC_Order $order, array $data = array() ): void {
		unset( $data );

		$order_id = $order->get_id();
		if ( ! $order_id ) {
			return;
		}

		$event_id = get_post_meta( $order_id, '_dpfb_purchase_event_id', true );
		if ( ! $event_id ) {
			$event_id = DPFB_Utils::generate_event_id( 'purchase' );
		}

		$source_url = DPFB_Utils::get_tracking_source_url( (string) wp_get_referer() );

		$fbp = DPFB_Utils::get_request_fbp();
		$fbc = DPFB_Utils::get_request_fbc( $source_url );

		update_post_meta( $order_id, '_dpfb_purchase_event_id', $event_id );
		$order->update_meta_data( '_dpfb_purchase_event_id', $event_id );

		if ( $fbp ) {
			update_post_meta( $order_id, '_dpfb_fbp', $fbp );
			$order->update_meta_data( '_dpfb_fbp', $fbp );
		}

		if ( $fbc ) {
			update_post_meta( $order_id, '_dpfb_fbc', $fbc );
			$order->update_meta_data( '_dpfb_fbc', $fbc );
		}

		$external_id = DPFB_Utils::get_external_id();
		if ( $external_id !== '' ) {
			update_post_meta( $order_id, '_dpfb_external_id', $external_id );
			$order->update_meta_data( '_dpfb_external_id', $external_id );
		}

		$consent_allowed = DPFB_Utils::is_consent_allowed();
		update_post_meta( $order_id, '_dpfb_consent', $consent_allowed ? 1 : 0 );
		$order->update_meta_data( '_dpfb_consent', $consent_allowed ? 1 : 0 );

		update_post_meta( $order_id, '_dpfb_source_url', $source_url );
		$order->update_meta_data( '_dpfb_source_url', $source_url );
	}

	/**
	 * Capture Store API order meta.
	 *
	 * @param WC_Order $order Order instance.
	 * @return void
	 */
	public static function capture_store_api_order_meta( WC_Order $order ): void {
		self::capture_order_meta( $order, array() );
	}

	/**
	 * Persist order tracking identifiers when the browser request is still available.
	 *
	 * @param WC_Order $order      Order instance.
	 * @param string   $source_url Source URL.
	 * @return void
	 */
	private static function persist_order_tracking_identifiers( WC_Order $order, string $source_url = '' ): void {
		$order_id = absint( $order->get_id() );
		if ( ! $order_id ) {
			return;
		}

		$fbp = $order->get_meta( '_dpfb_fbp', true );
		$fbc = $order->get_meta( '_dpfb_fbc', true );

		if ( ! $fbp ) {
			$fbp = get_post_meta( $order_id, '_dpfb_fbp', true );
		}

		if ( ! $fbc ) {
			$fbc = get_post_meta( $order_id, '_dpfb_fbc', true );
		}

		if ( ! $fbp ) {
			$fbp = DPFB_Utils::get_request_fbp();
		}

		if ( ! $fbc ) {
			$fbc = DPFB_Utils::get_request_fbc( $source_url );
		}

		$fbp = DPFB_Utils::sanitize_meta_click_id( $fbp );
		$fbc = DPFB_Utils::sanitize_meta_fbc( $fbc );

		if ( $fbp !== '' ) {
			update_post_meta( $order_id, '_dpfb_fbp', $fbp );
			$order->update_meta_data( '_dpfb_fbp', $fbp );
		}

		if ( $fbc !== '' ) {
			update_post_meta( $order_id, '_dpfb_fbc', $fbc );
			$order->update_meta_data( '_dpfb_fbc', $fbc );
		}
	}

	/**
	 * Track AddToCart from server fallback.
	 *
	 * @param string               $cart_item_key   Cart item key.
	 * @param int                  $product_id      Product ID.
	 * @param int                  $quantity        Quantity.
	 * @param int                  $variation_id    Variation ID.
	 * @param array<string, mixed> $variation       Variation data.
	 * @param array<string, mixed> $cart_item_data  Cart item data.
	 * @return void
	 */
	public static function track_add_to_cart_server_fallback( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		unset( $variation, $cart_item_data );

		if ( ! DPFB_Server_CAPI::is_enabled() || ! DPFB_Utils::setting( 'enable_addtocart', 1 ) ) {
			return;
		}

		$prepared_event_id = '';
		if ( isset( $_COOKIE['_dpfb_atc_eid'] ) ) {
			$prepared_event_id = sanitize_text_field( wp_unslash( $_COOKIE['_dpfb_atc_eid'] ) );
		}

		// Prefer the browser-triggered AJAX CAPI path when a prepared browser
		// event ID exists. If a custom flow bypasses the browser preparation
		// entirely, keep this server fallback active so AddToCart is not lost.
		if ( DPFB_Utils::setting( 'enable_browser', 1 ) && $prepared_event_id !== '' ) {
			return;
		}

		$product = DPFB_Utils::resolve_product( absint( $product_id ), absint( $variation_id ) );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$event_id = $prepared_event_id ? $prepared_event_id : DPFB_Utils::generate_event_id( 'addtocart' );

		$source_url = '';
		if ( isset( $_COOKIE['_dpfb_atc_source_url'] ) ) {
			$source_url = esc_url_raw( wp_unslash( $_COOKIE['_dpfb_atc_source_url'] ) );
		}

		if ( ! $source_url ) {
			$source_url = esc_url_raw( (string) wp_get_referer() );
		}

		if ( ! $source_url ) {
			$source_url = DPFB_Utils::get_product_permalink_url( $product );
		}

		if ( ! $source_url ) {
			$source_url = DPFB_Utils::get_tracking_source_url();
		}

		DPFB_Utils::set_tracking_cookie( '_dpfb_atc_eid', $event_id, time() + MINUTE_IN_SECONDS );
		DPFB_Utils::set_tracking_cookie( '_dpfb_atc_source_url', $source_url, time() + MINUTE_IN_SECONDS );

		$custom_data = DPFB_Utils::build_product_custom_data( $product, $quantity );
		$user_data   = DPFB_Utils::build_request_user_data( array(), $source_url );

		DPFB_Server_CAPI::queue_server_event(
			'AddToCart',
			$event_id,
			$custom_data,
			$user_data,
			$source_url,
			time(),
			0,
			'addtocart'
		);
	}

	/**
	 * Track RemoveFromCart from server fallback.
	 *
	 * @param string        $cart_item_key Cart item key.
	 * @param WC_Cart|mixed $cart          Cart instance.
	 * @return void
	 */
	public static function track_remove_from_cart_server_fallback( string $cart_item_key, $cart ): void {
		if ( ! DPFB_Server_CAPI::is_enabled() || ! DPFB_Utils::setting( 'enable_removefromcart', 1 ) ) {
			return;
		}

		// When browser tracking is enabled, RemoveFromCart server delivery must come
		// from the browser-triggered AJAX CAPI path so it keeps the same event rule.
		if ( DPFB_Utils::setting( 'enable_browser', 1 ) ) {
			return;
		}

		if ( ! is_object( $cart ) || ! isset( $cart->removed_cart_contents ) || ! is_array( $cart->removed_cart_contents ) ) {
			return;
		}

		if ( empty( $cart->removed_cart_contents[ $cart_item_key ] ) || ! is_array( $cart->removed_cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$cart_item   = $cart->removed_cart_contents[ $cart_item_key ];
		$custom_data = DPFB_Utils::build_cart_item_custom_data( $cart_item );

		if ( empty( $custom_data ) ) {
			return;
		}

		$event_id = '';
		if ( isset( $_COOKIE['_dpfb_rfc_eid'] ) ) {
			$event_id = sanitize_text_field( wp_unslash( $_COOKIE['_dpfb_rfc_eid'] ) );
		}

		if ( ! $event_id ) {
			$event_id = DPFB_Utils::generate_event_id( 'removefromcart' );
		}

		$source_url = '';
		if ( isset( $_COOKIE['_dpfb_rfc_source_url'] ) ) {
			$source_url = esc_url_raw( wp_unslash( $_COOKIE['_dpfb_rfc_source_url'] ) );
		}

		if ( ! $source_url ) {
			$source_url = DPFB_Utils::get_tracking_source_url( (string) wp_get_referer() );
		}

		$user_data = DPFB_Utils::build_request_user_data( array(), $source_url );

		DPFB_Server_CAPI::queue_server_event(
			'RemoveFromCart',
			$event_id,
			$custom_data,
			$user_data,
			$source_url,
			time(),
			0,
			'removefromcart'
		);
	}

	/**
	 * Queue purchase tracking for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function queue_purchase_tracking( int $order_id ): void {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! DPFB_Utils::setting( 'enable_purchase', 1 ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$event_id   = DPFB_Utils::get_or_create_purchase_event_id( $order_id );
			$source_url = DPFB_Utils::get_context_source_url( 'purchase', $order );
			if ( ! $source_url ) {
				$source_url = (string) $order->get_meta( '_dpfb_source_url', true );
			}
			$source_url = (string) $source_url;
			if ( $source_url ) {
				update_post_meta( $order_id, '_dpfb_purchase_source_url', $source_url );
			}
			self::persist_order_tracking_identifiers( $order, $source_url );

			$recovery_payload = DPFB_Utils::build_purchase_recovery_payload(
				$order,
				$source_url,
				$event_id,
				DPFB_Utils::build_purchase_marketing_events( $order )
			);
			if ( ! empty( $recovery_payload ) ) {
				update_post_meta( $order_id, '_dpfb_purchase_recovery_snapshot', wp_json_encode( $recovery_payload ) );
			}
		}

		if ( get_post_meta( $order_id, '_dpfb_purchase_sent', true ) ) {
			return;
		}

		$delay = DPFB_Utils::setting( 'enable_browser', 1 ) ? 30 : 5;

		if ( ! wp_next_scheduled( 'dpfb_track_purchase_async', array( $order_id ) ) ) {
			wp_schedule_single_event( time() + $delay, 'dpfb_track_purchase_async', array( $order_id ) );
		}
	}

	/**
	 * Send purchase event for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function send_purchase_event( int $order_id ): void {
		if ( ! DPFB_Server_CAPI::is_enabled() || ! DPFB_Utils::setting( 'enable_purchase', 1 ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! DPFB_Server_CAPI::is_valid_purchase_order( $order ) ) {
			return;
		}

		if ( get_post_meta( $order->get_id(), '_dpfb_purchase_sent', true ) ) {
			return;
		}

		$event_id = DPFB_Utils::get_or_create_purchase_event_id( $order->get_id() );
		if ( ! $event_id ) {
			return;
		}

		$custom_data = DPFB_Utils::build_order_custom_data( $order );
		$source_url  = DPFB_Utils::get_context_source_url( 'purchase', $order );

		if ( ! $source_url ) {
			$source_url = (string) $order->get_meta( '_dpfb_source_url', true );
		}
		if ( ! $source_url ) {
			$source_url = (string) get_post_meta( $order->get_id(), '_dpfb_purchase_source_url', true );
		}
		$source_url = (string) $source_url;

		if ( $source_url ) {
			update_post_meta( $order->get_id(), '_dpfb_purchase_source_url', $source_url );
		}
		self::persist_order_tracking_identifiers( $order, $source_url );

		$user_data = DPFB_Utils::build_order_user_data( $order );

		$recovery_payload = DPFB_Utils::build_purchase_recovery_payload(
			$order,
			$source_url,
			$event_id,
			DPFB_Utils::build_purchase_marketing_events( $order )
		);
		if ( ! empty( $recovery_payload ) ) {
			update_post_meta( $order->get_id(), '_dpfb_purchase_recovery_snapshot', wp_json_encode( $recovery_payload ) );
		}

		$event_time = time();
		if ( $order->get_date_paid() ) {
			$event_time = $order->get_date_paid()->getTimestamp();
		} elseif ( $order->get_date_created() ) {
			$event_time = $order->get_date_created()->getTimestamp();
		}

		$result = DPFB_Server_CAPI::send_event( 'Purchase', $event_id, $custom_data, $user_data, $source_url, $event_time, $order->get_id(), 'purchase-fallback', true );

		if ( ! empty( $result['success'] ) ) {
			self::send_purchase_marketing_events( $order, $user_data, $source_url, $event_time );
			update_post_meta( $order->get_id(), '_dpfb_purchase_event_id', $event_id );
			update_post_meta( $order->get_id(), '_dpfb_purchase_sent', 1 );
			update_post_meta( $order->get_id(), '_dpfb_purchase_sent_at', current_time( 'mysql' ) );
		}
	}

	/**
	 * Send purchase marketing events.
	 *
	 * @param WC_Order             $order      Order instance.
	 * @param array<string, mixed> $user_data  User data.
	 * @param string               $source_url Source URL.
	 * @param int                  $event_time Event time.
	 * @return void
	 */
	private static function send_purchase_marketing_events( WC_Order $order, array $user_data, string $source_url, int $event_time ): void {
		$events = DPFB_Utils::build_purchase_marketing_events( $order );
		if ( empty( $events ) ) {
			return;
		}

		foreach ( $events as $key => $event ) {
			if ( empty( $event['event_name'] ) ) {
				continue;
			}

			$event_id = DPFB_Utils::get_or_create_order_event_id( $order->get_id(), 'purchase_' . $key );
			if ( ! $event_id ) {
				continue;
			}

			DPFB_Server_CAPI::send_event(
				sanitize_text_field( (string) $event['event_name'] ),
				$event_id,
				! empty( $event['custom_data'] ) && is_array( $event['custom_data'] ) ? $event['custom_data'] : array(),
				$user_data,
				$source_url,
				$event_time,
				$order->get_id(),
				'purchase-marketing',
				true
			);
		}
	}

	/**
	 * Track complete registration server event.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function track_complete_registration( int $user_id ): void {
		if ( ! DPFB_Server_CAPI::is_enabled() || ! DPFB_Utils::setting( 'enable_completeregistration', 1 ) ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
			return;
		}

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		$event_id   = DPFB_Utils::generate_event_id( 'completeregistration' );
		$source_url = '';

		if ( isset( $_COOKIE['_dpfb_reg_source_url'] ) ) {
			$source_url = esc_url_raw( wp_unslash( $_COOKIE['_dpfb_reg_source_url'] ) );
		}

		if ( ! $source_url ) {
			$source_url = DPFB_Utils::get_current_url();
		}

		if ( ! $source_url ) {
			$source_url = DPFB_Utils::get_tracking_source_url( (string) wp_get_referer() );
		}

		DPFB_Utils::set_tracking_cookie( '_dpfb_reg_event_id', $event_id, time() + HOUR_IN_SECONDS );
		DPFB_Utils::set_tracking_cookie( '_dpfb_reg_source_url', $source_url, time() + HOUR_IN_SECONDS );

		$custom_data = DPFB_Utils::build_complete_registration_custom_data();
		$user_data   = DPFB_Utils::build_registered_user_data( $user_id, $source_url );

		DPFB_Server_CAPI::send_event(
			'CompleteRegistration',
			$event_id,
			$custom_data,
			$user_data,
			$source_url,
			time(),
			0,
			'complete-registration',
			true,
			array(),
			$user_id
		);
	}
}

// phpcs:enable WordPress.PHP.YodaConditions
