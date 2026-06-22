<?php
namespace RZOG\CourierIntegration;


use RZOG\Encryption;

defined('ABSPATH') || exit;

/**
 * Steadfast Courier API v1 client.
 *
 * Base URL: https://portal.packzy.com/api/v1
 */
class SteadfastClient {
	private const BASE_URL = 'https://portal.packzy.com/api/v1';

	public static function is_enabled(): bool {
		return get_option('rzog_ci_steadfast_enabled', 'no') === 'yes';
	}

	private static function creds(): array {
		// Decrypt credentials if encrypted
		$api_key_raw = (string) get_option('rzog_ci_steadfast_api_key', '');
		$api_key = Encryption::is_encrypted($api_key_raw) 
			? Encryption::decrypt($api_key_raw) 
			: $api_key_raw;
		
		$secret_key_raw = (string) get_option('rzog_ci_steadfast_secret_key', '');
		$secret_key = Encryption::is_encrypted($secret_key_raw) 
			? Encryption::decrypt($secret_key_raw) 
			: $secret_key_raw;
		
		return [
			'api_key' => $api_key,
			'secret_key' => $secret_key,
		];
	}

	private static function has_creds(): bool {
		$c = self::creds();
		return $c['api_key'] !== '' && $c['secret_key'] !== '';
	}

	/**
	 * Create order (consignment) in Steadfast.
	 *
	 * Required: invoice, recipient_name, recipient_phone, recipient_address, cod_amount
	 *
	 * @return array|\WP_Error
	 */
	public static function create_order(array $payload) {
		if (!self::has_creds()) {
			return new \WP_Error('rzog_ci_steadfast_missing_creds', __('Steadfast API credentials are missing.', 'rz-order-guard'));
		}

		$c = self::creds();
		$res = wp_remote_post(self::BASE_URL . '/create_order', [
			'headers' => [
				'Api-Key' => $c['api_key'],
				'Secret-Key' => $c['secret_key'],
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'timeout' => 45,
			'body' => wp_json_encode($payload),
		]);

		return self::handle_json_response($res, 'create_order');
	}

	/**
	 * Delivery status by consignment id.
	 *
	 * @return array|\WP_Error
	 */
	public static function status_by_cid(string $consignment_id) {
		$consignment_id = trim($consignment_id);
		if ($consignment_id === '') {
			return new \WP_Error('rzog_ci_steadfast_missing_consignment', __('Missing Steadfast consignment id.', 'rz-order-guard'));
		}
		if (!self::has_creds()) {
			return new \WP_Error('rzog_ci_steadfast_missing_creds', __('Steadfast API credentials are missing.', 'rz-order-guard'));
		}

		$c = self::creds();
		$res = wp_remote_get(self::BASE_URL . '/status_by_cid/' . rawurlencode($consignment_id), [
			'headers' => [
				'Api-Key' => $c['api_key'],
				'Secret-Key' => $c['secret_key'],
				'Accept' => 'application/json',
			],
			'timeout' => 30,
		]);

		return self::handle_json_response($res, 'status_by_cid');
	}

	/**
	 * @param array|\WP_Error $res
	 * @return array|\WP_Error
	 */
	private static function handle_json_response($res, string $context) {
		if (is_wp_error($res)) return $res;

		$code = (int) wp_remote_retrieve_response_code($res);
		$raw = (string) wp_remote_retrieve_body($res);
		$data = json_decode($raw, true);

		if ($code < 200 || $code >= 300 || !is_array($data)) {
			$msg = __('Steadfast API request failed.', 'rz-order-guard');
			if (is_array($data) && !empty($data['message'])) {
				$msg = (string) $data['message'];
			}
			return new \WP_Error('rzog_ci_steadfast_' . $context . '_' . $code, $msg, ['status' => $code, 'body' => $raw]);
		}

		return $data;
	}

	public static function build_sent_meta(array $create_response): array {
		// Timezone fix: Use UTC for storage (consistent with rest of codebase)
		$now = gmdate('Y-m-d H:i:s');
		$consignment = $create_response['consignment'] ?? [];
		return [
			'consignment_id' => (string) ($consignment['consignment_id'] ?? ''),
			'tracking_code' => (string) ($consignment['tracking_code'] ?? ''),
			'status' => (string) ($consignment['status'] ?? ''),
			'sent_at' => $now,
			'last_sync_at' => $now,
		];
	}
}

