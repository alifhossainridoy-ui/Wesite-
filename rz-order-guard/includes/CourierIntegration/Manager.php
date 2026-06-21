<?php
namespace RZOG\CourierIntegration;


use RZOG\Fraud_Check;

defined('ABSPATH') || exit;

/**
 * Courier Integration Manager (Send orders to Pathao / Steadfast).
 */
class Manager {
	// Pathao meta keys
	public const META_PATHAO_CONSIGNMENT_ID = '_rzog_ci_pathao_consignment_id';
	public const META_PATHAO_STATUS = '_rzog_ci_pathao_status';
	public const META_PATHAO_DELIVERY_FEE = '_rzog_ci_pathao_delivery_fee';
	public const META_PATHAO_SENT_AT = '_rzog_ci_pathao_sent_at';
	public const META_PATHAO_LAST_SYNC_AT = '_rzog_ci_pathao_last_sync_at';

	// Steadfast meta keys
	public const META_STEADFAST_CONSIGNMENT_ID = '_rzog_ci_steadfast_consignment_id';
	public const META_STEADFAST_TRACKING_CODE = '_rzog_ci_steadfast_tracking_code';
	public const META_STEADFAST_STATUS = '_rzog_ci_steadfast_status';
	public const META_STEADFAST_INVOICE = '_rzog_ci_steadfast_invoice';
	public const META_STEADFAST_SENT_AT = '_rzog_ci_steadfast_sent_at';
	public const META_STEADFAST_LAST_SYNC_AT = '_rzog_ci_steadfast_last_sync_at';

	// RedX meta keys
	public const META_REDX_TRACKING_ID = '_rzog_ci_redx_tracking_id';
	public const META_REDX_STATUS = '_rzog_ci_redx_status';
	public const META_REDX_INVOICE = '_rzog_ci_redx_invoice';
	public const META_REDX_SENT_AT = '_rzog_ci_redx_sent_at';
	public const META_REDX_LAST_SYNC_AT = '_rzog_ci_redx_last_sync_at';

	public const PROVIDER_PATHAO = 'pathao';
	public const PROVIDER_STEADFAST = 'steadfast';
	public const PROVIDER_REDX = 'redx';

	public static function any_enabled(): bool {
		return PathaoClient::is_enabled() || SteadfastClient::is_enabled() || RedXClient::is_enabled();
	}

	public static function provider_enabled(string $provider): bool {
		if ($provider === self::PROVIDER_PATHAO) return PathaoClient::is_enabled();
		if ($provider === self::PROVIDER_STEADFAST) return SteadfastClient::is_enabled();
		if ($provider === self::PROVIDER_REDX) return RedXClient::is_enabled();
		return false;
	}

	/**
	 * Build initial (editable) form payload from a WooCommerce order.
	 */
	public static function build_form_defaults(\WC_Order $order): array {
		$order_id = $order->get_id();

		$billing_phone = (string) $order->get_billing_phone();
		$phone = Fraud_Check::normalize_phone($billing_phone) ?: preg_replace('/[^0-9]/', '', $billing_phone);

		$name = trim((string) $order->get_formatted_billing_full_name());
		if ($name === '') {
			$name = trim((string) ($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()));
		}

		// Prefer shipping, fallback to billing.
		$address_parts = [];
		$ship1 = trim((string) $order->get_shipping_address_1());
		$ship2 = trim((string) $order->get_shipping_address_2());
		$ship_city = trim((string) $order->get_shipping_city());
		$ship_state = trim((string) $order->get_shipping_state());
		$ship_postcode = trim((string) $order->get_shipping_postcode());

		$bill1 = trim((string) $order->get_billing_address_1());
		$bill2 = trim((string) $order->get_billing_address_2());
		$bill_city = trim((string) $order->get_billing_city());
		$bill_state = trim((string) $order->get_billing_state());
		$bill_postcode = trim((string) $order->get_billing_postcode());

		if ($ship1 !== '' || $ship2 !== '' || $ship_city !== '' || $ship_postcode !== '') {
			if ($ship1 !== '') $address_parts[] = $ship1;
			if ($ship2 !== '') $address_parts[] = $ship2;
			if ($ship_city !== '') $address_parts[] = $ship_city;
			if ($ship_state !== '') $address_parts[] = $ship_state;
			if ($ship_postcode !== '') $address_parts[] = $ship_postcode;
		} else {
			if ($bill1 !== '') $address_parts[] = $bill1;
			if ($bill2 !== '') $address_parts[] = $bill2;
			if ($bill_city !== '') $address_parts[] = $bill_city;
			if ($bill_state !== '') $address_parts[] = $bill_state;
			if ($bill_postcode !== '') $address_parts[] = $bill_postcode;
		}

		$address = trim(implode(', ', array_filter($address_parts)));

		// COD amount: Check payment method
		// If payment method is COD (cash on delivery), always set to order total
		// Otherwise (pay with, pay via, etc.), set to 0
		$payment_method = strtolower($order->get_payment_method() ?? '');
		$payment_method_title = strtolower($order->get_payment_method_title() ?? '');
		
		$amount_to_collect = 0;
		
		// Check if payment method is COD (cash on delivery)
		// Common COD identifiers: 'cod', 'cash_on_delivery', 'cash-on-delivery', 'cashondelivery'
		$is_cod = (
			strpos($payment_method, 'cod') !== false ||
			strpos($payment_method, 'cash') !== false ||
			strpos($payment_method_title, 'cod') !== false ||
			strpos($payment_method_title, 'cash on delivery') !== false ||
			strpos($payment_method_title, 'cash-on-delivery') !== false
		);
		
		if ($is_cod) {
			// Payment method is COD - always set to order total
			$amount_to_collect = (int) round((float) $order->get_total());
		}
		// Otherwise, keep it 0 (for pay with, pay via, etc.)

		// Item description: list products (short).
		$item_lines = [];
		$total_qty = 0;
		foreach ($order->get_items() as $item) {
			$qty = (int) $item->get_quantity();
			$total_qty += $qty;
			$item_lines[] = trim($item->get_name()) . ' x' . $qty;
		}
		$item_description = implode("\n", array_slice($item_lines, 0, 10));

		$customer_note = (string) $order->get_customer_note();

		// Pathao defaults
		$default_store_id = (int) get_option('rzog_ci_pathao_store_id', 0);

		// RedX defaults
		$default_pickup_store_id = (int) get_option('rzog_ci_redx_pickup_store_id', 0);

		return [
			'order_id' => $order_id,
			'customer' => [
				'name' => $name,
				'phone' => $phone,
				'secondary_phone' => '',
				'address' => $address,
			],
			'parcel' => [
				'amount_to_collect' => $amount_to_collect,
				'item_quantity' => max(1, $total_qty),
				'item_weight' => '0.5',
				'item_type' => 2, // 2 parcel
				'delivery_type' => 48, // normal delivery
				'item_description' => $item_description,
				'special_instruction' => $customer_note,
			],
			'pathao' => [
				'store_id' => $default_store_id,
				'recipient_city' => 0,
				'recipient_zone' => 0,
				'recipient_area' => 0,
			],
			'steadfast' => [
				'invoice' => self::build_invoice($order_id),
				'note' => $customer_note,
			],
			'redx' => [
				'invoice' => self::build_invoice($order_id),
				'delivery_area' => '',
				'delivery_area_id' => 0,
				'pickup_store_id' => $default_pickup_store_id,
				'instruction' => $customer_note,
				'value' => '0',
				'is_closed_box' => 'no',
			],
		];
	}

	public static function build_invoice(int $order_id): string {
		// Steadfast wants unique invoice; keep deterministic & compact.
		return gmdate('ymd') . '-' . $order_id;
	}

	public static function get_status(\WC_Order $order): array {
		return [
			'pathao' => [
				'consignment_id' => (string) $order->get_meta(self::META_PATHAO_CONSIGNMENT_ID, true),
				'status' => (string) $order->get_meta(self::META_PATHAO_STATUS, true),
				'delivery_fee' => (string) $order->get_meta(self::META_PATHAO_DELIVERY_FEE, true),
				'sent_at' => (string) $order->get_meta(self::META_PATHAO_SENT_AT, true),
				'last_sync_at' => (string) $order->get_meta(self::META_PATHAO_LAST_SYNC_AT, true),
			],
			'steadfast' => [
				'consignment_id' => (string) $order->get_meta(self::META_STEADFAST_CONSIGNMENT_ID, true),
				'tracking_code' => (string) $order->get_meta(self::META_STEADFAST_TRACKING_CODE, true),
				'status' => (string) $order->get_meta(self::META_STEADFAST_STATUS, true),
				'sent_at' => (string) $order->get_meta(self::META_STEADFAST_SENT_AT, true),
				'last_sync_at' => (string) $order->get_meta(self::META_STEADFAST_LAST_SYNC_AT, true),
			],
			'redx' => [
				'tracking_id' => (string) $order->get_meta(self::META_REDX_TRACKING_ID, true),
				'status' => (string) $order->get_meta(self::META_REDX_STATUS, true),
				'sent_at' => (string) $order->get_meta(self::META_REDX_SENT_AT, true),
				'last_sync_at' => (string) $order->get_meta(self::META_REDX_LAST_SYNC_AT, true),
			],
		];
	}

	/**
	 * Send order to provider and persist meta on success.
	 *
	 * @return array|\WP_Error
	 */
	public static function send(\WC_Order $order, string $provider, array $form): array|\WP_Error {
		$provider = strtolower(trim($provider));
		if (!self::provider_enabled($provider)) {
			return new \WP_Error('rzog_ci_provider_disabled', __('Selected courier is not enabled.', 'rz-order-guard'));
		}

		if ($provider === self::PROVIDER_PATHAO) {
			$resp = PathaoClient::create_order(self::build_pathao_payload($order, $form));
			if (is_wp_error($resp)) return $resp;

			$meta = PathaoClient::build_sent_meta($resp);
			$order->update_meta_data(self::META_PATHAO_CONSIGNMENT_ID, $meta['consignment_id']);
			$order->update_meta_data(self::META_PATHAO_STATUS, $meta['order_status']);
			$order->update_meta_data(self::META_PATHAO_DELIVERY_FEE, $meta['delivery_fee']);
			$order->update_meta_data(self::META_PATHAO_SENT_AT, $meta['sent_at']);
			$order->update_meta_data(self::META_PATHAO_LAST_SYNC_AT, $meta['last_sync_at']);
			$order->save();

			return $meta;
		}

		if ($provider === self::PROVIDER_STEADFAST) {
			$payload = self::build_steadfast_payload($order, $form);
			$invoice = (string) ($payload['invoice'] ?? '');
			$resp = SteadfastClient::create_order($payload);
			if (is_wp_error($resp)) return $resp;

			$meta = SteadfastClient::build_sent_meta($resp);
			$order->update_meta_data(self::META_STEADFAST_CONSIGNMENT_ID, $meta['consignment_id']);
			$order->update_meta_data(self::META_STEADFAST_TRACKING_CODE, $meta['tracking_code']);
			$order->update_meta_data(self::META_STEADFAST_STATUS, $meta['status']);
			if ($invoice !== '') {
				$order->update_meta_data(self::META_STEADFAST_INVOICE, $invoice);
			}
			$order->update_meta_data(self::META_STEADFAST_SENT_AT, $meta['sent_at']);
			$order->update_meta_data(self::META_STEADFAST_LAST_SYNC_AT, $meta['last_sync_at']);
			$order->save();

			return $meta;
		}

		if ($provider === self::PROVIDER_REDX) {
			$payload = self::build_redx_payload($order, $form);
			$invoice = (string) ($payload['merchant_invoice_id'] ?? '');
			$resp = RedXClient::create_order($payload);
			if (is_wp_error($resp)) return $resp;

			$meta = RedXClient::build_sent_meta($resp);
			$order->update_meta_data(self::META_REDX_TRACKING_ID, $meta['tracking_id']);
			if ($invoice !== '') {
				$order->update_meta_data(self::META_REDX_INVOICE, $invoice);
			}
			$order->update_meta_data(self::META_REDX_SENT_AT, $meta['sent_at']);
			$order->update_meta_data(self::META_REDX_LAST_SYNC_AT, $meta['last_sync_at']);
			$order->save();

			return $meta;
		}

		return new \WP_Error('rzog_ci_unknown_provider', __('Unknown courier provider.', 'rz-order-guard'));
	}

	/**
	 * Refresh delivery status for a sent consignment.
	 *
	 * @return array|\WP_Error
	 */
	public static function refresh_status(\WC_Order $order, string $provider) {
		$provider = strtolower(trim($provider));
		// Timezone fix: Use UTC for storage (consistent with rest of codebase)
		$now = gmdate('Y-m-d H:i:s');

		if ($provider === self::PROVIDER_PATHAO) {
			$consignment_id = (string) $order->get_meta(self::META_PATHAO_CONSIGNMENT_ID, true);
			if ($consignment_id === '') {
				return new \WP_Error('rzog_ci_no_consignment', __('This order has not been sent to Pathao yet.', 'rz-order-guard'));
			}
			$resp = PathaoClient::get_order_info($consignment_id);
			if (is_wp_error($resp)) return $resp;

			$data = $resp['data'] ?? [];
			$status = (string) ($data['order_status'] ?? $data['order_status_slug'] ?? '');
			$order->update_meta_data(self::META_PATHAO_STATUS, $status);
			$order->update_meta_data(self::META_PATHAO_LAST_SYNC_AT, $now);
			$order->save();

			return ['status' => $status, 'last_sync_at' => $now];
		}

		if ($provider === self::PROVIDER_STEADFAST) {
			$consignment_id = (string) $order->get_meta(self::META_STEADFAST_CONSIGNMENT_ID, true);
			if ($consignment_id === '') {
				return new \WP_Error('rzog_ci_no_consignment', __('This order has not been sent to Steadfast yet.', 'rz-order-guard'));
			}
			$resp = SteadfastClient::status_by_cid($consignment_id);
			if (is_wp_error($resp)) return $resp;

			$status = (string) ($resp['delivery_status'] ?? $resp['status'] ?? '');
			$order->update_meta_data(self::META_STEADFAST_STATUS, $status);
			$order->update_meta_data(self::META_STEADFAST_LAST_SYNC_AT, $now);
			$order->save();

			return ['status' => $status, 'last_sync_at' => $now];
		}

		if ($provider === self::PROVIDER_REDX) {
			$tracking_id = (string) $order->get_meta(self::META_REDX_TRACKING_ID, true);
			if ($tracking_id === '') {
				return new \WP_Error('rzog_ci_no_consignment', __('This order has not been sent to RedX yet.', 'rz-order-guard'));
			}
			$resp = RedXClient::get_order_info($tracking_id);
			if (is_wp_error($resp)) return $resp;

			$parcel = $resp['parcel'] ?? [];
			$status = (string) ($parcel['status'] ?? '');
			$order->update_meta_data(self::META_REDX_STATUS, $status);
			$order->update_meta_data(self::META_REDX_LAST_SYNC_AT, $now);
			$order->save();

			return ['status' => $status, 'last_sync_at' => $now];
		}

		return new \WP_Error('rzog_ci_unknown_provider', __('Unknown courier provider.', 'rz-order-guard'));
	}

	private static function build_pathao_payload(\WC_Order $order, array $form): array {
		$order_id = $order->get_id();

		$customer = $form['customer'] ?? [];
		$parcel = $form['parcel'] ?? [];
		$pathao = $form['pathao'] ?? [];

		$payload = [
			'store_id' => (int) ($pathao['store_id'] ?? 0),
			'merchant_order_id' => (string) ($order_id),
			'recipient_name' => (string) ($customer['name'] ?? ''),
			'recipient_phone' => (string) ($customer['phone'] ?? ''),
			'recipient_secondary_phone' => (string) ($customer['secondary_phone'] ?? ''),
			'recipient_address' => (string) ($customer['address'] ?? ''),
			'delivery_type' => (int) ($parcel['delivery_type'] ?? 48),
			'item_type' => (int) ($parcel['item_type'] ?? 2),
			'special_instruction' => (string) ($parcel['special_instruction'] ?? ''),
			'item_quantity' => (int) ($parcel['item_quantity'] ?? 1),
			'item_weight' => (string) ($parcel['item_weight'] ?? '0.5'),
			'item_description' => (string) ($parcel['item_description'] ?? ''),
			'amount_to_collect' => (int) ($parcel['amount_to_collect'] ?? 0),
		];

		$city = (int) ($pathao['recipient_city'] ?? 0);
		$zone = (int) ($pathao['recipient_zone'] ?? 0);
		$area = (int) ($pathao['recipient_area'] ?? 0);
		if ($city > 0) $payload['recipient_city'] = $city;
		if ($zone > 0) $payload['recipient_zone'] = $zone;
		if ($area > 0) $payload['recipient_area'] = $area;

		return $payload;
	}

	private static function build_steadfast_payload(\WC_Order $order, array $form): array {
		$order_id = $order->get_id();
		$customer = $form['customer'] ?? [];
		$parcel = $form['parcel'] ?? [];
		$sf = $form['steadfast'] ?? [];

		$invoice = (string) ($sf['invoice'] ?? self::build_invoice($order_id));
		$note = (string) ($sf['note'] ?? ($parcel['special_instruction'] ?? ''));

		return [
			'invoice' => $invoice,
			'recipient_name' => (string) ($customer['name'] ?? ''),
			'recipient_phone' => (string) ($customer['phone'] ?? ''),
			'alternative_phone' => (string) ($customer['secondary_phone'] ?? ''),
			'recipient_address' => (string) ($customer['address'] ?? ''),
			'cod_amount' => (float) ($parcel['amount_to_collect'] ?? 0),
			'note' => $note,
			'item_description' => (string) ($parcel['item_description'] ?? ''),
		];
	}

	private static function build_redx_payload(\WC_Order $order, array $form): array {
		$order_id = $order->get_id();
		$customer = $form['customer'] ?? [];
		$parcel = $form['parcel'] ?? [];
		$redx = $form['redx'] ?? [];

		$invoice = (string) ($redx['invoice'] ?? self::build_invoice($order_id));
		$instruction = (string) ($redx['instruction'] ?? ($parcel['special_instruction'] ?? ''));
		$delivery_area_id = (int) ($redx['delivery_area_id'] ?? 0);
		$pickup_store_id = (int) ($redx['pickup_store_id'] ?? 0);
		$value = (string) ($redx['value'] ?? '0');
		$is_closed_box = (string) ($redx['is_closed_box'] ?? 'no');

		// Build parcel_details_json from order items
		$parcel_details = [];
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			$parcel_details[] = [
				'name' => trim($item->get_name()),
				'category' => $product ? ($product->get_category_ids() ? get_term($product->get_category_ids()[0])->name ?? 'General' : 'General') : 'General',
				'value' => (int) round((float) $item->get_total()),
			];
		}

		$payload = [
			'customer_name' => (string) ($customer['name'] ?? ''),
			'customer_phone' => (string) ($customer['phone'] ?? ''),
			'customer_address' => (string) ($customer['address'] ?? ''),
			'merchant_invoice_id' => $invoice,
			'cash_collection_amount' => (string) ($parcel['amount_to_collect'] ?? '0'),
			'parcel_weight' => (string) ($parcel['item_weight'] ?? '500'), // grams
			'instruction' => $instruction,
			'value' => $value,
			'is_closed_box' => $is_closed_box,
			'parcel_details_json' => $parcel_details,
		];

		// Delivery area is required
		if ($delivery_area_id > 0) {
			$payload['delivery_area_id'] = $delivery_area_id;
			// Get area name from cached areas if available
			$areas = RedXClient::get_areas();
			if (!is_wp_error($areas)) {
				foreach ($areas as $area) {
					if (isset($area['id']) && (int) $area['id'] === $delivery_area_id) {
						$payload['delivery_area'] = (string) ($area['name'] ?? '');
						break;
					}
				}
			}
		}

		// Pickup store is optional
		if ($pickup_store_id > 0) {
			$payload['pickup_store_id'] = $pickup_store_id;
		}

		return $payload;
	}
}

