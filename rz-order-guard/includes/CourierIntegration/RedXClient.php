<?php
namespace RZOG\CourierIntegration;


use RZOG\Encryption;

defined('ABSPATH') || exit;

/**
 * RedX Courier OpenAPI client.
 *
 * Base URL: sandbox.redx.com.bd/v1.0.0-beta (sandbox)
 *           openapi.redx.com.bd/v1.0.0-beta (production)
 */
class RedXClient {
	private const CACHE_TTL_LONG = 604800; // 7 days

	public static function is_enabled(): bool {
		return get_option('rzog_ci_redx_enabled', 'no') === 'yes';
	}

	public static function base_url(): string {
		$env = (string) get_option('rzog_ci_redx_environment', 'live');
		if ($env === 'sandbox') {
			return 'https://sandbox.redx.com.bd/v1.0.0-beta';
		}
		return 'https://openapi.redx.com.bd/v1.0.0-beta';
	}

	private static function get_token(): string {
		$token_raw = (string) get_option('rzog_ci_redx_token', '');
		
		// Decrypt token if encrypted
		if (Encryption::is_encrypted($token_raw)) {
			return Encryption::decrypt($token_raw);
		}
		
		return $token_raw;
	}

	private static function has_creds(): bool {
		$token = self::get_token();
		return !empty($token);
	}

	/**
	 * Perform authenticated request.
	 *
	 * @return array|\WP_Error
	 */
	private static function request(string $method, string $path, ?array $body = null, array $query_params = []) {
		if (!self::has_creds()) {
			return new \WP_Error('rzog_ci_redx_missing_creds', __('RedX API token is missing.', 'rz-order-guard'));
		}

		$token = self::get_token();
		$url = rtrim(self::base_url(), '/') . $path;

		// Add query parameters if provided
		if (!empty($query_params)) {
			$url = add_query_arg($query_params, $url);
		}

		$args = [
			'method' => strtoupper($method),
			'headers' => [
				'API-ACCESS-TOKEN' => 'Bearer ' . $token,
				'Accept' => 'application/json',
			],
			'timeout' => 30,
		];

		if ($body !== null) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode($body);
		}

		$res = wp_remote_request($url, $args);
		if (is_wp_error($res)) return $res;

		$code = (int) wp_remote_retrieve_response_code($res);
		$raw = (string) wp_remote_retrieve_body($res);
		$data = json_decode($raw, true);

		if ($code < 200 || $code >= 300) {
			$msg = __('RedX API request failed.', 'rz-order-guard');
			if (is_array($data) && !empty($data['message'])) {
				$msg = (string) $data['message'];
			} elseif (is_array($data) && !empty($data['error'])) {
				$error = $data['error'];
				if (is_array($error) && !empty($error['message'])) {
					$msg = (string) $error['message'];
				} elseif (is_string($error)) {
					$msg = $error;
				}
			}
			return new \WP_Error('rzog_ci_redx_http_' . $code, $msg, ['status' => $code, 'body' => $raw, 'path' => $path]);
		}

		return is_array($data) ? $data : [];
	}

	/**
	 * GET areas list.
	 *
	 * @param int|null $post_code Optional postal code filter
	 * @param string|null $district_name Optional district name filter
	 * @return array|\WP_Error list of areas
	 */
	public static function get_areas(?int $post_code = null, ?string $district_name = null) {
		$cache_key = 'rzog_ci_redx_areas';
		if ($post_code !== null) {
			$cache_key .= '_post_' . $post_code;
		} elseif ($district_name !== null) {
			$cache_key .= '_dist_' . md5($district_name);
		}

		$cached = get_transient($cache_key);
		if (is_array($cached)) return $cached;

		$query_params = [];
		if ($post_code !== null) {
			$query_params['post_code'] = $post_code;
		} elseif ($district_name !== null) {
			$query_params['district_name'] = $district_name;
		}

		$data = self::request('GET', '/areas', null, $query_params);
		if (is_wp_error($data)) return $data;

		$areas = $data['areas'] ?? [];
		if (!is_array($areas)) $areas = [];

		set_transient($cache_key, $areas, self::CACHE_TTL_LONG);
		return $areas;
	}

	/**
	 * GET pickup stores list.
	 *
	 * @return array|\WP_Error list of pickup stores
	 */
	public static function get_pickup_stores() {
		$key = 'rzog_ci_redx_pickup_stores';
		$cached = get_transient($key);
		if (is_array($cached)) return $cached;

		$data = self::request('GET', '/pickup/stores');
		if (is_wp_error($data)) return $data;

		$stores = $data['pickup_stores'] ?? [];
		if (!is_array($stores)) $stores = [];

		set_transient($key, $stores, self::CACHE_TTL_LONG);
		return $stores;
	}

	/**
	 * GET pickup store details.
	 *
	 * @param int $store_id Pickup store ID
	 * @return array|\WP_Error store details
	 */
	public static function get_pickup_store_info(int $store_id) {
		return self::request('GET', '/pickup/store/info/' . $store_id);
	}

	/**
	 * Calculate parcel charge.
	 *
	 * @param int $delivery_area_id Delivery area ID
	 * @param int $pickup_area_id Pickup area ID
	 * @param float $cash_collection_amount COD amount
	 * @param int $weight Weight in grams
	 * @return array|\WP_Error charge details
	 */
	public static function calculate_charge(int $delivery_area_id, int $pickup_area_id, float $cash_collection_amount, int $weight) {
		$query_params = [
			'delivery_area_id' => $delivery_area_id,
			'pickup_area_id' => $pickup_area_id,
			'cash_collection_amount' => $cash_collection_amount,
			'weight' => $weight,
		];

		return self::request('GET', '/charge/charge_calculator', null, $query_params);
	}

	/**
	 * Create parcel at RedX.
	 *
	 * @param array $payload expected fields per RedX docs
	 * @return array|\WP_Error response data with tracking_id
	 */
	public static function create_order(array $payload) {
		return self::request('POST', '/parcel', $payload);
	}

	/**
	 * Get parcel details by tracking ID.
	 *
	 * @return array|\WP_Error
	 */
	public static function get_order_info(string $tracking_id) {
		$tracking_id = trim($tracking_id);
		if ($tracking_id === '') {
			return new \WP_Error('rzog_ci_redx_missing_tracking', __('Missing RedX tracking ID.', 'rz-order-guard'));
		}
		return self::request('GET', '/parcel/info/' . rawurlencode($tracking_id));
	}

	/**
	 * Track parcel by tracking ID.
	 *
	 * @return array|\WP_Error tracking updates
	 */
	public static function track_parcel(string $tracking_id) {
		$tracking_id = trim($tracking_id);
		if ($tracking_id === '') {
			return new \WP_Error('rzog_ci_redx_missing_tracking', __('Missing RedX tracking ID.', 'rz-order-guard'));
		}
		return self::request('GET', '/parcel/track/' . rawurlencode($tracking_id));
	}

	/**
	 * Update parcel (e.g., cancel).
	 *
	 * @param string $tracking_id Tracking ID
	 * @param string $property_name Property to update (e.g., 'status')
	 * @param string $new_value New value (e.g., 'cancelled')
	 * @param string|null $reason Optional reason
	 * @return array|\WP_Error
	 */
	public static function update_parcel(string $tracking_id, string $property_name, string $new_value, ?string $reason = null) {
		$payload = [
			'entity_type' => 'parcel-tracking-id',
			'entity_id' => $tracking_id,
			'update_details' => [
				'property_name' => $property_name,
				'new_value' => $new_value,
			],
		];

		if ($reason !== null) {
			$payload['update_details']['reason'] = $reason;
		}

		return self::request('PATCH', '/parcels', $payload);
	}

	/**
	 * Build a standard "sent snapshot" meta payload.
	 */
	public static function build_sent_meta(array $create_response): array {
		// Timezone fix: Use UTC for storage (consistent with rest of codebase)
		$now = gmdate('Y-m-d H:i:s');
		return [
			'tracking_id' => (string) ($create_response['tracking_id'] ?? ''),
			'sent_at' => $now,
			'last_sync_at' => $now,
		];
	}
}
