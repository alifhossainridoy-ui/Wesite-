<?php
namespace RZOG\Webhooks;


use RZOG\CourierIntegration\Manager;
use RZOG\Encryption;
use RZOG\Status_Bridge;

defined('ABSPATH') || exit;

/**
 * Steadfast webhook handler.
 *
 * Handles:
 * - delivery_status: Updates order status, delivery charge
 * - tracking_update: Logs tracking messages
 */
class SteadfastWebhook {
	public function register(): void {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	public function register_routes(): void {
		register_rest_route('rzog/v1', '/steadfast-webhook', [
			'methods' => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => [$this, 'permission'],
		]);
	}

	public function permission(\WP_REST_Request $request): bool {
		// Steadfast uses Bearer token in Authorization header
		$auth_header = (string) ($request->get_header('authorization') ?: '');
		if ($auth_header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
			$auth_header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		}

		// Extract Bearer token
		if (preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
			$token = trim($matches[1]);
			// NOTE: this is a SEPARATE field from the outbound API key/secret --
			// Steadfast's dashboard has a distinct "Auth Token (Bearer)" field
			// specifically for webhook auth. Don't reuse the outbound API key here.
			$expected_raw = (string) get_option('rzog_ci_steadfast_webhook_token', '');
			
			// Decrypt API key if encrypted
			$expected = Encryption::is_encrypted($expected_raw) 
				? Encryption::decrypt($expected_raw) 
				: $expected_raw;
			
			return hash_equals($expected, $token);
		}

		return false;
	}

	public function handle(\WP_REST_Request $request) {
		$payload = $request->get_json_params();
		if (!is_array($payload)) {
			return $this->response(['status' => 'error', 'message' => 'Invalid payload'], 400);
		}

		$notification_type = (string) ($payload['notification_type'] ?? '');
		$consignment_id = isset($payload['consignment_id']) ? absint($payload['consignment_id']) : 0;
		$invoice = (string) ($payload['invoice'] ?? '');

		if ($consignment_id === 0 && $invoice === '') {
			return $this->response(['status' => 'error', 'message' => 'Missing consignment_id or invoice'], 400);
		}

		// Find order by consignment_id first, then fallback to invoice parsing
		$order = null;
		if ($consignment_id > 0) {
			$order = $this->find_order_by_consignment_id($consignment_id);
		}

		if (!$order && $invoice !== '') {
			$order = $this->find_order_by_invoice($invoice);
		}

		if (!$order) {
			return $this->response(['status' => 'error', 'message' => 'Order not found'], 404);
		}

		// Timezone fix: Use UTC for storage (consistent with rest of codebase)
		$now = gmdate('Y-m-d H:i:s');

		// Handle delivery_status notification
		if ($notification_type === 'delivery_status') {
			$status = (string) ($payload['status'] ?? '');
			$delivery_charge = isset($payload['delivery_charge']) ? (float) $payload['delivery_charge'] : null;

			if ($status !== '') {
				// Map Steadfast statuses to our stored format
				$order->update_meta_data(Manager::META_STEADFAST_STATUS, $status);
				// THE FIX: actually transition the real WC order status so
				// Fraud_Check::local_check() picks this up.
				Status_Bridge::maybe_transition($order, 'steadfast', $status);
			}

			if ($delivery_charge !== null) {
				// Store delivery charge if provided (we don't have a dedicated meta for this, but could add it)
				// For now, we'll just update status
			}

			// Update consignment_id if provided and not already set
			if ($consignment_id > 0 && (string) $order->get_meta(Manager::META_STEADFAST_CONSIGNMENT_ID, true) === '') {
				$order->update_meta_data(Manager::META_STEADFAST_CONSIGNMENT_ID, (string) $consignment_id);
			}

			// If order wasn't sent via UI, set sent_at
			if ((string) $order->get_meta(Manager::META_STEADFAST_SENT_AT, true) === '' && $consignment_id > 0) {
				$order->update_meta_data(Manager::META_STEADFAST_SENT_AT, $now);
			}

			$order->update_meta_data(Manager::META_STEADFAST_LAST_SYNC_AT, $now);
			$order->save();

			return $this->response(['status' => 'success', 'message' => 'Webhook received successfully.'], 200);
		}

		// Handle tracking_update notification (just log, don't update status)
		if ($notification_type === 'tracking_update') {
			$tracking_message = (string) ($payload['tracking_message'] ?? '');
			if ($tracking_message !== '') {
				// Optionally store tracking messages in order notes
				$order->add_order_note(sprintf(__('Steadfast Tracking: %s', 'rz-order-guard'), $tracking_message));
			}

			$order->update_meta_data(Manager::META_STEADFAST_LAST_SYNC_AT, $now);
			$order->save();

			return $this->response(['status' => 'success', 'message' => 'Webhook received successfully.'], 200);
		}

		// Unknown notification type - still acknowledge
		return $this->response(['status' => 'success', 'message' => 'Webhook received (unknown type).'], 200);
	}

	/**
	 * Find order by Steadfast consignment_id.
	 */
	private function find_order_by_consignment_id(int $consignment_id): ?\WC_Order {
		$args = [
			'limit' => 1,
			'return' => 'ids',
			'meta_key' => Manager::META_STEADFAST_CONSIGNMENT_ID,
			'meta_value' => (string) $consignment_id,
		];

		$order_ids = wc_get_orders($args);
		if (empty($order_ids)) {
			return null;
		}

		return wc_get_order($order_ids[0]);
	}

	/**
	 * Find order by Steadfast invoice.
	 * Our invoice format: ymd-order_id (e.g., "250121-12345")
	 */
	private function find_order_by_invoice(string $invoice): ?\WC_Order {
		$invoice = trim($invoice);
		if ($invoice === '') {
			return null;
		}

		// Try to parse our format: ymd-order_id
		if (preg_match('/^(\d{6})-(\d+)$/', $invoice, $matches)) {
			$order_id = absint($matches[2]);
			$order = wc_get_order($order_id);
			if ($order) {
				return $order;
			}
		}

		// Fallback: search by invoice in meta (if stored)
		$args = [
			'limit' => 1,
			'return' => 'ids',
			'meta_key' => '_rzog_ci_steadfast_invoice',
			'meta_value' => $invoice,
		];

		$order_ids = wc_get_orders($args);
		if (!empty($order_ids)) {
			return wc_get_order($order_ids[0]);
		}

		return null;
	}

	private function response(array $data, int $status = 200): \WP_REST_Response {
		$res = rest_ensure_response($data);
		$res->set_status($status);
		return $res;
	}
}
