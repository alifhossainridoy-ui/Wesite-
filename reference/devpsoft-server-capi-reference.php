<?php
/**
 * Server-side Conversions API integration.
 *
 * @package DevPsoft_FB_Pixel_CAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.PHP.YodaConditions

/**
 * Server-side CAPI controller.
 */
class DPFB_Server_CAPI {
	/**
	 * Conversions API version.
	 *
	 * @var string
	 */
	const API_VERSION = 'v21.0';

	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_dpfb_track_event', array( __CLASS__, 'ajax_track_event' ) );
		add_action( 'wp_ajax_nopriv_dpfb_track_event', array( __CLASS__, 'ajax_track_event' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prime_tracking_identifiers' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'queue_page_events' ), 20 );
		add_action( 'dpfb_process_event_queue', array( __CLASS__, 'process_queue' ) );
	}

	/**
	 * Check if server tracking is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return DPFB_License_Manager::is_active()
			&& (bool) DPFB_Utils::setting( 'enable_capi', 1 )
			&& DPFB_Utils::setting( 'pixel_id', '' ) !== ''
			&& DPFB_Utils::setting( 'access_token', '' ) !== '';
	}

	/**
	 * Validate purchase order eligibility.
	 *
	 * @param WC_Order|null $order Order instance.
	 * @return bool
	 */
	public static function is_valid_purchase_order( ?WC_Order $order ): bool {
		if ( ! $order ) {
			return false;
		}

		$status = $order->get_status();
		if ( ! in_array( $status, array( 'processing', 'completed' ), true ) ) {
			return false;
		}

		if ( 'processing' === $status && ! DPFB_Utils::setting( 'track_processing', 1 ) ) {
			return false;
		}

		if ( 'completed' === $status && ! DPFB_Utils::setting( 'track_completed', 1 ) ) {
			return false;
		}

		if ( (float) $order->get_total() <= 0 || (int) $order->get_item_count() < 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize event source URL.
	 *
	 * @param string $event_name Event name.
	 * @param string $source_url Source URL.
	 * @param int    $order_id   Order ID.
	 * @return string
	 */
	private static function normalize_event_source_url( string $event_name, string $source_url, int $order_id = 0 ): string {
		$source_url = esc_url_raw( (string) $source_url );

		if ( 'ViewContent' === $event_name ) {
			$view_url = DPFB_Utils::get_product_permalink_url();
			if ( $view_url !== '' ) {
				return $view_url;
			}

			return $source_url ? $source_url : DPFB_Utils::get_current_url();
		}

		if ( 'InitiateCheckout' === $event_name ) {
			$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
			if ( $checkout_url !== '' ) {
				return esc_url_raw( $checkout_url );
			}

			return $source_url ? $source_url : DPFB_Utils::get_current_url();
		}

		if ( 'Purchase' === $event_name && $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$purchase_url = DPFB_Utils::get_context_source_url( 'purchase', $order );
				if ( $purchase_url !== '' ) {
					return $purchase_url;
				}
			}

			return $source_url ? $source_url : DPFB_Utils::get_current_url();
		}

		return $source_url ? $source_url : DPFB_Utils::get_current_url();
	}

	/**
	 * Decide if page events should be skipped.
	 *
	 * @return bool
	 */
	private static function should_skip_page_queue(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX ) ) {
			return true;
		}

		$method = strtoupper( (string) DPFB_Utils::get_server_value( array( 'REQUEST_METHOD' ) ) );
		if ( $method && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return true;
		}

		if ( '' !== DPFB_Utils::get_request_value( 'wc-ajax', '' ) ) {
			return true;
		}

		$request_uri = (string) DPFB_Utils::get_server_value( array( 'REQUEST_URI' ) );
		if ( $request_uri && false !== strpos( $request_uri, 'wc-ajax=' ) ) {
			return true;
		}

		if ( $request_uri && preg_match( '/\.(?:css|js|map|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot)(?:\?.*)?$/i', $request_uri ) ) {
			return true;
		}

		$accept = strtolower( (string) DPFB_Utils::get_server_value( array( 'HTTP_ACCEPT' ) ) );
		if ( $accept && false === strpos( $accept, 'text/html' ) && false === strpos( $accept, 'application/xhtml+xml' ) ) {
			return true;
		}

		$sec_fetch_mode = strtolower( (string) DPFB_Utils::get_server_value( array( 'HTTP_SEC_FETCH_MODE' ) ) );
		if ( $sec_fetch_mode && 'navigate' !== $sec_fetch_mode ) {
			return true;
		}

		$sec_fetch_dest = strtolower( (string) DPFB_Utils::get_server_value( array( 'HTTP_SEC_FETCH_DEST' ) ) );
		if ( $sec_fetch_dest && ! in_array( $sec_fetch_dest, array( 'document', 'iframe' ), true ) ) {
			return true;
		}

		$purpose     = strtolower( (string) DPFB_Utils::get_server_value( array( 'HTTP_PURPOSE' ) ) );
		$sec_purpose = strtolower( (string) DPFB_Utils::get_server_value( array( 'HTTP_SEC_PURPOSE' ) ) );
		if (
			( $purpose && preg_match( '/prefetch|prerender/', $purpose ) )
			|| ( $sec_purpose && preg_match( '/prefetch|prerender/', $sec_purpose ) )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Persist browser identifiers early.
	 *
	 * @return void
	 */
	public static function prime_tracking_identifiers(): void {
		if ( self::should_skip_page_queue() ) {
			return;
		}

		DPFB_Utils::persist_tracking_identifiers( DPFB_Utils::get_current_url() );
	}

	/**
	 * Queue page-level server events.
	 *
	 * @return void
	 */
	public static function queue_page_events(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( DPFB_Utils::is_cache_preload_request() ) {
			return;
		}

		if ( ! DPFB_Utils::is_consent_allowed() ) {
			return;
		}

		if ( self::should_skip_page_queue() ) {
			return;
		}

		$page_source_url = DPFB_Utils::get_context_source_url( 'pageview' );
		$event_time      = time();

		if (
			DPFB_Utils::setting( 'enable_pageview', 1 )
			&& ! DPFB_Utils::setting( 'enable_browser', 1 )
		) {
			self::queue_server_event(
				'PageView',
				DPFB_Utils::get_request_event_id( 'pageview' ),
				array(),
				DPFB_Utils::build_pageview_user_data( $page_source_url ),
				$page_source_url,
				$event_time,
				0,
				'pageview'
			);
		}

		if (
			DPFB_Utils::setting( 'enable_viewcontent', 1 )
			&& ! DPFB_Utils::setting( 'enable_browser', 1 )
			&& function_exists( 'is_product' )
			&& is_product()
			&& function_exists( 'wc_get_product' )
		) {
			global $product;

			if ( ! $product instanceof WC_Product ) {
				$product = wc_get_product( get_the_ID() );
			}

			if ( $product instanceof WC_Product ) {
				$view_source_url = DPFB_Utils::get_context_source_url( 'viewcontent', $product );
				self::queue_server_event(
					'ViewContent',
					DPFB_Utils::get_request_event_id( 'viewcontent' ),
					DPFB_Utils::build_product_custom_data( $product, 1 ),
					DPFB_Utils::build_request_user_data( array(), $view_source_url ),
					$view_source_url,
					$event_time,
					0,
					'viewcontent'
				);
			}
		}

		if (
			DPFB_Utils::setting( 'enable_initiatecheckout', 1 )
			&& ( ! DPFB_Utils::setting( 'enable_browser', 1 ) || DPFB_Utils::is_checkout_block() )
			&& function_exists( 'is_checkout' )
			&& is_checkout()
			&& ! is_order_received_page()
			&& function_exists( 'WC' )
			&& WC()
			&& WC()->cart
		) {
			$cart_data           = DPFB_Utils::build_cart_custom_data();
			$checkout_source_url = DPFB_Utils::get_context_source_url( 'initiatecheckout' );

			if ( ! empty( $cart_data ) ) {
				self::queue_server_event(
					'InitiateCheckout',
					DPFB_Utils::get_request_event_id( 'initiatecheckout' ),
					$cart_data,
					DPFB_Utils::build_request_user_data( array(), $checkout_source_url ),
					$checkout_source_url,
					$event_time,
					0,
					'initiatecheckout'
				);
			}
		}
	}

	/**
	 * Handle AJAX server tracking requests.
	 *
	 * @return void
	 */
	public static function ajax_track_event(): void {
		if ( ! self::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Conversion API is disabled.', 'devpsoft-fb-pixel-capi' ) ), 400 );
		}

		check_ajax_referer( 'dpfb_track_event', 'nonce' );

		$event_name_raw  = DPFB_Utils::get_post_value( 'event_name', '' );
		$event_id_raw    = DPFB_Utils::get_post_value( 'event_id', '' );
		$source_url_raw  = DPFB_Utils::get_post_value( 'event_source_url', DPFB_Utils::get_current_url() );
		$event_time_raw  = DPFB_Utils::get_post_value( 'event_time', time() );
		$order_id_raw    = DPFB_Utils::get_post_value( 'order_id', 0 );
		$custom_data_raw = DPFB_Utils::get_post_value( 'custom_data', array() );
		$user_data_raw   = DPFB_Utils::get_post_value( 'user_data', array() );

		$event_name = DPFB_Utils::sanitize_event_name( $event_name_raw );
		$event_id   = sanitize_text_field( $event_id_raw );
		$source_url = esc_url_raw( $source_url_raw );
		$event_time = absint( $event_time_raw );
		$order_id   = absint( $order_id_raw );

		if ( ! $event_name || ! $event_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing event name or event ID.', 'devpsoft-fb-pixel-capi' ) ), 400 );
		}

		$custom_data = DPFB_Utils::sanitize_custom_data_input( $custom_data_raw );
		$raw_user    = DPFB_Utils::sanitize_user_data_input( $user_data_raw );

		if ( 'PageView' === $event_name ) {
			$user_data = DPFB_Utils::build_pageview_user_data( $source_url, $raw_user );
		} elseif ( 'Purchase' === $event_name && $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! self::is_valid_purchase_order( $order ) ) {
				wp_send_json_error( array( 'message' => __( 'Purchase order is not eligible for tracking.', 'devpsoft-fb-pixel-capi' ) ), 400 );
			}

			if ( get_post_meta( $order_id, '_dpfb_purchase_sent', true ) ) {
				wp_send_json_success( array( 'message' => __( 'Purchase already tracked.', 'devpsoft-fb-pixel-capi' ) ) );
			}

			$custom_data = DPFB_Utils::build_order_custom_data( $order );
			$user_data   = DPFB_Utils::build_order_user_data( $order );
		} else {
			$user_data = DPFB_Utils::build_request_user_data( $raw_user, $source_url );
		}

		$result = self::send_event( $event_name, $event_id, $custom_data, $user_data, $source_url, $event_time, $order_id, 'ajax', true, $raw_user );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 500 );
	}

	/**
	 * Queue a server event.
	 *
	 * @param string   $event_name Event name.
	 * @param string   $event_id   Event ID.
	 * @param array    $custom_data Custom data.
	 * @param array    $user_data   User data.
	 * @param string   $source_url  Source URL.
	 * @param int|null $event_time  Event time.
	 * @param int      $order_id    Order ID.
	 * @param string   $transport   Transport label.
	 * @return bool
	 */
	public static function queue_server_event( string $event_name, string $event_id, array $custom_data, array $user_data, string $source_url, ?int $event_time = null, int $order_id = 0, string $transport = 'server-queued' ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( DPFB_Utils::is_cache_preload_request() ) {
			return false;
		}

		if ( ! DPFB_Utils::is_consent_allowed( $order_id ) ) {
			return false;
		}

		$event_name = DPFB_Utils::sanitize_event_name( $event_name );
		$event_id   = sanitize_text_field( (string) $event_id );

		if ( ! $event_name || ! $event_id ) {
			return false;
		}

		if ( 'Purchase' === $event_name && $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! self::is_valid_purchase_order( $order ) || get_post_meta( $order_id, '_dpfb_purchase_sent', true ) ) {
				return false;
			}
		}

		$normalized_source_url = self::normalize_event_source_url( $event_name, $source_url, absint( $order_id ) );

		$event = array(
			'event_name'       => $event_name,
			'event_time'       => $event_time ? absint( $event_time ) : time(),
			'event_id'         => $event_id,
			'event_source_url' => $normalized_source_url,
			'action_source'    => 'website',
			'user_data'        => array_filter( is_array( $user_data ) ? $user_data : array() ),
		);

		$custom_data = DPFB_Utils::sanitize_custom_data_input( $custom_data );
		$custom_data = DPFB_Utils::append_utm_params( $custom_data, $normalized_source_url );
		if ( $normalized_source_url !== '' && empty( $custom_data['event_url'] ) ) {
			$custom_data['event_url'] = $normalized_source_url;
		}
		if ( ! empty( $custom_data ) ) {
			$event['custom_data'] = $custom_data;
		}

		$event = self::apply_ldu_fields( $event, absint( $order_id ) );

		return self::queue_event( $event, absint( $order_id ), $transport, 0, '' );
	}

	/**
	 * Send a server event.
	 *
	 * @param string   $event_name      Event name.
	 * @param string   $event_id        Event ID.
	 * @param array    $custom_data     Custom data.
	 * @param array    $user_data       User data.
	 * @param string   $source_url      Source URL.
	 * @param int|null $event_time      Event time.
	 * @param int      $order_id        Order ID.
	 * @param string   $transport       Transport label.
	 * @param bool     $allow_queue     Allow queueing.
	 * @param array    $raw_user_data   Raw user data.
	 * @param int      $context_user_id Context user ID.
	 * @return array<string, mixed>
	 */
	public static function send_event( string $event_name, string $event_id, array $custom_data, array $user_data, string $source_url, ?int $event_time = null, int $order_id = 0, string $transport = 'server', bool $allow_queue = true, array $raw_user_data = array(), int $context_user_id = 0 ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'success' => false,
				'message' => __( 'Conversion API is not configured.', 'devpsoft-fb-pixel-capi' ),
			);
		}

		if ( DPFB_Utils::is_cache_preload_request() ) {
			return array(
				'success' => true,
				'skipped' => true,
				'message' => __( 'Cache preload request skipped.', 'devpsoft-fb-pixel-capi' ),
			);
		}

		if ( ! DPFB_Utils::is_consent_allowed( $order_id ) ) {
			return array(
				'success' => true,
				'skipped' => true,
				'message' => __( 'Consent not granted. Event skipped.', 'devpsoft-fb-pixel-capi' ),
			);
		}

		$event_name = DPFB_Utils::sanitize_event_name( $event_name );
		$event_id   = sanitize_text_field( (string) $event_id );

		if ( ! $event_name || ! $event_id ) {
			return array(
				'success' => false,
				'message' => __( 'Event name or event ID is invalid.', 'devpsoft-fb-pixel-capi' ),
			);
		}

		$normalized_source_url = self::normalize_event_source_url( $event_name, $source_url, absint( $order_id ) );

		$event = array(
			'event_name'       => $event_name,
			'event_time'       => $event_time ? absint( $event_time ) : time(),
			'event_id'         => $event_id,
			'event_source_url' => $normalized_source_url,
			'action_source'    => 'website',
			'user_data'        => array_filter( is_array( $user_data ) ? $user_data : array() ),
		);

		$custom_data = DPFB_Utils::sanitize_custom_data_input( $custom_data );
		$custom_data = DPFB_Utils::append_utm_params( $custom_data, $normalized_source_url );
		if ( $normalized_source_url !== '' && empty( $custom_data['event_url'] ) ) {
			$custom_data['event_url'] = $normalized_source_url;
		}
		if ( ! empty( $custom_data ) ) {
			$event['custom_data'] = $custom_data;
		}

		$event = self::apply_ldu_fields( $event, absint( $order_id ) );

		return self::dispatch_event( $event, absint( $order_id ), $transport, $allow_queue, 0, $raw_user_data, absint( $context_user_id ) );
	}

	/**
	 * Skip Purchase events that are no longer valid.
	 *
	 * @param array  $event     Event payload.
	 * @param int    $order_id  Order ID.
	 * @param string $transport Transport label.
	 * @param int    $attempt   Attempt count.
	 * @return array<string, mixed>|null
	 */
	private static function maybe_short_circuit_purchase_event( array $event, int $order_id, string $transport, int $attempt ): ?array {
		if ( 'Purchase' !== $event['event_name'] || ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! self::is_valid_purchase_order( $order ) ) {
			$message = __( 'Purchase order is no longer eligible for tracking.', 'devpsoft-fb-pixel-capi' );
			DPFB_Utils::log_event( $event['event_name'], $event['event_id'], 'skipped', $message, $transport, self::build_log_context( $event, $order_id, $attempt ) );

			return array(
				'success' => true,
				'skipped' => true,
				'message' => $message,
			);
		}

		if ( get_post_meta( $order_id, '_dpfb_purchase_sent', true ) ) {
			$message = __( 'Purchase already tracked.', 'devpsoft-fb-pixel-capi' );
			DPFB_Utils::log_event( $event['event_name'], $event['event_id'], 'skipped', $message, $transport, self::build_log_context( $event, $order_id, $attempt ) );

			return array(
				'success' => true,
				'skipped' => true,
				'message' => $message,
			);
		}

		return null;
	}

	/**
	 * Apply LDU fields when enabled.
	 *
	 * @param array $event    Event payload.
	 * @param int   $order_id Order ID.
	 * @return array
	 */
	private static function apply_ldu_fields( array $event, int $order_id ): array {
		$order = null;
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
		}

		$ldu = DPFB_Utils::get_ldu_context( $order );
		if ( empty( $ldu['enabled'] ) ) {
			return $event;
		}

		$event['data_processing_options']         = array( 'LDU' );
		$event['data_processing_options_country'] = 0;
		$event['data_processing_options_state']   = 0;

		return $event;
	}

	/**
	 * Resolve Meta error message.
	 *
	 * @param mixed $body_raw Raw body.
	 * @param mixed $decoded  Decoded body.
	 * @return string
	 */
	private static function get_meta_error_message( $body_raw, $decoded ): string {
		if ( is_array( $decoded ) && ! empty( $decoded['error'] ) ) {
			$error = $decoded['error'];
			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				return sanitize_text_field( (string) $error['message'] );
			}
		}

		if ( is_scalar( $body_raw ) && $body_raw !== '' ) {
			return sanitize_text_field( (string) $body_raw );
		}

		return __( 'Meta returned an empty response.', 'devpsoft-fb-pixel-capi' );
	}

	/**
	 * Build log context for stored events.
	 *
	 * @param array $event    Event payload.
	 * @param int   $order_id Order ID.
	 * @param int   $attempt  Attempt count.
	 * @param array $extra    Extra context.
	 * @return array<string, string>
	 */
	private static function build_log_context( array $event, int $order_id, int $attempt, array $extra = array() ): array {
		$content_ids = array();
		if ( ! empty( $event['custom_data']['content_ids'] ) && is_array( $event['custom_data']['content_ids'] ) ) {
			$content_ids = array_values( array_filter( array_map( 'sanitize_text_field', $event['custom_data']['content_ids'] ) ) );
		}

		$context = array(
			'event_name'        => isset( $event['event_name'] ) ? (string) $event['event_name'] : '',
			'event_id'          => isset( $event['event_id'] ) ? (string) $event['event_id'] : '',
			'source'            => 'server',
			'attempt'           => (string) $attempt,
			'order_id'          => (string) absint( $order_id ),
			'event_time'        => isset( $event['event_time'] ) ? (string) absint( $event['event_time'] ) : '',
			'source_url'        => isset( $event['event_source_url'] ) ? (string) $event['event_source_url'] : '',
			'event_source_url'  => isset( $event['event_source_url'] ) ? (string) $event['event_source_url'] : '',
			'action_source'     => isset( $event['action_source'] ) ? (string) $event['action_source'] : '',
			'order_status'      => '',
			'queue_retry_time'  => '',
			'fbp_present'       => ! empty( $event['user_data']['fbp'] ) ? 'Yes' : 'No',
			'fbc_present'       => ! empty( $event['user_data']['fbc'] ) ? 'Yes' : 'No',
			'currency'          => ! empty( $event['custom_data']['currency'] ) ? DPFB_Utils::normalize_currency( (string) $event['custom_data']['currency'], '' ) : '',
			'value'             => isset( $event['custom_data']['value'] ) ? (string) (float) $event['custom_data']['value'] : '',
			'content_ids'       => ! empty( $content_ids ) ? implode( ',', $content_ids ) : '',
			'dedup_pair_status' => isset( $event['event_id'], $event['event_name'] ) && $event['event_id'] !== '' && $event['event_name'] !== '' ? 'ready' : 'missing',
		);

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$context['order_status'] = (string) $order->get_status();
			}
		}

		return array_merge( $context, is_array( $extra ) ? $extra : array() );
	}

	/**
	 * Extract Meta error context.
	 *
	 * @param mixed $decoded Decoded response.
	 * @return array<string, string>
	 */
	private static function get_meta_error_context( $decoded ): array {
		if ( ! is_array( $decoded ) || empty( $decoded['error'] ) || ! is_array( $decoded['error'] ) ) {
			return array();
		}

		$error = $decoded['error'];

		return array(
			'meta_error_type'    => ! empty( $error['type'] ) ? sanitize_text_field( (string) $error['type'] ) : '',
			'meta_error_code'    => isset( $error['code'] ) ? (string) absint( $error['code'] ) : '',
			'meta_error_subcode' => isset( $error['error_subcode'] ) ? (string) absint( $error['error_subcode'] ) : '',
			'fbtrace_id'         => ! empty( $error['fbtrace_id'] ) ? sanitize_text_field( (string) $error['fbtrace_id'] ) : '',
		);
	}

	/**
	 * Check if the SDK classes are available.
	 *
	 * @return bool
	 */
	private static function sdk_available(): bool {
		return class_exists( '\\FacebookAds\\Object\\ServerSide\\EventRequest' )
			&& class_exists( '\\FacebookAds\\Object\\ServerSide\\UserData' )
			&& class_exists( '\\FacebookAds\\Object\\ServerSide\\CustomData' )
			&& class_exists( '\\FacebookAds\\Object\\ServerSide\\Content' );
	}

	/**
	 * Build SDK user data.
	 *
	 * @param string $event_name      Event name.
	 * @param string $source_url      Source URL.
	 * @param int    $order_id        Order ID.
	 * @param array  $raw_user_data   Raw user data.
	 * @param int    $context_user_id Context user ID.
	 * @return \FacebookAds\Object\ServerSide\UserData
	 */
	private static function build_sdk_user_data( string $event_name, string $source_url, int $order_id, array $raw_user_data = array(), int $context_user_id = 0 ) {
		$raw_user_data   = is_array( $raw_user_data ) ? $raw_user_data : array();
		$context_user_id = absint( $context_user_id );

		if ( 'Purchase' === $event_name && $order_id ) {
			$order = wc_get_order( $order_id );
			$raw   = DPFB_Utils::build_order_user_data_raw( $order );
		} elseif ( 'CompleteRegistration' === $event_name && $context_user_id ) {
			$raw = DPFB_Utils::build_registered_user_data_raw( $context_user_id, $source_url );
		} elseif ( 'login' === $event_name && $context_user_id ) {
			$raw = DPFB_Utils::build_registered_user_data_raw( $context_user_id, $source_url );
		} elseif ( 'PageView' === $event_name ) {
			$raw = DPFB_Utils::build_pageview_user_data_raw( $source_url, $raw_user_data );
		} else {
			$raw = DPFB_Utils::build_request_user_data_raw( $raw_user_data, $source_url );
		}

		$user_data = new \FacebookAds\Object\ServerSide\UserData();

		if ( ! empty( $raw['email'] ) ) {
			$user_data->setEmail( $raw['email'] );
		}
		if ( ! empty( $raw['phone'] ) ) {
			$user_data->setPhone( $raw['phone'] );
		}
		if ( ! empty( $raw['first_name'] ) ) {
			$user_data->setFirstName( $raw['first_name'] );
		}
		if ( ! empty( $raw['last_name'] ) ) {
			$user_data->setLastName( $raw['last_name'] );
		}
		if ( ! empty( $raw['city'] ) ) {
			$user_data->setCity( $raw['city'] );
		}
		if ( ! empty( $raw['state'] ) ) {
			$user_data->setState( $raw['state'] );
		}
		if ( ! empty( $raw['zip'] ) ) {
			$user_data->setZipCode( $raw['zip'] );
		}
		if ( ! empty( $raw['country'] ) ) {
			$user_data->setCountryCode( $raw['country'] );
		}
		if ( ! empty( $raw['external_id'] ) ) {
			$user_data->setExternalId( $raw['external_id'] );
		}
		if ( ! empty( $raw['client_ip_address'] ) ) {
			$user_data->setClientIpAddress( $raw['client_ip_address'] );
		}
		if ( ! empty( $raw['client_user_agent'] ) ) {
			$user_data->setClientUserAgent( $raw['client_user_agent'] );
		}
		if ( ! empty( $raw['fbp'] ) ) {
			$user_data->setFbp( $raw['fbp'] );
		}
		if ( ! empty( $raw['fbc'] ) ) {
			$user_data->setFbc( $raw['fbc'] );
		}
		if ( ! empty( $raw['fb_login_id'] ) ) {
			$user_data->setFbLoginId( $raw['fb_login_id'] );
		}

		return $user_data;
	}

	/**
	 * Build SDK custom data.
	 *
	 * @param array $custom_data Custom data.
	 * @param int   $order_id    Order ID.
	 * @return \FacebookAds\Object\ServerSide\CustomData|null
	 */
	private static function build_sdk_custom_data( array $custom_data, int $order_id ) {
		$custom_data = is_array( $custom_data ) ? $custom_data : array();
		/** Custom data payload. @var array<string, mixed> $custom_data */
		$custom     = new \FacebookAds\Object\ServerSide\CustomData();
		$has_custom = false;

		if ( isset( $custom_data['value'] ) ) {
			$custom->setValue( (float) $custom_data['value'] );
			$has_custom = true;
		}
		if ( ! empty( $custom_data['currency'] ) ) {
			$currency = DPFB_Utils::normalize_currency( (string) $custom_data['currency'], DPFB_Utils::get_currency() );
			if ( $currency !== '' ) {
				$custom->setCurrency( $currency );
				$has_custom = true;
			}
		}
		if ( ! empty( $custom_data['content_name'] ) ) {
			$custom->setContentName( (string) $custom_data['content_name'] );
			$has_custom = true;
		}
		if ( ! empty( $custom_data['content_category'] ) ) {
			$custom->setContentCategory( (string) $custom_data['content_category'] );
			$has_custom = true;
		}
		if ( ! empty( $custom_data['content_ids'] ) && is_array( $custom_data['content_ids'] ) ) {
			$custom->setContentIds( array_values( array_filter( $custom_data['content_ids'] ) ) );
			$has_custom = true;
		}
		if ( ! empty( $custom_data['content_type'] ) ) {
			$custom->setContentType( (string) $custom_data['content_type'] );
			$has_custom = true;
		}
		if ( ! empty( $custom_data['num_items'] ) ) {
			$custom->setNumItems( absint( $custom_data['num_items'] ) );
			$has_custom = true;
		}
		if ( ! empty( $custom_data['status'] ) ) {
			$custom->setStatus( (string) $custom_data['status'] );
			$has_custom = true;
		}
		$predicted_ltv = null;
		if ( isset( $custom_data['predicted_ltv'] ) ) {
			$predicted_ltv = (float) $custom_data['predicted_ltv'];
			unset( $custom_data['predicted_ltv'] );
		}
		if ( null !== $predicted_ltv && method_exists( $custom, 'setPredictedLtv' ) ) {
			$custom->setPredictedLtv( $predicted_ltv );
			$has_custom = true;
		}
		if ( $order_id ) {
			$custom->setOrderId( (string) absint( $order_id ) );
			$has_custom = true;
		}

		if ( ! empty( $custom_data['contents'] ) && is_array( $custom_data['contents'] ) ) {
			$contents = array();
			foreach ( $custom_data['contents'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$content = new \FacebookAds\Object\ServerSide\Content();
				if ( isset( $item['id'] ) ) {
					$content->setProductId( (string) $item['id'] );
				}
				if ( isset( $item['quantity'] ) ) {
					$content->setQuantity( (int) $item['quantity'] );
				}
				if ( isset( $item['item_price'] ) ) {
					$content->setItemPrice( (float) $item['item_price'] );
				}
				$contents[] = $content;
			}
			if ( ! empty( $contents ) ) {
				$custom->setContents( $contents );
				$has_custom = true;
			}
		}

		/** Custom properties. @var array<string, mixed> $custom_properties */
		$custom_properties = array();
		if ( ! empty( $custom_data['tags'] ) ) {
			$custom_properties['tags'] = $custom_data['tags'];
		}
		if ( ! empty( $custom_data['event_url'] ) ) {
			$custom_properties['event_url'] = $custom_data['event_url'];
		}
		$extra_keys = array(
			'event_action',
			'event_trigger',
			'page_title',
			'download_name',
			'download_type',
			'form_class',
			'form_submit_label',
			'order_status',
			'payment_method',
			'shipping',
			'shipping_cost',
			'shipping_tax',
			'coupon_used',
			'coupon_name',
			'transactions_count',
			'average_order',
		);
		foreach ( $extra_keys as $key ) {
			if ( isset( $custom_data[ $key ] ) && $custom_data[ $key ] !== '' ) {
				$custom_properties[ $key ] = $custom_data[ $key ];
			}
		}
		$reserved_keys = array(
			'value',
			'currency',
			'content_name',
			'content_category',
			'content_ids',
			'content_type',
			'num_items',
			'status',
			'contents',
			'order_id',
			'predicted_ltv',
		);
		if ( ! empty( $custom_properties ) ) {
			$custom_properties = array_diff_key( $custom_properties, array_flip( $reserved_keys ) );
		}
		if ( ! empty( $custom_properties ) ) {
			$custom->setCustomProperties( $custom_properties );
			$has_custom = true;
		}

		return $has_custom ? $custom : null;
	}

	/**
	 * Dispatch event via Meta SDK.
	 *
	 * @param array  $event           Event payload.
	 * @param int    $order_id        Order ID.
	 * @param string $transport       Transport label.
	 * @param bool   $allow_queue     Allow queueing.
	 * @param int    $attempt         Attempt count.
	 * @param array  $raw_user_data   Raw user data.
	 * @param int    $context_user_id Context user ID.
	 * @return array<string, mixed>
	 */
	private static function dispatch_event_sdk( array $event, int $order_id, string $transport, bool $allow_queue, int $attempt, array $raw_user_data = array(), int $context_user_id = 0 ): array {
		$pixel_id     = DPFB_Utils::setting( 'pixel_id', '' );
		$access_token = DPFB_Utils::setting( 'access_token', '' );

		try {
			self::ensure_sdk_api( $access_token );
			\FacebookAds\Object\ServerSide\HttpServiceClientConfig::getInstance()->setAccessToken( $access_token );

			$user_data   = self::build_sdk_user_data( $event['event_name'], $event['event_source_url'], $order_id, $raw_user_data, $context_user_id );
			$custom_data = self::build_sdk_custom_data( isset( $event['custom_data'] ) ? $event['custom_data'] : array(), $order_id );

			$server_event = new \FacebookAds\Object\ServerSide\Event();
			$server_event->setEventName( (string) $event['event_name'] );
			$server_event->setEventTime( (int) $event['event_time'] );
			if ( ! empty( $event['event_source_url'] ) ) {
				$server_event->setEventSourceUrl( (string) $event['event_source_url'] );
			}
			$server_event->setEventId( (string) $event['event_id'] );
			$server_event->setActionSource( (string) $event['action_source'] );
			$server_event->setUserData( $user_data );
			if ( $custom_data instanceof \FacebookAds\Object\ServerSide\CustomData ) {
				$server_event->setCustomData( $custom_data );
			}
			if ( ! empty( $event['data_processing_options'] ) && is_array( $event['data_processing_options'] ) ) {
				$server_event->setDataProcessingOptions( $event['data_processing_options'] );
				$server_event->setDataProcessingOptionsCountry( isset( $event['data_processing_options_country'] ) ? (int) $event['data_processing_options_country'] : 0 );
				$server_event->setDataProcessingOptionsState( isset( $event['data_processing_options_state'] ) ? (int) $event['data_processing_options_state'] : 0 );
			}

			$request = new \FacebookAds\Object\ServerSide\EventRequest( $pixel_id );
			$request->setEvents( array( $server_event ) );
			$request->setPartnerAgent( DPFB_PRODUCT_CODE );

			if ( DPFB_Utils::setting( 'enable_test_mode', 0 ) && DPFB_Utils::setting( 'test_event_code', '' ) !== '' ) {
				$request->setTestEventCode( DPFB_Utils::setting( 'test_event_code', '' ) );
			}

			$response        = $request->execute();
			$events_received = is_object( $response ) && method_exists( $response, 'getEventsReceived' ) ? $response->getEventsReceived() : '';
			$fbtrace_id      = is_object( $response ) && method_exists( $response, 'getFbTraceId' ) ? $response->getFbTraceId() : '';

			DPFB_Utils::log_event(
				$event['event_name'],
				$event['event_id'],
				'success',
				(string) $response,
				$transport,
				self::build_log_context(
					$event,
					$order_id,
					$attempt,
					array(
						'events_received' => (string) $events_received,
						'fbtrace_id'      => $fbtrace_id ? (string) $fbtrace_id : '',
					)
				)
			);

			if ( 'Purchase' === $event['event_name'] && $order_id ) {
				update_post_meta( $order_id, '_dpfb_purchase_sent', 1 );
				update_post_meta( $order_id, '_dpfb_purchase_sent_at', current_time( 'mysql' ) );
			}

			return array(
				'success'         => true,
				'message'         => __( 'Events sent successfully.', 'devpsoft-fb-pixel-capi' ),
				'events_received' => $events_received,
			);
		} catch ( \Exception $e ) {
			$message = $e->getMessage();
			DPFB_Utils::log_event( $event['event_name'], $event['event_id'], 'failed', $message, $transport, self::build_log_context( $event, $order_id, $attempt ) );

			if ( $allow_queue ) {
				if ( self::queue_event( $event, $order_id, $transport, $attempt + 1, $message ) ) {
					return self::build_queued_result( $message );
				}
			}

			return array(
				'success' => false,
				'message' => $message,
			);
		}
	}

	/**
	 * Ensure SDK API is initialized.
	 *
	 * @param string $access_token Access token.
	 * @return void
	 */
	private static function ensure_sdk_api( string $access_token ): void {
		if ( ! class_exists( '\FacebookAds\Api' ) ) {
			return;
		}

		if ( method_exists( '\FacebookAds\Api', 'getInstance' ) ) {
			$instance = call_user_func( array( '\FacebookAds\Api', 'getInstance' ) );
			if ( $instance ) {
				return;
			}
		}

		if ( method_exists( '\FacebookAds\Api', 'init' ) ) {
			\FacebookAds\Api::init( null, null, $access_token );
		}
	}

	/**
	 * Dispatch a server event.
	 *
	 * @param array  $event           Event payload.
	 * @param int    $order_id        Order ID.
	 * @param string $transport       Transport label.
	 * @param bool   $allow_queue     Allow queueing.
	 * @param int    $attempt         Attempt count.
	 * @param array  $raw_user_data   Raw user data.
	 * @param int    $context_user_id Context user ID.
	 * @return array<string, mixed>
	 */
	private static function dispatch_event( array $event, int $order_id, string $transport, bool $allow_queue, int $attempt, array $raw_user_data = array(), int $context_user_id = 0 ): array {
		$pixel_id     = DPFB_Utils::setting( 'pixel_id', '' );
		$access_token = DPFB_Utils::setting( 'access_token', '' );
		$event        = self::normalize_event_payload( $event );

		if ( empty( $event['event_name'] ) || empty( $event['event_id'] ) ) {
			DPFB_Utils::log_event(
				isset( $event['event_name'] ) ? (string) $event['event_name'] : 'Unknown',
				isset( $event['event_id'] ) ? (string) $event['event_id'] : '',
				'skipped',
				__( 'Server event skipped because event_id is missing.', 'devpsoft-fb-pixel-capi' ),
				$transport,
				self::build_log_context( $event, $order_id, $attempt )
			);

			return array(
				'success' => true,
				'skipped' => true,
				'message' => __( 'Server event skipped because event_id is missing.', 'devpsoft-fb-pixel-capi' ),
			);
		}

		$purchase_guard = self::maybe_short_circuit_purchase_event( $event, $order_id, $transport, $attempt );

		if ( is_array( $purchase_guard ) ) {
			return $purchase_guard;
		}

		if ( self::sdk_available() && ! self::event_has_currency( $event ) ) {
			return self::dispatch_event_sdk( $event, $order_id, $transport, $allow_queue, $attempt, $raw_user_data, $context_user_id );
		}

		$body = array(
			'data'         => array( $event ),
			'access_token' => $access_token,
		);

		if ( DPFB_Utils::setting( 'enable_test_mode', 0 ) && DPFB_Utils::setting( 'test_event_code', '' ) !== '' ) {
			$body['test_event_code'] = DPFB_Utils::setting( 'test_event_code', '' );
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( ! empty( $event['user_data']['fbp'] ) ) {
			$headers['X-FB-CK-FBP'] = DPFB_Utils::sanitize_meta_click_id( $event['user_data']['fbp'] );
		}

		if ( ! empty( $event['user_data']['fbc'] ) ) {
			$headers['X-FB-CK-FBC'] = DPFB_Utils::sanitize_meta_fbc( $event['user_data']['fbc'] );
		}

		$response = wp_remote_post(
			'https://graph.facebook.com/' . self::API_VERSION . '/' . rawurlencode( $pixel_id ) . '/events',
			array(
				'method'      => 'POST',
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			DPFB_Utils::log_event(
				$event['event_name'],
				$event['event_id'],
				'failed',
				$message,
				$transport,
				self::build_log_context(
					$event,
					$order_id,
					$attempt,
					array(
						'wp_error_code' => sanitize_text_field( (string) $response->get_error_code() ),
					)
				)
			);

			if ( $allow_queue ) {
				if ( self::queue_event( $event, $order_id, $transport, $attempt + 1, $message ) ) {
					return self::build_queued_result( $message );
				}
			}

			return array(
				'success' => false,
				'message' => $message,
			);
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $body_raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = 'HTTP ' . $code . ': ' . self::get_meta_error_message( $body_raw, $decoded );
			DPFB_Utils::log_event(
				$event['event_name'],
				$event['event_id'],
				'failed',
				$message,
				$transport,
				self::build_log_context(
					$event,
					$order_id,
					$attempt,
					array(
						'http_code' => (string) $code,
					) + self::get_meta_error_context( $decoded )
				)
			);

			if ( $allow_queue ) {
				if ( self::queue_event( $event, $order_id, $transport, $attempt + 1, $message ) ) {
					return self::build_queued_result( $message );
				}
			}

			return array(
				'success' => false,
				'message' => $message,
			);
		}

		if ( ! is_array( $decoded ) ) {
			$message = __( 'Meta returned invalid JSON.', 'devpsoft-fb-pixel-capi' );
			DPFB_Utils::log_event(
				$event['event_name'],
				$event['event_id'],
				'failed',
				$message,
				$transport,
				self::build_log_context(
					$event,
					$order_id,
					$attempt,
					array(
						'http_code' => (string) $code,
					)
				)
			);

			if ( $allow_queue ) {
				if ( self::queue_event( $event, $order_id, $transport, $attempt + 1, $message ) ) {
					return self::build_queued_result( $message );
				}
			}

			return array(
				'success' => false,
				'message' => $message,
			);
		}

		if ( ! empty( $decoded['error'] ) ) {
			$message = self::get_meta_error_message( $body_raw, $decoded );
			DPFB_Utils::log_event(
				$event['event_name'],
				$event['event_id'],
				'failed',
				$message,
				$transport,
				self::build_log_context(
					$event,
					$order_id,
					$attempt,
					array(
						'http_code' => (string) $code,
					) + self::get_meta_error_context( $decoded )
				)
			);

			if ( $allow_queue ) {
				if ( self::queue_event( $event, $order_id, $transport, $attempt + 1, $message ) ) {
					return self::build_queued_result( $message );
				}
			}

			return array(
				'success' => false,
				'message' => $message,
			);
		}

		if ( isset( $decoded['events_received'] ) && absint( $decoded['events_received'] ) < 1 ) {
			$message = __( 'Meta accepted the request but did not confirm any events received.', 'devpsoft-fb-pixel-capi' );
			DPFB_Utils::log_event(
				$event['event_name'],
				$event['event_id'],
				'failed',
				$message,
				$transport,
				self::build_log_context(
					$event,
					$order_id,
					$attempt,
					array(
						'http_code'       => (string) $code,
						'events_received' => isset( $decoded['events_received'] ) ? (string) absint( $decoded['events_received'] ) : '0',
					)
				)
			);

			if ( $allow_queue ) {
				if ( self::queue_event( $event, $order_id, $transport, $attempt + 1, $message ) ) {
					return self::build_queued_result( $message );
				}
			}

			return array(
				'success' => false,
				'message' => $message,
			);
		}

		DPFB_Utils::log_event(
			$event['event_name'],
			$event['event_id'],
			'success',
			$body_raw,
			$transport,
			self::build_log_context(
				$event,
				$order_id,
				$attempt,
				array(
					'http_code'       => (string) $code,
					'events_received' => isset( $decoded['events_received'] ) ? (string) absint( $decoded['events_received'] ) : '',
				)
			)
		);

		if ( 'Purchase' === $event['event_name'] && $order_id ) {
			update_post_meta( $order_id, '_dpfb_purchase_sent', 1 );
			update_post_meta( $order_id, '_dpfb_purchase_sent_at', current_time( 'mysql' ) );
		}

		return array(
			'success'  => true,
			'response' => $body_raw,
		);
	}

	/**
	 * Normalize event payload before SDK/API dispatch.
	 *
	 * @param array $event Event payload.
	 * @return array<string, mixed>
	 */
	private static function normalize_event_payload( array $event ): array {
		$event['event_name']       = ! empty( $event['event_name'] ) ? DPFB_Utils::sanitize_event_name( (string) $event['event_name'] ) : '';
		$event['event_id']         = ! empty( $event['event_id'] ) ? sanitize_text_field( (string) $event['event_id'] ) : '';
		$event['event_time']       = ! empty( $event['event_time'] ) ? absint( $event['event_time'] ) : time();
		$event['event_source_url'] = ! empty( $event['event_source_url'] ) ? esc_url_raw( (string) $event['event_source_url'] ) : '';
		$event['action_source']    = ! empty( $event['action_source'] ) ? sanitize_text_field( (string) $event['action_source'] ) : 'website';

		if ( ! empty( $event['custom_data'] ) && is_array( $event['custom_data'] ) ) {
			$event['custom_data'] = DPFB_Utils::sanitize_custom_data_input( $event['custom_data'] );
		}

		return $event;
	}

	/**
	 * Check whether event contains a currency field.
	 *
	 * @param array $event Event payload.
	 * @return bool
	 */
	private static function event_has_currency( array $event ): bool {
		return ! empty( $event['custom_data']['currency'] );
	}

	/**
	 * Build queued response payload.
	 *
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private static function build_queued_result( string $message ): array {
		return array(
			'success' => true,
			'queued'  => true,
			'message' => $message,
		);
	}

	/**
	 * Queue event for retry.
	 *
	 * @param array  $event      Event payload.
	 * @param int    $order_id   Order ID.
	 * @param string $transport  Transport label.
	 * @param int    $attempt    Attempt count.
	 * @param string $last_error Last error message.
	 * @return bool
	 */
	private static function queue_event( array $event, int $order_id, string $transport, int $attempt, string $last_error ): bool {
		$event = self::normalize_event_payload( $event );

		if ( empty( $event['event_name'] ) || empty( $event['event_id'] ) ) {
			DPFB_Utils::log_event(
				isset( $event['event_name'] ) ? (string) $event['event_name'] : 'Unknown',
				isset( $event['event_id'] ) ? (string) $event['event_id'] : '',
				'skipped',
				__( 'Server event was not queued because event_id is missing.', 'devpsoft-fb-pixel-capi' ),
				$transport,
				self::build_log_context( $event, $order_id, $attempt )
			);

			return false;
		}

		if ( $attempt > self::MAX_RETRIES ) {
			DPFB_Utils::log_event( $event['event_name'], $event['event_id'], 'failed-final', $last_error, 'queue', self::build_log_context( $event, $order_id, $attempt ) );
			return false;
		}

		$queue = get_option( 'dpfb_event_queue', array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		foreach ( $queue as $item ) {
			if (
				isset( $item['event']['event_name'], $item['event']['event_id'] )
				&& $item['event']['event_name'] === $event['event_name']
				&& $item['event']['event_id'] === $event['event_id']
			) {
				return true;
			}
		}

		$queue[] = array(
			'event'      => $event,
			'order_id'   => absint( $order_id ),
			'transport'  => sanitize_text_field( $transport ),
			'attempt'    => absint( $attempt ),
			'next_retry' => $attempt > 0 ? time() + min( 1800, 60 * max( 1, $attempt ) ) : time(),
			'last_error' => sanitize_text_field( $last_error ),
		);

		update_option( 'dpfb_event_queue', $queue, false );
		DPFB_Utils::log_event(
			$event['event_name'],
			$event['event_id'],
			'queued',
			__( 'Queued for async delivery.', 'devpsoft-fb-pixel-capi' ),
			$transport,
			self::build_log_context(
				$event,
				$order_id,
				$attempt,
				array(
					'queue_retry_time' => wp_date( 'Y-m-d H:i:s', $queue[ count( $queue ) - 1 ]['next_retry'] ),
					'last_error'       => sanitize_text_field( (string) $last_error ),
				)
			)
		);
		self::schedule_queue_processor( 1 );

		return true;
	}

	/**
	 * Schedule queue processing.
	 *
	 * @param int $delay Delay in seconds.
	 * @return void
	 */
	private static function schedule_queue_processor( int $delay = 90 ): void {
		if ( ! wp_next_scheduled( 'dpfb_process_event_queue' ) ) {
			wp_schedule_single_event( time() + max( 1, absint( $delay ) ), 'dpfb_process_event_queue' );
		}
	}

	/**
	 * Process queued events.
	 *
	 * @return void
	 */
	public static function process_queue(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$queue = get_option( 'dpfb_event_queue', array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		$remaining = array();

		foreach ( $queue as $item ) {
			if ( empty( $item['event'] ) || ! is_array( $item['event'] ) ) {
				continue;
			}

			$order_id = isset( $item['order_id'] ) ? absint( $item['order_id'] ) : 0;
			if ( ! empty( $item['event']['event_name'] ) && 'Purchase' === $item['event']['event_name'] && $order_id ) {
				$purchase_guard = self::maybe_short_circuit_purchase_event( $item['event'], $order_id, 'queue', isset( $item['attempt'] ) ? absint( $item['attempt'] ) : 1 );
				if ( is_array( $purchase_guard ) && ! empty( $purchase_guard['success'] ) ) {
					continue;
				}
			}

			$next_retry = isset( $item['next_retry'] ) ? absint( $item['next_retry'] ) : 0;
			if ( $next_retry > time() ) {
				$remaining[] = $item;
				continue;
			}

			$attempt   = isset( $item['attempt'] ) ? absint( $item['attempt'] ) : 1;
			$transport = ! empty( $item['transport'] ) ? sanitize_text_field( $item['transport'] ) : 'queue';
			$result    = self::dispatch_event(
				$item['event'],
				$order_id,
				$transport,
				false,
				$attempt,
				array(),
				0
			);

			if ( ! $result['success'] ) {
				++$attempt;
				if ( $attempt <= self::MAX_RETRIES ) {
					$item['attempt']    = $attempt;
					$item['next_retry'] = time() + min( 1800, 120 * $attempt );
					$item['last_error'] = sanitize_text_field( $result['message'] );
					$remaining[]        = $item;
				} else {
					DPFB_Utils::log_event(
						isset( $item['event']['event_name'] ) ? $item['event']['event_name'] : 'Unknown',
						isset( $item['event']['event_id'] ) ? $item['event']['event_id'] : '',
						'failed-final',
						$result['message'],
						'queue',
						self::build_log_context( $item['event'], $order_id, $attempt )
					);
				}
			}
		}

		update_option( 'dpfb_event_queue', $remaining, false );

		if ( ! empty( $remaining ) ) {
			self::schedule_queue_processor( 120 );
		}
	}
}

// phpcs:enable WordPress.PHP.YodaConditions
