<?php
namespace RZOG\CourierIntegration;


use RZOG\Encryption;

defined('ABSPATH') || exit;

/**
 * Pathao Courier Merchant API (OAuth2 password grant) client.
 *
 * Docs (public): /aladdin/api/v1/issue-token + /orders + location endpoints.
 */
class PathaoClient {
	private const TOKEN_TRANSIENT = 'rzog_ci_pathao_token';
	private const CACHE_TTL_LONG = 604800; // 7 days

	public static function is_enabled(): bool {
		return get_option('rzog_ci_pathao_enabled', 'no') === 'yes';
	}

	public static function base_url(): string {
		$env = (string) get_option('rzog_ci_pathao_environment', 'live');
		if ($env === 'sandbox') {
			return 'https://courier-api-sandbox.pathao.com';
		}
		return 'https://api-hermes.pathao.com';
	}

	private static function creds(): array {
		// Decrypt credentials if encrypted
		$client_secret_raw = (string) get_option('rzog_ci_pathao_client_secret', '');
		$client_secret = Encryption::is_encrypted($client_secret_raw) 
			? Encryption::decrypt($client_secret_raw) 
			: $client_secret_raw;
		
		$password_raw = (string) get_option('rzog_ci_pathao_password', '');
		$password = Encryption::is_encrypted($password_raw) 
			? Encryption::decrypt($password_raw) 
			: $password_raw;
		
		return [
			'client_id' => (string) get_option('rzog_ci_pathao_client_id', ''),
			'client_secret' => $client_secret,
			'username' => (string) get_option('rzog_ci_pathao_username', ''),
			'password' => $password,
		];
	}

	private static function has_creds(): bool {
		$c = self::creds();
		return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['username'] !== '' && $c['password'] !== '';
	}

	/**
	 * Get access token; refresh if needed.
	 *
	 * @return string|\WP_Error
	 */
	public static function get_access_token(bool $force_refresh = false) {
		if (!self::has_creds()) {
			return new \WP_Error('rzog_ci_pathao_missing_creds', __('Pathao integration credentials are missing.', 'rz-order-guard'));
		}

		$cached = get_transient(self::TOKEN_TRANSIENT);
		if (!$force_refresh && is_array($cached)) {
			$access = (string) ($cached['access_token'] ?? '');
			$expires_at = (int) ($cached['expires_at'] ?? 0);

			// Consider token valid if it has > 12 hours left (buffer).
			if ($access !== '' && $expires_at > (time() + 43200)) {
				return $access;
			}

			$refresh_token = (string) ($cached['refresh_token'] ?? '');
			if ($refresh_token !== '') {
				$ref = self::issue_token_refresh($refresh_token);
				if (!is_wp_error($ref) && !empty($ref['access_token'])) {
					return (string) $ref['access_token'];
				}
			}
		}

		$issued = self::issue_token_password();
		if (is_wp_error($issued)) return $issued;
		return (string) ($issued['access_token'] ?? '');
	}

	/**
	 * Issue token with password grant.
	 *
	 * @return array|\WP_Error
	 */
	private static function issue_token_password() {
		$c = self::creds();
		$payload = [
			'client_id' => $c['client_id'],
			'client_secret' => $c['client_secret'],
			'grant_type' => 'password',
			'username' => $c['username'],
			'password' => $c['password'],
		];

		$res = wp_remote_post(self::base_url() . '/aladdin/api/v1/issue-token', [
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'timeout' => 30,
			'body' => wp_json_encode($payload),
		]);

		return self::handle_token_response($res);
	}

	/**
	 * Issue token with refresh grant.
	 *
	 * @return array|\WP_Error
	 */
	private static function issue_token_refresh(string $refresh_token) {
		$c = self::creds();
		$payload = [
			'client_id' => $c['client_id'],
			'client_secret' => $c['client_secret'],
			'grant_type' => 'refresh_token',
			'refresh_token' => $refresh_token,
		];

		$res = wp_remote_post(self::base_url() . '/aladdin/api/v1/issue-token', [
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'timeout' => 30,
			'body' => wp_json_encode($payload),
		]);

		return self::handle_token_response($res);
	}

	/**
	 * @param array|\WP_Error $res
	 * @return array|\WP_Error
	 */
	private static function handle_token_response($res) {
		if (is_wp_error($res)) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);
		$data = json_decode($body, true);

		if ($code < 200 || $code >= 300 || !is_array($data)) {
			$msg = __('Pathao token request failed.', 'rz-order-guard');
			if (is_array($data) && !empty($data['message'])) {
				$msg = (string) $data['message'];
			}
			return new \WP_Error('rzog_ci_pathao_token_failed', $msg, ['status' => $code, 'body' => $body]);
		}

		$access_token = (string) ($data['access_token'] ?? '');
		$refresh_token = (string) ($data['refresh_token'] ?? '');
		$expires_in = (int) ($data['expires_in'] ?? 0);

		if ($access_token === '' || $expires_in <= 0) {
			return new \WP_Error('rzog_ci_pathao_token_invalid', __('Pathao returned an invalid token response.', 'rz-order-guard'), ['status' => $code, 'body' => $body]);
		}

		$session = [
			'access_token' => $access_token,
			'refresh_token' => $refresh_token,
			'expires_in' => $expires_in,
			'expires_at' => time() + $expires_in,
		];

		// Cache slightly less than expiry (buffer 5 minutes).
		$ttl = max(60, $expires_in - 300);
		set_transient(self::TOKEN_TRANSIENT, $session, $ttl);

		return $session;
	}

	/**
	 * Perform authenticated request.
	 *
	 * @return array|\WP_Error
	 */
	private static function request(string $method, string $path, ?array $body = null) {
		$token = self::get_access_token();
		if (is_wp_error($token)) return $token;

		$args = [
			'method' => strtoupper($method),
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept' => 'application/json',
			],
			'timeout' => 30,
		];

		if ($body !== null) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode($body);
		}

		$url = rtrim(self::base_url(), '/') . $path;
		$res = wp_remote_request($url, $args);
		if (is_wp_error($res)) return $res;

		$code = (int) wp_remote_retrieve_response_code($res);
		$raw = (string) wp_remote_retrieve_body($res);
		$data = json_decode($raw, true);

		if ($code < 200 || $code >= 300) {
			$msg = __('Pathao API request failed.', 'rz-order-guard');
			if (is_array($data) && !empty($data['message'])) {
				$msg = (string) $data['message'];
			}
			// If unauthorized, clear token so next attempt re-issues.
			if ($code === 401) {
				delete_transient(self::TOKEN_TRANSIENT);
			}
			return new \WP_Error('rzog_ci_pathao_http_' . $code, $msg, ['status' => $code, 'body' => $raw, 'path' => $path]);
		}

		return is_array($data) ? $data : [];
	}

	/**
	 * GET stores list.
	 *
	 * @return array|\WP_Error list of stores (as returned by Pathao)
	 */
	public static function get_stores() {
		$key = 'rzog_ci_pathao_stores';
		$cached = get_transient($key);
		if (is_array($cached)) return $cached;

		$data = self::request('GET', '/aladdin/api/v1/stores');
		if (is_wp_error($data)) return $data;

		$stores = $data['data']['data'] ?? $data['data'] ?? [];
		if (!is_array($stores)) $stores = [];

		set_transient($key, $stores, self::CACHE_TTL_LONG);
		return $stores;
	}

	/**
	 * GET city list.
	 *
	 * @return array|\WP_Error list of cities (as returned by Pathao)
	 */
	public static function get_cities() {
		$key = 'rzog_ci_pathao_cities';
		$cached = get_transient($key);
		if (is_array($cached)) return $cached;

		// Try current docs endpoint first.
		$data = self::request('GET', '/aladdin/api/v1/city-list');
		if (is_wp_error($data)) {
			// Fallback to older endpoint used by some integrations.
			$data = self::request('GET', '/aladdin/api/v1/countries/1/city-list');
			if (is_wp_error($data)) return $data;
		}

		$cities = $data['data']['data'] ?? $data['data'] ?? [];
		if (!is_array($cities)) $cities = [];

		set_transient($key, $cities, self::CACHE_TTL_LONG);
		return $cities;
	}

	/**
	 * GET zones for a city.
	 *
	 * @return array|\WP_Error
	 */
	public static function get_zones(int $city_id) {
		$key = 'rzog_ci_pathao_zones_' . $city_id;
		$cached = get_transient($key);
		if (is_array($cached)) return $cached;

		$data = self::request('GET', '/aladdin/api/v1/cities/' . $city_id . '/zone-list');
		if (is_wp_error($data)) return $data;

		$zones = $data['data']['data'] ?? $data['data'] ?? [];
		if (!is_array($zones)) $zones = [];

		set_transient($key, $zones, self::CACHE_TTL_LONG);
		return $zones;
	}

	/**
	 * GET areas for a zone.
	 *
	 * @return array|\WP_Error
	 */
	public static function get_areas(int $zone_id) {
		$key = 'rzog_ci_pathao_areas_' . $zone_id;
		$cached = get_transient($key);
		if (is_array($cached)) return $cached;

		$data = self::request('GET', '/aladdin/api/v1/zones/' . $zone_id . '/area-list');
		if (is_wp_error($data)) return $data;

		$areas = $data['data']['data'] ?? $data['data'] ?? [];
		if (!is_array($areas)) $areas = [];

		set_transient($key, $areas, self::CACHE_TTL_LONG);
		return $areas;
	}

	/**
	 * Create order at Pathao.
	 *
	 * @param array $payload expected fields per Pathao docs.
	 * @return array|\WP_Error response data
	 */
	public static function create_order(array $payload) {
		return self::request('POST', '/aladdin/api/v1/orders', $payload);
	}

	/**
	 * Get order short info.
	 *
	 * @return array|\WP_Error
	 */
	public static function get_order_info(string $consignment_id) {
		$consignment_id = trim($consignment_id);
		if ($consignment_id === '') {
			return new \WP_Error('rzog_ci_pathao_missing_consignment', __('Missing Pathao consignment id.', 'rz-order-guard'));
		}
		return self::request('GET', '/aladdin/api/v1/orders/' . rawurlencode($consignment_id) . '/info');
	}

	/**
	 * Build a standard "sent snapshot" meta payload.
	 */
	public static function build_sent_meta(array $create_response): array {
		// Timezone fix: Use UTC for storage (consistent with rest of codebase)
		$now = gmdate('Y-m-d H:i:s');
		$data = $create_response['data'] ?? [];
		return [
			'consignment_id' => (string) ($data['consignment_id'] ?? ''),
			'merchant_order_id' => (string) ($data['merchant_order_id'] ?? ''),
			'order_status' => (string) ($data['order_status'] ?? ''),
			'delivery_fee' => isset($data['delivery_fee']) ? (string) $data['delivery_fee'] : '',
			'sent_at' => $now,
			'last_sync_at' => $now,
		];
	}
}

