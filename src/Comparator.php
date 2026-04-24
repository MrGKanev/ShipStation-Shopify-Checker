<?php
/**
 * Comparator — pure logic, no I/O.
 */
class Comparator
{
    /**
     * Build a fast lookup: normalised orderNumber → [ss orders].
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function buildSSIndex(array $ssOrders): array
    {
        $index = [];
        foreach ($ssOrders as $order) {
            $key = self::normalise((string) ($order['orderNumber'] ?? ''));
            if ($key !== '') {
                $index[$key][] = $order;
            }
        }
        return $index;
    }

    /**
     * Compare Shopify orders against the ShipStation index.
     *
     * Skipped reasons (stored in $order['_skip_reason']):
     *   cancelled     — cancelled_at is set
     *   financial     — pending / voided / refunded
     *   fulfilled     — fulfillment_status === 'fulfilled' (fully shipped; already processed)
     *   restocked     — fulfillment_status === 'restocked' (returned & restocked after shipment)
     *   on_hold       — fulfillment order has status 'on_hold' (checked post-compare in audit.php
     *                   via Shopify::isOnHold(); requires a separate API call per order)
     *   zero_value    — total_price == 0 (digital downloads, gift cards)
     *   no_shipping   — no shipping lines (fulfilled digitally or local pickup)
     *   ignored       — manually ignored via the web dashboard
     *
     * @param  array<int, array<string, mixed>>                $shopifyOrders
     * @param  array<string, array<int, array<string, mixed>>> $ssIndex
     * @param  array<string, array<string, mixed>>             $ignoredNumbers  key = normalised order number
     * @return array{missing: list<array>, found: list<array>, skipped: list<array>, ignored: list<array>}
     */
    public static function compare(
        array $shopifyOrders,
        array $ssIndex,
        array $ignoredNumbers = []
    ): array {
        $missing = [];
        $found   = [];
        $skipped = [];
        $ignored = [];

        foreach ($shopifyOrders as $order) {
            $num = self::normalise((string) ($order['order_number'] ?? $order['name'] ?? ''));

            // ── Ignored (manually dismissed) ──────────────────────────
            if (isset($ignoredNumbers[$num])) {
                $order['_ignore_info'] = $ignoredNumbers[$num];
                $ignored[] = $order;
                continue;
            }

            // ── Cancelled ─────────────────────────────────────────────
            if (!empty($order['cancelled_at'])) {
                $order['_skip_reason'] = 'cancelled';
                $skipped[] = $order;
                continue;
            }

            // ── Financial status ──────────────────────────────────────
            $financial = $order['financial_status'] ?? '';
            if (in_array($financial, ['pending', 'voided', 'refunded'], true)) {
                $order['_skip_reason'] = 'financial';
                $skipped[] = $order;
                continue;
            }

            // ── Fulfillment status ────────────────────────────────────
            // 'fulfilled'  → all line items shipped; order is done.
            // 'restocked'  → items were returned and restocked after shipment
            //                (Shopify sets this when a fulfilment is cancelled
            //                 post-shipment, e.g. a return/void after dispatch).
            // Both cases mean the order is no longer actionable from a
            // ShipStation perspective, so we skip them.
            $fulfillment = $order['fulfillment_status'] ?? null;
            if (in_array($fulfillment, ['fulfilled', 'restocked'], true)) {
                $order['_skip_reason'] = 'fulfilled';
                $skipped[] = $order;
                continue;
            }

            // ── Zero-value orders (digital downloads, gift cards) ─────
            if (isset($order['total_price']) && (float) $order['total_price'] === 0.0) {
                $order['_skip_reason'] = 'zero_value';
                $skipped[] = $order;
                continue;
            }

            // ── No shipping lines (digital / local pickup) ────────────
            // shipping_lines is an array; empty = nothing shipped physically.
            if (array_key_exists('shipping_lines', $order) && $order['shipping_lines'] === []) {
                $order['_skip_reason'] = 'no_shipping';
                $skipped[] = $order;
                continue;
            }

            // ── Compare ───────────────────────────────────────────────
            if (isset($ssIndex[$num])) {
                $order['_ss_matches'] = $ssIndex[$num];
                $found[] = $order;
            } else {
                $missing[] = $order;
            }
        }

        return compact('missing', 'found', 'skipped', 'ignored');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** Strip leading # and whitespace; keep digits only. */
    public static function normalise(string $raw): string
    {
        return preg_replace('/\D/', '', ltrim(trim($raw), '#'));
    }
}
