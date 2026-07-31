<?php
declare(strict_types=1);

/**
 * Comparator - pure logic, no I/O.
 */
class Comparator
{
    /**
     * Build a fast lookup: normalised orderNumber → [ss orders].
     *
     * Indexes each SS order under every distinct numeric segment in its
     * orderNumber so that compound formats like "100042-B2" or "Addon-100031"
     * all resolve to their Shopify counterpart correctly.
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function buildSSIndex(array $ssOrders): array
    {
        $index = [];
        foreach ($ssOrders as $order) {
            $raw = (string) ($order['orderNumber'] ?? '');
            // Primary key: full-normalised (digits only, all segments joined)
            $full = self::normalise($raw);
            if ($full !== '') {
                $index[$full][] = $order;
            }
            // Secondary keys: each individual contiguous digit-run
            // e.g. "100042-B2" → ["100042", "2"]; "Addon-100031" → ["100031"]
            preg_match_all('/\d+/', $raw, $m);
            foreach ($m[0] as $segment) {
                if ($segment !== $full && $segment !== '') {
                    $index[$segment][] = $order;
                }
            }
        }
        return $index;
    }

    /**
     * Build a secondary lookup: lowercase email → [ss orders].
     * Used as a fallback when order-number matching fails.
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function buildSSEmailIndex(array $ssOrders): array
    {
        $index = [];
        foreach ($ssOrders as $order) {
            $email = strtolower(trim($order['customerEmail'] ?? ''));
            if ($email !== '') {
                $index[$email][] = $order;
            }
        }
        return $index;
    }

    /**
     * Compare Shopify orders against the ShipStation index.
     *
     * Match order (first match wins):
     *   1. Order number - normalised numeric segments vs SS orderNumber
     *   2. Email + amount - same customer email and total within 1% tolerance
     *      (catches orders manually entered in SS with a wrong order number)
     *
     * Skipped reasons (stored in $order['_skip_reason']):
     *   cancelled     - cancelled_at is set
     *   financial     - pending / voided / refunded
     *   fulfilled     - fulfillment_status === 'fulfilled' (fully shipped; already processed)
     *   restocked     - fulfillment_status === 'restocked' (returned & restocked after shipment)
     *   on_hold       - fulfillment order has status 'on_hold' (checked post-compare in audit.php
     *                   via Shopify::isOnHold(); requires a separate API call per order)
     *   zero_value    - total_price == 0 (digital downloads, gift cards)
     *   no_shipping   - no shipping lines (fulfilled digitally or local pickup)
     *   ignored       - manually ignored via the web dashboard
     *
     * @param  array<int, array<string, mixed>>                $shopifyOrders
     * @param  array<string, array<int, array<string, mixed>>> $ssIndex
     * @param  array<string, array<string, mixed>>             $ignoredNumbers  key = normalised order number
     * @param  array<string, array<int, array<string, mixed>>> $ssEmailIndex    optional secondary index
     * @return array{missing: list<array>, found: list<array>, skipped: list<array>, ignored: list<array>}
     */
    public static function compare(
        array $shopifyOrders,
        array $ssIndex,
        array $ignoredNumbers = [],
        array $ssEmailIndex   = []
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
            if (in_array($financial, ['pending', 'voided', 'refunded', 'partially_refunded'], true)) {
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

            // ── Match 1: order number ─────────────────────────────────
            // Shopify exposes two identifiers: `order_number` (e.g. 65075) and
            // `name` (e.g. #165075). ShipStation may import either one depending
            // on the integration. We try both so a mismatch in convention doesn't
            // produce false positives.
            $nameNorm = self::normalise((string) ($order['name'] ?? ''));
            $match    = $ssIndex[$num] ?? $ssIndex[$nameNorm] ?? null;

            if ($match !== null) {
                $order['_ss_matches']    = $match;
                $order['_match_method']  = 'order_number';
                $found[] = $order;
                continue;
            }

            // ── Match 2: email + amount ───────────────────────────────
            // Fallback for orders manually entered in SS with a wrong/different
            // order number. Requires the same customer email and a total within
            // 1% of the Shopify total to avoid false positives.
            if (!empty($ssEmailIndex)) {
                $email        = strtolower(trim($order['email'] ?? ''));
                $shopifyTotal = (float) ($order['total_price'] ?? 0);

                if ($email !== '' && isset($ssEmailIndex[$email]) && $shopifyTotal > 0) {
                    foreach ($ssEmailIndex[$email] as $ssOrder) {
                        $ssTotal = (float) ($ssOrder['orderTotal'] ?? 0);
                        if (abs($shopifyTotal - $ssTotal) / $shopifyTotal < 0.01) {
                            $order['_ss_matches']   = [$ssOrder];
                            $order['_match_method'] = 'email+amount';
                            $found[] = $order;
                            continue 2;
                        }
                    }
                }
            }

            $missing[] = $order;
        }

        return compact('missing', 'found', 'skipped', 'ignored');
    }

    // ── Public helpers ────────────────────────────────────────────────

    /** Strip leading # and whitespace; keep digits only. */
    public static function normalise(string $raw): string
    {
        return preg_replace('/\D/', '', ltrim(trim($raw), '#'));
    }

    /**
     * Classify a Shopify order using rules defined in order_types.json.
     * Multiple matching rule names are joined with " + " (e.g. "Pro + Accessory").
     * Returns the configured fallback (default "Other") when no rules match.
     */
    public static function classifyOrder(array $order): string
    {
        $config   = self::getOrderTypesConfig();
        $rules    = $config['rules']    ?? [];
        $fallback = $config['fallback'] ?? 'Other';

        if (empty($rules)) return $fallback;

        $matched = [];
        foreach ($rules as $rule) {
            $name = $rule['name'] ?? 'Unknown';
            foreach ($order['line_items'] ?? [] as $li) {
                if (self::lineItemHit($li, $rule['match'] ?? '', $rule['value'] ?? '')) {
                    $matched[$name] = true;
                    break;
                }
            }
        }

        return $matched ? implode(' + ', array_keys($matched)) : $fallback;
    }

    /**
     * Returns missing required items for each matched rule type.
     *
     * Rules can optionally define a `required_items` array. When a rule triggers
     * (i.e. the order contains that type), every required item is checked against
     * the order's line items using the same match engine as classification rules.
     * Any item that cannot be matched is included in the returned array.
     *
     * Returns [] when no rule matches or all required items are present.
     * Format: [ 'RuleName' => ['Missing Label A', 'Missing Label B'], … ]
     *
     * Example order_types.json rule:
     *   {
     *     "name": "Pro", "match": "sku_starts_with", "value": "widget-pro-",
     *     "required_items": [
     *       { "label": "Carry Case",  "match": "title_contains", "value": "carry case" },
     *       { "label": "Power Cable", "match": "sku_starts_with", "value": "cable-pwr-" }
     *     ]
     *   }
     *
     * @return array<string, string[]>  rule name → list of missing item labels
     */
    public static function findMissingRequired(array $order): array
    {
        $config = self::getOrderTypesConfig();
        $rules  = $config['rules'] ?? [];
        $result = [];

        foreach ($rules as $rule) {
            $required = $rule['required_items'] ?? [];
            if (empty($required)) continue;

            // Skip if this rule doesn't match the order
            $typeMatched = false;
            foreach ($order['line_items'] ?? [] as $li) {
                if (self::lineItemHit($li, $rule['match'] ?? '', $rule['value'] ?? '')) {
                    $typeMatched = true;
                    break;
                }
            }
            if (!$typeMatched) continue;

            // Skip if any exclusion condition matches any line item
            foreach ($rule['exclude_if'] ?? [] as $excl) {
                foreach ($order['line_items'] ?? [] as $li) {
                    if (self::lineItemHit($li, $excl['match'] ?? '', $excl['value'] ?? '')) {
                        continue 3; // next rule
                    }
                }
            }

            // Collect required items absent from the order
            $missing = [];
            foreach ($required as $req) {
                $found = false;
                foreach ($order['line_items'] ?? [] as $li) {
                    if (self::lineItemHit($li, $req['match'] ?? '', $req['value'] ?? '')) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missing[] = $req['label'];
                }
            }

            if (!empty($missing)) {
                $result[$rule['name']] = $missing;
            }
        }

        return $result;
    }

    /**
     * Diffs what was ordered in Shopify against what ShipStation actually shipped,
     * at the SKU/quantity level. Catches picking errors: missing accessories, wrong
     * items substituted, or short quantities that the standard audit never checks
     * (that audit only confirms an order *exists* in both systems, not that the
     * physically shipped contents match).
     *
     * @param  array<string, mixed> $order   Shopify order (has line_items)
     * @param  array<int, array<string, mixed>> $ssItems  ShipStation order's raw `items` array
     * @return array{
     *     ordered: array<string, int>,
     *     shipped: array<string, int>,
     *     missing: array<string, int>,
     *     extra: array<string, int>,
     *     missingRequired: array<string, string[]>
     * }
     */
    public static function diffShippedItems(array $order, array $ssItems): array
    {
        $ordered = [];
        foreach ($order['line_items'] ?? [] as $li) {
            $sku = strtolower(trim((string) ($li['sku'] ?? '')));
            if ($sku === '') continue;
            $ordered[$sku] = ($ordered[$sku] ?? 0) + (int) ($li['quantity'] ?? 1);
        }

        $shipped = [];
        foreach ($ssItems as $item) {
            $sku = strtolower(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') continue;
            $shipped[$sku] = ($shipped[$sku] ?? 0) + (int) ($item['quantity'] ?? 1);
        }

        $missing = [];
        foreach ($ordered as $sku => $qty) {
            $shortfall = $qty - ($shipped[$sku] ?? 0);
            if ($shortfall > 0) $missing[$sku] = $shortfall;
        }

        $extra = [];
        foreach ($shipped as $sku => $qty) {
            $over = $qty - ($ordered[$sku] ?? 0);
            if ($over > 0) $extra[$sku] = $over;
        }

        // Only surface bundle gaps that are NEW at shipment time (not gaps the
        // order-level bundle check already flags — those are ordering gaps, not
        // picking/shipping gaps).
        $shippedOrder = ['line_items' => array_map(fn($item) => [
            'sku'   => $item['sku']  ?? '',
            'title' => $item['name'] ?? ($item['title'] ?? ''),
        ], $ssItems)];

        $orderLevelMissing   = self::findMissingRequired($order);
        $shippedLevelMissing = self::findMissingRequired($shippedOrder);

        $missingRequired = [];
        foreach ($shippedLevelMissing as $ruleName => $labels) {
            $new = array_values(array_diff($labels, $orderLevelMissing[$ruleName] ?? []));
            if (!empty($new)) {
                $missingRequired[$ruleName] = $new;
            }
        }

        return compact('ordered', 'shipped', 'missing', 'extra', 'missingRequired');
    }

    /**
     * Compares what ShipStation charged to ship a package (label cost + insurance)
     * against what the customer actually paid for shipping in Shopify. Surfaces
     * orders that quietly shipped at a loss - free-shipping promos, underpriced
     * flat-rate options, or zone surcharges on heavy/bulky items.
     *
     * Returns null for voided shipments (they never cost anything real).
     *
     * @param  array<string, mixed>              $shipment      ShipStation shipment record
     *                                                            (shipmentCost, insuranceCost, voided)
     * @param  array<int, array<string, mixed>>  $shippingLines Shopify order's shipping_lines (price per line)
     * @return array{shipCost: float, shippingCharged: float, loss: float}|null
     */
    public static function shippingLoss(array $shipment, array $shippingLines): ?array
    {
        if (($shipment['voided'] ?? false) === true) {
            return null;
        }

        $shipCost = (float) ($shipment['shipmentCost'] ?? 0) + (float) ($shipment['insuranceCost'] ?? 0);

        $shippingCharged = 0.0;
        foreach ($shippingLines as $line) {
            $shippingCharged += (float) ($line['price'] ?? 0);
        }

        $loss = $shipCost - $shippingCharged;

        return compact('shipCost', 'shippingCharged', 'loss');
    }

    // ── Private helpers ───────────────────────────────────────────────

    private static ?array $loadedConfig   = null;
    private static ?array $overrideConfig = null;

    /** Loads order_types.json once per process (or returns test override). */
    public static function getOrderTypesConfig(): array
    {
        if (self::$overrideConfig !== null) {
            return self::$overrideConfig;
        }
        if (self::$loadedConfig === null) {
            $file = __DIR__ . '/../order_types.json';
            self::$loadedConfig = file_exists($file)
                ? (json_decode(file_get_contents($file), true) ?: [])
                : [];
        }
        return self::$loadedConfig;
    }

    /** Inject config for tests - call resetOrderTypesConfig() in tearDown. */
    public static function setOrderTypesConfig(array $config): void
    {
        self::$overrideConfig = $config;
    }

    public static function resetOrderTypesConfig(): void
    {
        self::$overrideConfig = null;
    }

    /** Tests whether a single line item matches a rule's match type and value. */
    private static function lineItemHit(array $li, string $match, mixed $value): bool
    {
        $sku    = strtolower($li['sku']    ?? '');
        $title  = strtolower($li['title']  ?? '');
        $vendor = strtolower($li['vendor'] ?? '');

        return match($match) {
            'sku_starts_with'     => is_array($value)
                                        ? (bool)array_filter($value, fn($p) => str_starts_with($sku, strtolower((string)$p)))
                                        : str_starts_with($sku, strtolower((string)$value)),
            'sku_contains'        => str_contains($sku, strtolower((string)$value)),
            'sku_not_starts_with' => !array_filter((array)$value, fn($p) => str_starts_with($sku, strtolower($p))),
            'title_contains'      => str_contains($title, strtolower((string)$value)),
            'vendor_is'           => $vendor === strtolower((string)$value),
            default               => false,
        };
    }

    /**
     * Finds potential duplicate Shopify orders: same customer email + same rounded
     * total price, placed within 24 hours of each other.
     *
     * Returns only groups of 2+ orders, sorted by most-recent first.
     *
     * @param  array<int, array<string, mixed>> $shopifyOrders
     * @return array<int, array{email: string, amount: string, orders: array}>
     */
    public static function findDuplicates(array $shopifyOrders): array
    {
        $groups = [];
        foreach ($shopifyOrders as $order) {
            $email  = strtolower(trim($order['email'] ?? ''));
            $amount = round((float) ($order['total_price'] ?? 0), 0);
            if (!$email || $amount <= 0) continue;
            $key = $email . '|' . $amount;
            $groups[$key][] = $order;
        }

        $duplicates = [];
        foreach ($groups as $key => $orders) {
            if (count($orders) < 2) continue;

            // Sort by created_at ascending for window comparison
            usort($orders, fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));

            // Slide a 24-hour window: keep only clusters where at least two orders
            // are within 24 h of each other
            $cluster = [$orders[0]];
            for ($i = 1; $i < count($orders); $i++) {
                $prev = strtotime($orders[$i - 1]['created_at'] ?? '0');
                $curr = strtotime($orders[$i]['created_at']     ?? '0');
                if ($curr - $prev <= 86400) {
                    $cluster[] = $orders[$i];
                } else {
                    if (count($cluster) >= 2) {
                        [$email, $amount] = explode('|', $key);
                        $duplicates[] = ['email' => $email, 'amount' => $amount, 'orders' => array_reverse($cluster)];
                    }
                    $cluster = [$orders[$i]];
                }
            }
            if (count($cluster) >= 2) {
                [$email, $amount] = explode('|', $key);
                $duplicates[] = ['email' => $email, 'amount' => $amount, 'orders' => array_reverse($cluster)];
            }
        }

        // Sort by most recent duplicate first
        usort($duplicates, fn($a, $b) =>
            strcmp($b['orders'][0]['created_at'] ?? '', $a['orders'][0]['created_at'] ?? '')
        );

        return $duplicates;
    }
}
