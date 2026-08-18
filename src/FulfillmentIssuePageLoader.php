<?php
declare(strict_types=1);

/**
 * Loads fulfillment and shipping issue scan pages.
 */
class FulfillmentIssuePageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'onholdstall'  => self::loadOnHoldStall($action, $ctx),
            'notracking'   => self::loadNoTracking($action, $ctx),
            'postshipaddr' => self::loadPostShipAddrChange($action, $ctx),
            'ssshipped'    => self::loadSsShippedUnfulfilled($action, $ctx),
            'slabreaches'  => self::loadSlaBreaches($action, $ctx),
            'shipmentaging'=> self::loadShipmentAging($action, $ctx),
            'carrierperf'  => self::loadCarrierPerf($action, $ctx),
            'itemmismatch' => self::loadItemMismatch($action, $ctx),
            'shipmargin'   => self::loadShippingMargin($action, $ctx),
            'fulfilleditems' => self::loadFulfilledItems($action, $ctx),
            default        => [],
        };
    }

    private static function loadOnHoldStall(string $action, array $ctx): array
    {
        ['result' => $ohResult, 'error' => $ohError, 'start' => $ohStart, 'end' => $ohEnd] =
            ScanRunner::run($action, 'scan_onhold', $ctx, 'oh', function ($ctx, $start, $end) {
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $nodes   = self::suppressOutput(fn() => $shopify->fetchOnHoldFulfillmentOrders($start, $end));

                $rows = self::buildOnHoldStallRows($nodes, time());
                return ['rows' => $rows, 'start' => $start, 'end' => $end];
            }, 90);

        return compact('ohResult', 'ohError', 'ohStart', 'ohEnd');
    }

    /**
     * On-Hold Stall rows: one per fulfillment order currently on hold,
     * sorted by days_waiting descending. Only the first fulfillment hold's
     * reason/notes are surfaced per the documented "sorted by how long
     * waiting" behavior.
     *
     * @param  array<int, array<string, mixed>> $nodes shaped like Shopify::fetchOnHoldFulfillmentOrders()
     * @return array<int, array<string, mixed>>
     */
    private static function buildOnHoldStallRows(array $nodes, int $now): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            $order   = $node['order'];
            $created = $order['createdAt'] ?? '';
            $days    = $created ? (int)floor(($now - strtotime($created)) / 86400) : 0;
            $holds   = $node['fulfillmentHolds'] ?? [];
            $rows[] = [
                'shopify_id'   => $order['legacyResourceId']            ?? '',
                'order_number' => $order['name']                        ?? '',
                'created_at'   => self::dateOnly($created),
                'days_waiting' => $days,
                'email'        => $order['email']                       ?? '',
                'total'        => $order['totalPriceSet']['shopMoney']['amount'] ?? '',
                'financial'    => $order['displayFinancialStatus']      ?? '',
                'fulfillment'  => $order['displayFulfillmentStatus']    ?? '',
                'hold_reason'  => $holds[0]['reason']                  ?? '',
                'hold_notes'   => $holds[0]['reasonNotes']             ?? '',
            ];
        }
        usort($rows, fn($a, $b) => $b['days_waiting'] <=> $a['days_waiting']);
        return $rows;
    }

    private static function loadNoTracking(string $action, array $ctx): array
    {
        $ntThreshold = max(1, (int)($_POST['nt_threshold'] ?? $_GET['nt_threshold'] ?? 24));

        ['result' => $ntResult, 'error' => $ntError, 'start' => $ntStart, 'end' => $ntEnd] =
            ScanRunner::run($action, 'scan_notracking', $ctx, 'nt', function ($ctx, $start, $end) use (&$ntThreshold) {
                $ntThreshold = max(1, (int)($_POST['nt_threshold'] ?? 24));
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersFulfilledSince($start));

                $rows = self::buildNoTrackingRows($orders, $start, $end, $ntThreshold);
                return [
                    'rows'      => $rows,
                    'scanned'   => count($orders),
                    'start'     => $start,
                    'end'       => $end,
                    'threshold' => $ntThreshold,
                ];
            });

        return compact('ntResult', 'ntError', 'ntStart', 'ntEnd', 'ntThreshold');
    }

    /**
     * Pure row-builder for the no-tracking scan: keeps fulfillments shipped within
     * [startDate, endDate] (by the fulfillment's own created_at, not the order's,
     * so an order created before the window but shipped inside it is still checked)
     * that still have no tracking number after $threshold hours.
     *
     * Factored out from loadNoTracking() so it's testable without HTTP.
     *
     * @param  array<int, array<string, mixed>> $orders shaped like Shopify::fetchOrdersFulfilledSince()
     * @return array<int, array<string, mixed>>
     */
    private static function buildNoTrackingRows(array $orders, string $startDate, string $endDate, int $threshold): array
    {
        $rangeStart = "{$startDate}T00:00:00Z";
        $rangeEnd   = "{$endDate}T23:59:59Z";
        $now        = time();

        $rows = [];
        foreach ($orders as $o) {
            $missing = [];
            foreach ($o['fulfillments'] ?? [] as $f) {
                $createdAt = $f['created_at'] ?? '';
                if ($createdAt < $rangeStart || $createdAt > $rangeEnd) continue;
                if (trim($f['tracking_number'] ?? '') !== '') continue;
                $hoursAgo = $createdAt ? (int)(($now - strtotime($createdAt)) / 3600) : 0;
                if ($hoursAgo < $threshold) continue;
                $missing[] = [
                    'id'         => $f['id']       ?? '',
                    'created_at' => self::dateOnly($createdAt),
                    'hours_ago'  => $hoursAgo,
                    'status'     => $f['shipment_status'] ?? $f['status'] ?? '',
                    'company'    => $f['tracking_company'] ?? '',
                ];
            }
            if (empty($missing)) continue;
            $rows[] = [
                'shopify_id'   => $o['id']          ?? '',
                'order_number' => $o['name']        ?? '',
                'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                'email'        => $o['email']       ?? '',
                'total'        => $o['total_price'] ?? '',
                'financial'    => $o['financial_status']   ?? '',
                'fulfillment'  => $o['fulfillment_status'] ?? '',
                'missing'      => $missing,
            ];
        }
        usort($rows, fn($a, $b) => ($b['missing'][0]['hours_ago'] ?? 0) <=> ($a['missing'][0]['hours_ago'] ?? 0));
        return $rows;
    }

    private static function loadPostShipAddrChange(string $action, array $ctx): array
    {
        ['result' => $psResult, 'error' => $psError, 'start' => $psStart, 'end' => $psEnd] =
            ScanRunner::run($action, 'scan_postshipaddr', $ctx, 'ps', function ($ctx, $start, $end) {
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $entries = self::suppressOutput(fn() => $shopify->fetchPostShipAddressChanges($start, $end));

                $rows = self::buildPostShipAddrChangeRows($entries);
                return ['rows' => $rows, 'start' => $start, 'end' => $end];
            });

        return compact('psResult', 'psError', 'psStart', 'psEnd');
    }

    /**
     * Post-Ship Address Change rows: one per address-change event that
     * occurred after fulfillment, sorted by changed_at descending.
     * `mins_after_ship` is the gap between the fulfillment timestamp and the
     * change event (0 if either timestamp is missing/unparseable).
     *
     * @param  array<int, array{order: array<string, mixed>, changed_at: string, fulfillment_at: string}> $entries
     * @return array<int, array<string, mixed>>
     */
    private static function buildPostShipAddrChangeRows(array $entries): array
    {
        $rows = [];
        foreach ($entries as $e) {
            $o    = $e['order'];
            $addr = $o['shipping_address'] ?? null;
            $addrLine = $addr ? implode(', ', array_filter([
                $addr['address1']      ?? '',
                $addr['city']          ?? '',
                $addr['province_code'] ?? '',
                $addr['zip']           ?? '',
                $addr['country_code']  ?? '',
            ])) : '';
            $changedTs     = strtotime($e['changed_at']     ?? '');
            $fulfillTs     = strtotime($e['fulfillment_at'] ?? '');
            $minsAfterShip = ($changedTs && $fulfillTs) ? max(0, (int)(($changedTs - $fulfillTs) / 60)) : 0;
            $rows[] = [
                'shopify_id'      => $o['id']          ?? '',
                'order_number'    => $o['name']        ?? '',
                'created_at'      => self::dateOnly($o['created_at']     ?? ''),
                'fulfillment_at'  => self::dateOnly($e['fulfillment_at'] ?? ''),
                'changed_at'      => substr($e['changed_at'] ?? '', 0, 16),
                'mins_after_ship' => $minsAfterShip,
                'email'           => $o['email']       ?? '',
                'total'           => $o['total_price'] ?? '',
                'financial'       => $o['financial_status']   ?? '',
                'fulfillment'     => $o['fulfillment_status'] ?? '',
                'addr_name'       => trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                'addr_line'       => $addrLine,
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));
        return $rows;
    }

    private static function loadSsShippedUnfulfilled(string $action, array $ctx): array
    {
        $ssuResult = null;
        $ssuError  = '';
        [$ssuStart, $ssuEnd] = DateRange::fromRequest('ssu');

        ['result' => $ssuResult, 'error' => $ssuError, 'start' => $ssuStart, 'end' => $ssuEnd] =
            ScanRunner::run($action, 'scan_ssshipped', $ctx, 'ssu', function ($ctx, $start, $end) {
                self::setLimits(300);

                [$ssOrders, $shOrders] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    return [
                        $ss->fetchAllOrders($start, $end),
                        $shopify->fetchAllOrders($start, $end),
                    ];
                });

                $rows = self::buildSsShippedUnfulfilledRows($ssOrders, $shOrders);

                $shippedCount = count(array_filter($ssOrders, fn($o) => ($o['orderStatus'] ?? '') === 'shipped'));
                return [
                    'rows'          => $rows,
                    'shipped_total' => $shippedCount,
                    'start'         => $start,
                    'end'           => $end,
                ];
            }, 30, true);

        return compact('ssuResult', 'ssuError', 'ssuStart', 'ssuEnd');
    }

    /**
     * SS Shipped / Shopify Unfulfilled rows: ShipStation orders marked
     * 'shipped' whose matching Shopify order is NOT 'fulfilled' - a sync
     * failure between the two systems. Sorted by order_date descending.
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @param  array<int, array<string, mixed>> $shOrders
     * @return array<int, array<string, mixed>>
     */
    private static function buildSsShippedUnfulfilledRows(array $ssOrders, array $shOrders): array
    {
        $shIndex = [];
        foreach ($shOrders as $o) {
            $num = Comparator::normalise((string)($o['order_number'] ?? ltrim($o['name'] ?? '', '#')));
            if ($num) {
                $shIndex[$num] = [
                    'fulfillment_status' => $o['fulfillment_status'] ?? '',
                    'financial_status'   => $o['financial_status']   ?? '',
                    'shopify_id'         => $o['id']                 ?? '',
                ];
            }
        }

        $rows = [];
        foreach ($ssOrders as $o) {
            if (($o['orderStatus'] ?? '') !== 'shipped') continue;
            $num = Comparator::normalise((string)($o['orderNumber'] ?? ''));
            if (!$num || !isset($shIndex[$num])) continue;

            $sh            = $shIndex[$num];
            $shFulfillment = $sh['fulfillment_status'] ?? '';
            if ($shFulfillment === 'fulfilled') continue;

            $rows[] = [
                'ss_order_id'    => $o['orderId']      ?? '',
                'order_number'   => $o['orderNumber']  ?? '',
                'order_date'     => self::dateOnly($o['orderDate'] ?? ''),
                'customer'       => trim($o['shipTo']['name'] ?? ''),
                'email'          => $o['customerEmail'] ?? '',
                'total'          => $o['orderTotal']   ?? 0,
                'sh_fulfillment' => $shFulfillment ?: 'unfulfilled',
                'sh_financial'   => $sh['financial_status'] ?? '',
                'shopify_id'     => $sh['shopify_id'] ?? '',
                'ss_url'         => $o['orderId'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode((string)$o['orderId']) : null,
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['order_date'], $a['order_date']));
        return $rows;
    }

    private static function loadItemMismatch(string $action, array $ctx): array
    {
        $imResult = null;
        $imError  = '';
        [$imStart, $imEnd] = DateRange::fromRequest('im');

        ['result' => $imResult, 'error' => $imError, 'start' => $imStart, 'end' => $imEnd] =
            ScanRunner::run($action, 'scan_itemmismatch', $ctx, 'im', function ($ctx, $start, $end) {
                self::setLimits(300);

                [$ssOrders, $shOrders] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    return [
                        $ss->fetchAllOrders($start, $end),
                        $shopify->fetchAllOrders($start, $end),
                    ];
                });

                $rows = self::buildItemMismatchRows($ssOrders, $shOrders);

                return ['rows' => $rows, 'scanned' => count($ssOrders), 'start' => $start, 'end' => $end];
            }, 30, true);

        return compact('imResult', 'imError', 'imStart', 'imEnd');
    }

    /**
     * Pure row-builder for the item-mismatch scan: matches SS "shipped" orders to
     * their Shopify counterpart, skips orders that shouldn't have shipped anyway
     * (cancelled/refunded/voided/zero-value - mirrors Comparator::compare()'s skip
     * philosophy), diffs shipped-vs-ordered items, and keeps only rows with an
     * actual mismatch. Sorted so business-critical rows (missing required bundle
     * accessories) come first, then by mismatch size.
     *
     * Factored out from loadItemMismatch() so it's testable without HTTP.
     *
     * @param  array<int, array<string, mixed>> $ssOrders  raw ShipStation orders
     * @param  array<int, array<string, mixed>> $shOrders  raw Shopify orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildItemMismatchRows(array $ssOrders, array $shOrders): array
    {
        $shIndex = [];
        foreach ($shOrders as $o) {
            $num = Comparator::normalise((string)($o['order_number'] ?? ltrim($o['name'] ?? '', '#')));
            if ($num) {
                $shIndex[$num] = $o;
            }
        }

        $rows = [];
        foreach ($ssOrders as $o) {
            if (($o['orderStatus'] ?? '') !== 'shipped') continue;
            $num = Comparator::normalise((string)($o['orderNumber'] ?? ''));
            if (!$num || !isset($shIndex[$num])) continue;

            $shOrder = $shIndex[$num];

            // Mirror Comparator::compare()'s skip philosophy: an order that
            // never should have shipped (cancelled/refunded/free) isn't a
            // picking-error candidate.
            if (!empty($shOrder['cancelled_at'])) continue;
            if (in_array($shOrder['financial_status'] ?? '', ['refunded', 'voided'], true)) continue;
            if (isset($shOrder['total_price']) && (float)$shOrder['total_price'] === 0.0) continue;

            $diff = Comparator::diffShippedItems($shOrder, $o['items'] ?? []);
            if (empty($diff['missing']) && empty($diff['extra'])) continue;

            $missingRequiredFlat = [];
            foreach ($diff['missingRequired'] as $ruleName => $labels) {
                foreach ($labels as $label) {
                    $missingRequiredFlat[] = "{$ruleName}: {$label}";
                }
            }

            $rows[] = [
                'shopify_id'       => $shOrder['id']            ?? '',
                'order_number'     => $shOrder['order_number']  ?? $shOrder['name'] ?? '',
                'created_at'       => self::dateOnly($shOrder['created_at'] ?? ''),
                'email'            => $shOrder['email']         ?? '',
                'total'            => $shOrder['total_price']   ?? '',
                'order_type'       => Comparator::classifyOrder($shOrder),
                'ordered'          => $diff['ordered'],
                'shipped'          => $diff['shipped'],
                'missing'          => $diff['missing'],
                'extra'            => $diff['extra'],
                'missing_required' => $missingRequiredFlat,
                'ss_url'           => $o['orderId'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode((string)$o['orderId']) : null,
            ];
        }

        usort($rows, function ($a, $b) {
            $aHasReq = !empty($a['missing_required']);
            $bHasReq = !empty($b['missing_required']);
            if ($aHasReq !== $bHasReq) return $bHasReq <=> $aHasReq;
            $aCount = count($a['missing']) + count($a['extra']);
            $bCount = count($b['missing']) + count($b['extra']);
            return $bCount <=> $aCount;
        });

        return $rows;
    }

    private static function loadSlaBreaches(string $action, array $ctx): array
    {
        $slaThreshold = max(1, (int)($_POST['sla_threshold'] ?? $_GET['sla_threshold'] ?? 3));

        ['result' => $slaResult, 'error' => $slaError, 'start' => $slaStart, 'end' => $slaEnd] =
            ScanRunner::run($action, 'scan_sla', $ctx, 'sla', function ($ctx, $start, $end) use (&$slaThreshold) {
                $slaThreshold = max(1, (int)($_POST['sla_threshold'] ?? 3));
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForSla($start, $end));

                $rows = self::buildSlaBreachRows($orders, $slaThreshold, time());
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'threshold' => $slaThreshold];
            }, 30);

        return compact('slaResult', 'slaError', 'slaStart', 'slaEnd', 'slaThreshold');
    }

    /**
     * Fulfillment SLA Breaches rows: paid, non-cancelled orders whose
     * time-to-first-fulfillment (fulfilled orders) or time-since-placement
     * (still-open orders, measured against $now) meets or exceeds
     * $slaThreshold days, sorted by days descending.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildSlaBreachRows(array $orders, int $slaThreshold, int $now): array
    {
        $rows = [];
        foreach ($orders as $o) {
            if (!empty($o['cancelled_at'])) continue;
            if (in_array($o['financial_status'] ?? '', ['refunded', 'voided'], true)) continue;

            $createdTs = strtotime($o['created_at'] ?? '');
            if (!$createdTs) continue;

            $firstFulfillment = self::firstFulfillmentAt($o);
            $fulfilledTs      = $firstFulfillment ? strtotime($firstFulfillment) : null;
            $days             = $fulfilledTs
                ? (int) floor(($fulfilledTs - $createdTs) / 86400)
                : (int) floor(($now - $createdTs) / 86400);

            if ($days < $slaThreshold) continue;

            $addr = $o['shipping_address'] ?? [];
            $rows[] = [
                'shopify_id'   => $o['id'] ?? '',
                'order_number' => $o['name'] ?? '',
                'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                'fulfilled_at' => $firstFulfillment ? self::dateOnly($firstFulfillment) : '',
                'days'         => $days,
                'email'        => $o['email'] ?? '',
                'total'        => $o['total_price'] ?? '',
                'financial'    => $o['financial_status'] ?? '',
                'fulfillment'  => $o['fulfillment_status'] ?: 'unfulfilled',
                'method'       => self::shippingMethod($o),
                'region'       => self::addressRegion($addr),
                'order_type'   => Comparator::classifyOrder($o),
            ];
        }
        usort($rows, fn($a, $b) => $b['days'] <=> $a['days']);
        return $rows;
    }

    private static function loadShipmentAging(string $action, array $ctx): array
    {
        $saThreshold = max(1, (int)($_POST['sa_threshold'] ?? $_GET['sa_threshold'] ?? 3));
        $saResult = null;
        $saError  = '';

        if ($action === 'scan_shipmentaging') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);
            $saThreshold = max(1, (int)($_POST['sa_threshold'] ?? 3));
            if ($err = self::requireSS($ctx)) {
                $saError = $err;
                RunLog::append([
                    'tool'       => 'scan_shipmentaging',
                    'status'     => 'config_error',
                    'created_at' => $runStartedAt,
                    'duration'   => round(microtime(true) - $t0, 2),
                    'error'      => $saError,
                    'meta'       => ['threshold' => $saThreshold],
                ]);
            } else {
                try {
                    self::setLimits(180);
                    $ss     = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], null, $ctx['httpStack'] ?? null);
                    $orders = self::suppressOutput(fn() => $ss->fetchAwaitingOrders());

                    [$rows, $bySku, $byType] = self::buildShipmentAgingData($orders, $saThreshold, time());
                    $saResult = [
                        'rows'      => $rows,
                        'scanned'   => count($orders),
                        'threshold' => $saThreshold,
                        'by_sku'    => $bySku,
                        'by_type'   => $byType,
                    ];
                    RunLog::append([
                        'tool'       => 'scan_shipmentaging',
                        'status'     => count($rows) > 0 ? 'issues_found' : 'ok',
                        'created_at' => $runStartedAt,
                        'duration'   => round(microtime(true) - $t0, 2),
                        'scanned'    => count($orders),
                        'rows_found' => count($rows),
                        'meta'       => ['threshold' => $saThreshold],
                    ]);
                } catch (Throwable $e) {
                    $saError = $e->getMessage();
                    RunLog::append([
                        'tool'       => 'scan_shipmentaging',
                        'status'     => 'error',
                        'created_at' => $runStartedAt,
                        'duration'   => round(microtime(true) - $t0, 2),
                        'error'      => $saError,
                        'meta'       => ['threshold' => $saThreshold],
                    ]);
                }
            }
        }

        return compact('saResult', 'saError', 'saThreshold');
    }

    /**
     * Shipment Aging rows + by_sku/by_type aggregates from the live SS
     * awaiting-shipment queue. A synthetic order (SS line items mapped to
     * Shopify-shaped line_items) is built per SS order so
     * Comparator::classifyOrder() can be reused for order-type grouping.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>} [rows, bySku, byType]
     */
    private static function buildShipmentAgingData(array $orders, int $saThreshold, int $now): array
    {
        $rows   = [];
        $bySku  = [];
        $byType = [];

        foreach ($orders as $o) {
            $dateRaw = $o['orderDate'] ?? $o['createDate'] ?? '';
            $orderTs = strtotime($dateRaw);
            if (!$orderTs) continue;
            $days = (int)floor(($now - $orderTs) / 86400);
            if ($days < $saThreshold) continue;

            $items = $o['items'] ?? [];
            $fakeOrder = ['line_items' => array_map(fn($item) => [
                'sku'   => $item['sku'] ?? '',
                'title' => $item['name'] ?? '',
            ], $items)];
            $orderType = Comparator::classifyOrder($fakeOrder);

            $skus = [];
            foreach ($items as $item) {
                $sku = trim((string)($item['sku'] ?? ''));
                if ($sku === '') continue;
                $qty = (int)($item['quantity'] ?? 1);
                $skus[$sku] = ($skus[$sku] ?? 0) + $qty;
                if (!isset($bySku[$sku])) $bySku[$sku] = ['sku' => $sku, 'orders' => 0, 'qty' => 0, 'oldest_days' => 0];
                $bySku[$sku]['qty'] += $qty;
                $bySku[$sku]['oldest_days'] = max($bySku[$sku]['oldest_days'], $days);
            }
            foreach (array_keys($skus) as $sku) {
                $bySku[$sku]['orders']++;
            }
            if (!isset($byType[$orderType])) $byType[$orderType] = ['type' => $orderType, 'orders' => 0, 'oldest_days' => 0];
            $byType[$orderType]['orders']++;
            $byType[$orderType]['oldest_days'] = max($byType[$orderType]['oldest_days'], $days);

            $rows[] = [
                'ss_order_id'  => $o['orderId'] ?? '',
                'order_number' => $o['orderNumber'] ?? '',
                'order_date'   => self::dateOnly($dateRaw),
                'days'         => $days,
                'customer'     => trim($o['shipTo']['name'] ?? ''),
                'email'        => $o['customerEmail'] ?? '',
                'total'        => $o['orderTotal'] ?? '',
                'status'       => $o['orderStatus'] ?? '',
                'order_type'   => $orderType,
                'skus'         => $skus,
            ];
        }
        usort($rows, fn($a, $b) => $b['days'] <=> $a['days']);
        usort($bySku, fn($a, $b) => $b['oldest_days'] <=> $a['oldest_days'] ?: $b['orders'] <=> $a['orders']);
        usort($byType, fn($a, $b) => $b['oldest_days'] <=> $a['oldest_days'] ?: $b['orders'] <=> $a['orders']);

        return [$rows, array_values($bySku), array_values($byType)];
    }

    private static function loadCarrierPerf(string $action, array $ctx): array
    {
        $cpResult = null;
        $cpError  = '';
        [$cpStart, $cpEnd] = DateRange::fromRequest('cp', 30);

        ['result' => $cpResult, 'error' => $cpError, 'start' => $cpStart, 'end' => $cpEnd] =
            ScanRunner::run($action, 'scan_carrierperf', $ctx, 'cp', function ($ctx, $start, $end) {
                self::setLimits(240);
                $ss        = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                $shipments = self::suppressOutput(fn() => $ss->fetchShipmentsByDate($start, $end));

                $rows = self::buildCarrierPerfRows($shipments);

                return [
                    'rows'     => $rows,
                    'scanned'  => count($shipments),
                    'start'    => $start,
                    'end'      => $end,
                ];
            }, 30, true);

        return compact('cpResult', 'cpError', 'cpStart', 'cpEnd');
    }

    /**
     * Carrier Performance rows: one per carrier code seen in the shipment
     * set, with average delivery days and late-rate over shipments that
     * have both a ship date and a delivery date (`with_delivery`).
     * Shipments missing either date, or with a delivery date before the
     * ship date (bad data), don't count toward avg_days/late_pct but do
     * count toward `count`. "Late" is a fixed >5-day threshold. Sorted by
     * shipment count descending. A missing/blank carrierCode groups under
     * 'Unknown'.
     *
     * @param  array<int, array<string, mixed>> $shipments
     * @return array<int, array<string, mixed>>
     */
    private static function buildCarrierPerfRows(array $shipments): array
    {
        $byCarrier = [];
        foreach ($shipments as $s) {
            $carrier = trim((string)($s['carrierCode'] ?? 'Unknown'));
            if ($carrier === '') $carrier = 'Unknown';

            if (!isset($byCarrier[$carrier])) {
                $byCarrier[$carrier] = [
                    'carrier'         => $carrier,
                    'count'           => 0,
                    'with_delivery'   => 0,
                    'total_days'      => 0,
                    'late_count'      => 0,
                ];
            }

            $byCarrier[$carrier]['count']++;

            $shipDateTs    = $s['shipDate']     ? strtotime($s['shipDate'])     : null;
            $deliveryDateTs = $s['deliveryDate'] ? strtotime($s['deliveryDate']) : null;

            if ($shipDateTs && $deliveryDateTs && $deliveryDateTs >= $shipDateTs) {
                $days = (int) ceil(($deliveryDateTs - $shipDateTs) / 86400);
                $byCarrier[$carrier]['total_days']    += $days;
                $byCarrier[$carrier]['with_delivery'] += 1;
                if ($days > 5) {
                    $byCarrier[$carrier]['late_count']++;
                }
            }
        }

        $rows = [];
        foreach ($byCarrier as $carrier => $stat) {
            $count       = $stat['count'];
            $withDel     = $stat['with_delivery'];
            $avgDays     = $withDel > 0 ? round($stat['total_days'] / $withDel, 1) : null;
            $latePct     = $withDel > 0 ? round($stat['late_count'] / $withDel * 100, 1) : null;

            $rows[] = [
                'carrier'          => $carrier,
                'count'            => $count,
                'with_delivery'    => $withDel,
                'avg_days'         => $avgDays,
                'late_pct'         => $latePct,
                'late_count'       => $stat['late_count'],
            ];
        }

        usort($rows, fn($a, $b) => $b['count'] <=> $a['count']);
        return $rows;
    }

    private static function loadShippingMargin(string $action, array $ctx): array
    {
        $smThreshold = max(1, (int)($_POST['sm_threshold'] ?? $_GET['sm_threshold'] ?? 15));
        $smResult = null;
        $smError  = '';
        [$smStart, $smEnd] = DateRange::fromRequest('sm', 30);

        ['result' => $smResult, 'error' => $smError, 'start' => $smStart, 'end' => $smEnd] =
            ScanRunner::run($action, 'scan_shipmargin', $ctx, 'sm', function ($ctx, $start, $end) use (&$smThreshold) {
                $smThreshold = max(1, (int)($_POST['sm_threshold'] ?? 15));
                self::setLimits(240);

                [$shipments, $shOrders] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    return [
                        $ss->fetchShipmentsByDate($start, $end),
                        $shopify->fetchOrdersFulfilledSinceWithShipping($start),
                    ];
                });

                $rows      = self::buildShippingMarginRows($shipments, $shOrders, (float) $smThreshold);
                $byCarrier = self::buildShippingMarginCarrierSummary($rows);

                return [
                    'rows'       => $rows,
                    'by_carrier' => $byCarrier,
                    'scanned'    => count($shipments),
                    'start'      => $start,
                    'end'        => $end,
                    'threshold'  => $smThreshold,
                ];
            }, 30, true);

        return compact('smResult', 'smError', 'smStart', 'smEnd', 'smThreshold');
    }

    /**
     * Pure row-builder for the shipping-margin scan: matches ShipStation shipments to
     * their Shopify order by normalised order number, computes label cost vs. shipping
     * charged via Comparator::shippingLoss(), and keeps only shipments that lost more
     * than $threshold. Voided shipments and shipments with no Shopify match are skipped.
     * Sorted by loss descending.
     *
     * Factored out from loadShippingMargin() so it's testable without HTTP.
     *
     * @param  array<int, array<string, mixed>> $shipments  raw ShipStation shipments
     * @param  array<int, array<string, mixed>> $shOrders   raw Shopify orders
     * @param  float                            $threshold  minimum loss (in dollars) to include
     * @return array<int, array<string, mixed>>
     */
    private static function buildShippingMarginRows(array $shipments, array $shOrders, float $threshold): array
    {
        $shIndex = [];
        foreach ($shOrders as $o) {
            $num = Comparator::normalise((string)($o['order_number'] ?? ltrim($o['name'] ?? '', '#')));
            if ($num) {
                $shIndex[$num] = $o;
            }
        }

        $rows = [];
        foreach ($shipments as $s) {
            $num = Comparator::normalise((string)($s['orderNumber'] ?? ''));
            if (!$num || !isset($shIndex[$num])) continue;

            $shOrder = $shIndex[$num];
            $diff    = Comparator::shippingLoss($s, $shOrder['shipping_lines'] ?? []);
            if ($diff === null) continue;
            if ($diff['loss'] <= $threshold) continue;

            $carrier = trim((string)($s['carrierCode'] ?? 'Unknown'));
            if ($carrier === '') $carrier = 'Unknown';

            $rows[] = [
                'shopify_id'       => $shOrder['id']    ?? '',
                'order_number'     => $s['orderNumber'] ?? '',
                'ship_date'        => self::dateOnly($s['shipDate'] ?? ''),
                'carrier'          => $carrier,
                'service'          => $s['serviceCode'] ?? '',
                'ship_cost'        => $diff['shipCost'],
                'shipping_charged' => $diff['shippingCharged'],
                'loss'             => $diff['loss'],
                'email'            => $shOrder['email']       ?? '',
                'total'            => $shOrder['total_price'] ?? '',
                'ss_url'           => ($s['orderId'] ?? null)
                    ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode((string)$s['orderId'])
                    : null,
            ];
        }

        usort($rows, fn($a, $b) => $b['loss'] <=> $a['loss']);

        return $rows;
    }

    /**
     * Aggregates shipping-margin rows by carrier: shipment count, total loss and
     * average loss. Sorted by total loss descending, mirroring the aggregation
     * style already used in loadCarrierPerf().
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function buildShippingMarginCarrierSummary(array $rows): array
    {
        $byCarrier = [];
        foreach ($rows as $row) {
            $carrier = $row['carrier'];
            if (!isset($byCarrier[$carrier])) {
                $byCarrier[$carrier] = ['carrier' => $carrier, 'count' => 0, 'total_loss' => 0.0];
            }
            $byCarrier[$carrier]['count']++;
            $byCarrier[$carrier]['total_loss'] += $row['loss'];
        }

        $summary = [];
        foreach ($byCarrier as $stat) {
            $summary[] = [
                'carrier'    => $stat['carrier'],
                'count'      => $stat['count'],
                'total_loss' => round($stat['total_loss'], 2),
                'avg_loss'   => round($stat['total_loss'] / $stat['count'], 2),
            ];
        }

        usort($summary, fn($a, $b) => $b['total_loss'] <=> $a['total_loss']);

        return $summary;
    }

    private static function firstFulfillmentAt(array $order): string
    {
        $first = '';
        foreach ($order['fulfillments'] ?? [] as $f) {
            $ts = $f['created_at'] ?? '';
            if ($ts && (!$first || $ts < $first)) $first = $ts;
        }
        return $first;
    }

    private static function shippingMethod(array $order): string
    {
        $line = ($order['shipping_lines'] ?? [])[0] ?? [];
        return trim((string)($line['title'] ?? $line['code'] ?? 'Unknown'));
    }

    private static function addressRegion(?array $addr): string
    {
        if (!$addr) return 'Unknown';
        return implode(', ', array_filter([
            $addr['province_code'] ?? $addr['province'] ?? '',
            $addr['country_code'] ?? $addr['country'] ?? '',
        ])) ?: 'Unknown';
    }

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
    }

    private static function requireSS(array $ctx): ?string
    {
        return (!$ctx['ssKey'] || !$ctx['ssSecret'])
            ? 'SS_API_KEY / SS_API_SECRET not set in .env.'
            : null;
    }

    private static function suppressOutput(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }

    private static function loadFulfilledItems(string $action, array $ctx): array
    {
        $fiMode = self::fulfilledItemsMode();
        $fiShowOrders = $fiMode === 'by_order';
        $fiGroupProducts = $fiMode === 'grouped';

        ['result' => $fiResult, 'error' => $fiError, 'start' => $fiStart, 'end' => $fiEnd] =
            ScanRunner::run($action, 'scan_fulfilleditems', $ctx, 'fi', function ($ctx, $start, $end) use ($fiMode, $fiShowOrders) {
                self::setLimits(240);
                $data = self::fetchFulfilledItems($ctx, $start, $end, $fiMode);
                return ['rows' => $data['rows'], 'scanned' => $data['scanned'], 'start' => $start, 'end' => $end, 'mode' => $fiMode, 'byOrder' => $fiShowOrders];
            }, 30);

        $fiEmailMessage = '';
        $fiEmailError   = '';

        if ($action === 'email_fulfilleditems') {
            $notifier = $ctx['emailNotifier'] ?? EmailNotifier::fromEnvironment();

            if ($err = DateRange::validate($fiStart, $fiEnd)) {
                $fiEmailError = $err;
            } elseif (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
                $fiEmailError = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
            } elseif (!$notifier) {
                $fiEmailError = 'SMTP_HOST / ALERT_EMAIL not set in .env.';
            } else {
                try {
                    self::setLimits(240);
                    $data     = self::fetchFulfilledItems($ctx, $fiStart, $fiEnd, $fiMode);
                    $filename = "fulfilled_items_{$fiStart}_to_{$fiEnd}.csv";
                    $subject  = "Fulfilled Items Report ({$fiStart} \u{2192} {$fiEnd})";

                    if ($fiMode === 'grouped') {
                        $body = ItemizedFulfillmentReport::groupedEmailHtml($data['rows'], $fiStart, $fiEnd);
                        $csv  = ItemizedFulfillmentReport::toGroupedCsvString($data['rows']);
                    } elseif ($fiShowOrders) {
                        $body = ItemizedFulfillmentReport::detailedEmailHtml($data['rows'], $fiStart, $fiEnd);
                        $csv  = ItemizedFulfillmentReport::toDetailedCsvString($data['rows']);
                    } else {
                        $body = ItemizedFulfillmentReport::emailHtml($data['totals'], $fiStart, $fiEnd);
                        $csv  = ItemizedFulfillmentReport::toCsvString($data['totals']);
                    }

                    $notifier->sendReport($subject, $body, $filename, $csv);

                    $fiEmailMessage = 'Emailed to ' . getenv('ALERT_EMAIL') . '.';
                    $fiResult       = ['rows' => $data['rows'], 'scanned' => $data['scanned'], 'start' => $fiStart, 'end' => $fiEnd, 'mode' => $fiMode, 'byOrder' => $fiShowOrders];
                } catch (Throwable $e) {
                    $fiEmailError = $e->getMessage();
                }
            }
        }

        return compact('fiResult', 'fiError', 'fiStart', 'fiEnd', 'fiEmailMessage', 'fiEmailError', 'fiShowOrders', 'fiGroupProducts', 'fiMode');
    }

    /**
     * @return array{rows: array<int, array{product: string, quantity: int}>|array<int, array{order: string, product: string, quantity: int}>|array<int, array{product: string, quantity: int, orders: string}>, totals: array<string, int>, scanned: int}
     */
    private static function fetchFulfilledItems(array $ctx, string $start, string $end, string $mode = 'summary'): array
    {
        $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], null, $ctx['httpStack'] ?? null);
        $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersFulfilledSince($start));
        $totals  = ItemizedFulfillmentReport::aggregate($orders, $start, $end);

        if ($mode === 'grouped') {
            $rows = ItemizedFulfillmentReport::groupByProductWithOrders($orders, $start, $end);
        } elseif ($mode === 'by_order') {
            $rows = ItemizedFulfillmentReport::itemizeByOrder($orders, $start, $end);
        } else {
            $rows = [];
            foreach ($totals as $label => $qty) {
                $rows[] = ['product' => $label, 'quantity' => $qty];
            }
        }

        return ['rows' => $rows, 'totals' => $totals, 'scanned' => count($orders)];
    }

    private static function fulfilledItemsMode(): string
    {
        $mode = (string)($_POST['fi_mode'] ?? $_GET['fi_mode'] ?? '');
        if (in_array($mode, ['summary', 'by_order', 'grouped'], true)) {
            return $mode;
        }
        if (!empty($_POST['fi_group_products']) || !empty($_GET['fi_group_products'])) {
            return 'grouped';
        }
        if (!empty($_POST['fi_show_orders']) || !empty($_GET['fi_show_orders'])) {
            return 'by_order';
        }
        return 'summary';
    }
}
