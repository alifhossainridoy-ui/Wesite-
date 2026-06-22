<?php
namespace RZOG;

defined('ABSPATH') || exit;

/**
 * THE CRITICAL PIECE the old plugin's webhooks were missing: maps each
 * courier's raw delivery-status string to an actual WooCommerce order
 * status transition. Without this, webhook handlers only update private
 * meta fields -- Fraud_Check::local_check() reads the REAL order status,
 * so it would never see any resolved orders and your "free local fraud
 * signal" would silently do nothing.
 *
 * Steadfast's mapping below is confirmed against their documented status
 * vocabulary (pending, delivered_approval_pending, partial_delivered_*,
 * cancelled_approval_pending, delivered, partial_delivered, cancelled,
 * hold, in_review, unknown).
 *
 * Pathao and RedX mappings are BEST-EFFORT GUESSES, not verified against
 * your live payloads. Wrong strings here are harmless (no transition
 * happens, nothing breaks) -- but check a real test delivery's
 * `_rzog_ci_pathao_status` / `_rzog_ci_redx_status` order meta and adjust
 * the arrays below before trusting this for real fraud decisions.
 */
class Status_Bridge {

    const STEADFAST_DELIVERED = ['delivered'];
    const STEADFAST_FAILED    = ['cancelled'];

    // NOT VERIFIED -- confirm against real Pathao webhook payloads.
    const PATHAO_DELIVERED = ['Delivered', 'delivered'];
    const PATHAO_FAILED    = ['Cancelled', 'cancelled', 'Return', 'returned', 'Delivery_Failed'];

    // NOT VERIFIED -- confirm against real RedX webhook payloads.
    const REDX_DELIVERED = ['delivered', 'Delivered'];
    const REDX_FAILED    = ['cancelled', 'Cancelled', 'returned', 'Returned'];

    public static function resolve(string $courier, string $raw_status): ?string {
        $raw_status = trim($raw_status);
        if ($raw_status === '') {
            return null;
        }

        $map = self::get_map($courier);

        if (in_array($raw_status, $map['delivered'], true)) {
            return 'completed';
        }
        if (in_array($raw_status, $map['failed'], true)) {
            return 'cancelled';
        }

        return null; // ambiguous / still in-transit -- leave order status untouched
    }

    private static function get_map(string $courier): array {
        switch ($courier) {
            case 'steadfast':
                return ['delivered' => self::STEADFAST_DELIVERED, 'failed' => self::STEADFAST_FAILED];
            case 'pathao':
                return ['delivered' => self::PATHAO_DELIVERED, 'failed' => self::PATHAO_FAILED];
            case 'redx':
                return ['delivered' => self::REDX_DELIVERED, 'failed' => self::REDX_FAILED];
            default:
                return ['delivered' => [], 'failed' => []];
        }
    }

    /**
     * Apply the transition if one is warranted. Safe to call on every
     * webhook hit -- no-ops if the status is ambiguous or the order is
     * already in that state.
     */
    public static function maybe_transition(\WC_Order $order, string $courier, string $raw_status, string $note = ''): void {
        $target = self::resolve($courier, $raw_status);
        if ($target === null || $order->get_status() === $target) {
            return;
        }

        $order->update_status(
            $target,
            $note ?: sprintf('%s delivery status: %s', ucfirst($courier), $raw_status)
        );
    }
}
