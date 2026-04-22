<?php
/**
 * Comparator — pure logic, no I/O.
 *
 * Builds a lookup index from ShipStation orders and compares it against
 * the canonical Shopify order list.
 */
class Comparator
{
    /**
     * Build a fast lookup: orderNumber → [ss orders].
     *
     * ShipStation stores the Shopify order number in the `orderNumber`
     * field (e.g. "1234" or "#1234"). We normalise to digits only.
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function buildSSIndex(array $ssOrders): array
    {
        $index = [];
        foreach ($ssOrders as $order) {
            $raw = (string) ($order['orderNumber'] ?? '');
            $key = self::normalise($raw);
            if ($key !== '') {
                $index[$key][] = $order;
            }
        }
        return $index;
    }

    /**
     * Compare Shopify orders against the ShipStation index.
     *
     * Returns:
     *   missing  — Shopify orders that have NO match in ShipStation
     *   found    — Shopify orders that DO have a match
     *   skipped  — Shopify orders we deliberately ignore (cancelled / unpaid)
     *
     * @param  array<int, array<string, mixed>>                    $shopifyOrders
     * @param  array<string, array<int, array<string, mixed>>>     $ssIndex
     * @return array{missing: list<array>, found: list<array>, skipped: list<array>}
     */
    public static function compare(array $shopifyOrders, array $ssIndex): array
    {
        $missing = [];
        $found   = [];
        $skipped = [];

        foreach ($shopifyOrders as $order) {
            // Skip cancelled orders — they should never be in ShipStation
            if (!empty($order['cancelled_at'])) {
                $skipped[] = $order;
                continue;
            }

            // Skip orders that were never paid (pending / voided)
            $financial = $order['financial_status'] ?? '';
            if (in_array($financial, ['pending', 'voided', 'refunded'], true)) {
                $skipped[] = $order;
                continue;
            }

            $num = self::normalise((string) ($order['order_number'] ?? $order['name'] ?? ''));

            if (isset($ssIndex[$num])) {
                $order['_ss_matches'] = $ssIndex[$num];
                $found[] = $order;
            } else {
                $missing[] = $order;
            }
        }

        return compact('missing', 'found', 'skipped');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** Strip leading # and whitespace; keep digits only. */
    private static function normalise(string $raw): string
    {
        return preg_replace('/\D/', '', ltrim(trim($raw), '#'));
    }
}
