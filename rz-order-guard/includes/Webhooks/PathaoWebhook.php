<?php
namespace RZOG\Webhooks;


use RZOG\CourierIntegration\Manager;
use RZOG\Encryption;
use RZOG\Status_Bridge;

defined('ABSPATH') || exit;

/**
 * Pathao webhook handler.
 */
class PathaoWebhook {
	public function register(): void {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	public function register_routes(): void {
		register_rest_route('rzog/v1', '/pathao-webhook', [
			'methods' => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => [$this, 'permission'],
		]);
	}

	public function permission(\WP_REST_Request $request): bool {
		$expected_raw = (string) get_option('rzog_ci_pathao_webhook_secret', '');
		if ($expected_raw === '') {
			return false;
		}
		
		// Decrypt webhook secret if encrypted
		$expected = Encryption::is_encrypted($expected_raw) 
			? Encryption::decrypt($expected_raw) 
			: $expected_raw;

		// Pathao sends signature in header; accept a few common header variants.
		$sig = (string) ($request->get_header('x-pathao-signature') ?: $request->get_header('x_pathao_signature'));
		if ($sig === '' && isset($_SERVER['HTTP_X_PATHAO_SIGNATURE'])) {
			$sig = (string) $_SERVER['HTTP_X_PATHAO_SIGNATURE'];
		}
		$sig = trim($sig);

		return hash_equals($expected, $sig);
	}

	public function handle(\WP_REST_Request $request) {
		$payload = $request->get_json_params();
		if (!is_array($payload)) {
			$payload = [];
		}

		$event = (string) ($payload['event'] ?? '');
		if ($event === 'webhook_integration') {
			return $this->response(['message' => 'webhook_integration ok'], 202);
		}

		$order_id = isset($payload['merchant_order_id']) ? absint($payload['merchant_order_id']) : 0;
		if (!$order_id) {
			return $this->response(['message' => 'missing merchant_order_id'], 400);
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			return $this->response(['message' => 'order not found'], 404);
		}

		$status = (string) ($payload['order_status'] ?? '');
		$delivery_fee = isset($payload['delivery_fee']) ? (string) $payload['delivery_fee'] : '';
		$consignment_id = (string) ($payload['consignment_id'] ?? '');
		// Timezone fix: Use UTC for storage (consistent with rest of codebase)
		$now = gmdate('Y-m-d H:i:s');

		if ($status !== '') {
			$order->update_meta_data(Manager::META_PATHAO_STATUS, $status);
			// THE FIX: actually transition the real WC order status so
			// Fraud_Check::local_check() picks this up.
			Status_Bridge::maybe_transition($order, 'pathao', $status);
		} elseif ($event !== '') {
			// Fallback: store raw event if status missing
			$order->update_meta_data(Manager::META_PATHAO_STATUS, $event);
		}

		if ($delivery_fee !== '') {
			$order->update_meta_data(Manager::META_PATHAO_DELIVERY_FEE, $delivery_fee);
		}

		if ($consignment_id !== '') {
			$order->update_meta_data(Manager::META_PATHAO_CONSIGNMENT_ID, $consignment_id);
		}

		// If order wasn't sent via UI, don't overwrite sent_at; but always record sync time.
		if ((string) $order->get_meta(Manager::META_PATHAO_SENT_AT, true) === '' && $consignment_id !== '') {
			$order->update_meta_data(Manager::META_PATHAO_SENT_AT, $now);
		}
		$order->update_meta_data(Manager::META_PATHAO_LAST_SYNC_AT, $now);
		$order->save();

		return $this->response(['message' => 'ok'], 202);
	}

	private function response(array $data, int $status = 200): \WP_REST_Response {
		$res = rest_ensure_response($data);
		$res->set_status($status);
		return $res;
	}
}

