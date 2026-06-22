<?php
namespace RZOG\Webhooks;


use RZOG\CourierIntegration\Manager;
use RZOG\Encryption;
use RZOG\Status_Bridge;

defined('ABSPATH') || exit;

/**
 * RedX webhook handler.
 *
 * Handles parcel status updates from RedX.
 */
class RedXWebhook {
	public function register(): void {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	public function register_routes(): void {
		register_rest_route('rzog/v1', '/redx-webhook', [
			'methods' => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => [$this, 'permission'],
		]);
	}

	public function permission(\WP_REST_Request $request): bool {
		// RedX sends token in query parameter (as per docs: "Any required credentials should be included in the query parameters")
		$token = (string) ($request->get_param('token') ?? '');
		
		if ($token === '') {
			return false;
		}

		$expected_raw = (string) get_option('rzog_ci_redx_webhook_token', '');
		if ($expected_raw === '') {
			return false;
		}
		
		// Decrypt webhook token if encrypted
		$expected = Encryption::is_encrypted($expected_raw) 
			? Encryption::decrypt($expected_raw) 
			: $expected_raw;

		return hash_equals($expected, $token);
	}

	public function handle(\WP_REST_Request $request) {
		$payload = $request->get_json_params();
		if (!is_array($payload)) {
			return $this->response(['status' => 'error', 'message' => 'Invalid payload'], 400);
		}

		$tracking_number = (string) ($payload['tracking_number'] ?? '');
		$status = (string) ($payload['status'] ?? '');
		$invoice_number = (string) ($payload['invoice_number'] ?? '');

		if ($tracking_number === '' && $invoice_number === '') {
			return $this->response(['status' => 'error', 'message' => 'Missing tracking_number or invoice_number'], 400);
		}

		// Find order by tracking_id first, then fallback to invoice_number
		$order = null;
		if ($tracking_number !== '') {
			$order = $this->find_order_by_tracking_id($tracking_number);
		}

		if (!$order && $invoice_number !== '') {
			$order = $this->find_order_by_invoice($invoice_number);
		}

		if (!$order) {
			return $this->response(['status' => 'error', 'message' => 'Order not found'], 404);
		}

		// Timezone fix: Use UTC for storage (consistent with rest of codebase)
		$now = gmdate('Y-m-d H:i:s');

		// Update status if provided
		if ($status !== '') {
			// Map RedX statuses to our stored format
			$order->update_meta_data(Manager::META_REDX_STATUS, $status);
			// THE FIX: actually transition the real WC order status so
			// Fraud_Check::local_check() picks this up.
			Status_Bridge::maybe_transition($order, 'redx', $status);
		}

		// Update tracking_id if provided and not already set
		if ($tracking_number !== '' && (string) $order->get_meta(Manager::META_REDX_TRACKING_ID, true) === '') {
			$order->update_meta_data(Manager::META_REDX_TRACKING_ID, $tracking_number);
		}

		// If order wasn't sent via UI, set sent_at
		if ((string) $order->get_meta(Manager::META_REDX_SENT_AT, true) === '' && $tracking_number !== '') {
			$order->update_meta_data(Manager::META_REDX_SENT_AT, $now);
		}

		$order->update_meta_data(Manager::META_REDX_LAST_SYNC_AT, $now);
		
		// Store additional webhook data
		$message_en = (string) ($payload['message_en'] ?? '');
		$message_bn = (string) ($payload['message_bn'] ?? '');
		$timestamp = (string) ($payload['timestamp'] ?? '');
		
		if ($message_en !== '' || $message_bn !== '') {
			$tracking_note = sprintf(
				__('RedX Status Update: %s', 'rz-order-guard'),
				$message_en ?: $message_bn
			);
			$order->add_order_note($tracking_note);
		}

		$order->save();

		return $this->response(['status' => 'success', 'message' => 'Webhook received successfully.'], 200);
	}

	/**
	 * Find order by RedX tracking_id.
	 */
	private function find_order_by_tracking_id(string $tracking_id): ?\WC_Order {
		$args = [
			'limit' => 1,
			'return' => 'ids',
			'meta_key' => Manager::META_REDX_TRACKING_ID,
			'meta_value' => $tracking_id,
		];

		$order_ids = wc_get_orders($args);
		if (empty($order_ids)) {
			return null;
		}

		return wc_get_order($order_ids[0]);
	}

	/**
	 * Find order by invoice number.
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
			'meta_key' => Manager::META_REDX_INVOICE,
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
