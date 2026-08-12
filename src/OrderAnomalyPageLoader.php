<?php
declare(strict_types=1);

/**
 * Loads order anomaly scan pages.
 */
class OrderAnomalyPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'addrcheck'     => self::loadAddrCheck($action, $ctx),
            'refunds'       => self::loadRefunds($action, $ctx),
            'dupes'         => self::loadDuplicates($action, $ctx),
            'orphans'       => self::loadOrphans($action, $ctx),
            'repeatrefunds' => self::loadRepeatRefunds($action, $ctx),
            'failedship'    => self::loadFailedShipments($action, $ctx),
            'addrchanges'   => self::loadAddrChanges($action, $ctx),
            default         => [],
        };
    }

    private static function loadAddrCheck(string $action, array $ctx): array
    {
        $unfulfilledOnly = (bool)($_POST['unfulfilled_only'] ?? false);
        $poBoxOnly = (bool)($_POST['po_box_only'] ?? false);

        ['result' => $addrResult, 'error' => $addrError, 'start' => $addrStart, 'end' => $addrEnd] =
            ScanRunner::run($action, 'scan_addresses', $ctx, 'addr', function ($ctx, $start, $end) use (&$unfulfilledOnly, &$poBoxOnly) {
                $unfulfilledOnly = (bool)($_POST['unfulfilled_only'] ?? false);
                $poBoxOnly = (bool)($_POST['po_box_only'] ?? false);
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders = self::suppressOutput(fn() => $shopify->fetchOrdersForAddressScan($start, $end, $unfulfilledOnly));

                $rows = self::buildAddrCheckRows($orders, $poBoxOnly);
                return [
                    'rows'        => $rows,
                    'scanned'     => count($orders),
                    'start'       => $start,
                    'end'         => $end,
                    'critical'    => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
                    'warnings'    => count(array_filter($rows, fn($r) => $r['severity'] === 'warning')),
                    'po_box_only' => $poBoxOnly,
                ];
            });

        return compact('addrResult', 'addrError', 'addrStart', 'addrEnd', 'poBoxOnly', 'unfulfilledOnly');
    }

    /**
     * Address Scanner rows: orders with at least one address issue, sorted
     * critical-first, optionally filtered to only PO-box-related issues.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildAddrCheckRows(array $orders, bool $poBoxOnly): array
    {
        $rows = [];
        foreach ($orders as $o) {
            $addr = $o['shipping_address'] ?? null;
            $issues = self::checkAddress($addr, $o);
            if (!empty($issues)) {
                $rows[] = [
                    'shopify_id'   => $o['id'] ?? '',
                    'order_number' => $o['name'] ?? '',
                    'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                    'email'        => $o['email'] ?? '',
                    'address'      => $addr,
                    'issues'       => $issues,
                    'severity'     => in_array('critical', array_column($issues, 'level')) ? 'critical' : 'warning',
                ];
            }
        }
        usort($rows, fn($a, $b) =>
            ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1)
        );
        if ($poBoxOnly) {
            $rows = array_values(array_filter($rows, function ($r) {
                foreach ($r['issues'] as $issue) {
                    if (in_array($issue['code'], ['po_box', 'po_box_carrier'], true)) return true;
                }
                return false;
            }));
        }
        return $rows;
    }

    private static function checkAddress(?array $addr, array $order): array
    {
        $issues = [];

        if (!$addr) {
            $issues[] = ['level' => 'critical', 'code' => 'no_address', 'message' => 'No shipping address on this order'];
            return $issues;
        }

        // Cast every field to string first - a malformed payload can hand us
        // an int (e.g. a numeric ZIP), which would TypeError on trim()
        // under strict_types otherwise.
        $name = trim((string)($addr['first_name'] ?? '') . ' ' . (string)($addr['last_name'] ?? ''));
        $address1 = trim((string)($addr['address1'] ?? ''));
        $city = trim((string)($addr['city'] ?? ''));
        $zip = trim((string)($addr['zip'] ?? ''));
        $country = strtoupper(trim((string)($addr['country_code'] ?? $addr['country'] ?? '')));
        $province = trim((string)($addr['province_code'] ?? ''));
        $phone = trim((string)($addr['phone'] ?? ''));

        if (!$name || $name === ' ') {
            $issues[] = ['level' => 'critical', 'code' => 'no_name', 'message' => 'Missing recipient name'];
        }
        if (!$address1) {
            $issues[] = ['level' => 'critical', 'code' => 'no_address1', 'message' => 'Missing street address'];
        } elseif (strlen($address1) < 5) {
            $issues[] = ['level' => 'warning', 'code' => 'short_address', 'message' => 'Street address is suspiciously short'];
        }
        if (!$city) {
            $issues[] = ['level' => 'critical', 'code' => 'no_city', 'message' => 'Missing city'];
        }
        if (!$zip) {
            $issues[] = ['level' => 'critical', 'code' => 'no_zip', 'message' => 'Missing postal / ZIP code'];
        } elseif ($country === 'US' && !preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
            $issues[] = ['level' => 'warning', 'code' => 'bad_zip_us', 'message' => 'US ZIP code format invalid (expected 12345 or 12345-6789)'];
        } elseif ($country === 'CA' && !preg_match('/^[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d$/', $zip)) {
            $issues[] = ['level' => 'warning', 'code' => 'bad_zip_ca', 'message' => 'Canadian postal code format invalid (expected A1A 1A1)'];
        }
        if (!$country) {
            $issues[] = ['level' => 'critical', 'code' => 'no_country', 'message' => 'Missing country'];
        }
        if (in_array($country, ['US', 'CA'], true) && !$province) {
            $issues[] = ['level' => 'warning', 'code' => 'no_province', 'message' => 'Missing state / province (required for US and CA)'];
        }
        if (!$phone) {
            $shippingTitles = implode(' ', array_column($order['shipping_lines'] ?? [], 'title'));
            if (preg_match('/overnight|express|priority|fedex|ups/i', $shippingTitles)) {
                $issues[] = ['level' => 'warning', 'code' => 'no_phone_express', 'message' => 'No phone number - carrier may require it for express shipping'];
            }
        }
        if ($address1 && preg_match('/\bbox\b/i', $address1)) {
            $shippingTitles = implode(' ', array_column($order['shipping_lines'] ?? [], 'title'));
            if (preg_match('/fedex|ups|dhl/i', $shippingTitles)) {
                $issues[] = ['level' => 'warning', 'code' => 'po_box_carrier', 'message' => 'PO Box - carrier cannot deliver (FedEx/UPS/DHL do not deliver to PO Boxes)'];
            } else {
                $issues[] = ['level' => 'warning', 'code' => 'po_box', 'message' => 'PO Box address - confirm your shipping carrier accepts PO Box deliveries'];
            }
        }

        return $issues;
    }

    private static function loadRefunds(string $action, array $ctx): array
    {
        ['result' => $refundsResult, 'error' => $refundsError, 'start' => $refundsStart, 'end' => $refundsEnd] =
            ScanRunner::run($action, 'find_refunds', $ctx, 'refunds', function ($ctx, $start, $end) {
                self::setLimits(300);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                $refundedOrders = self::suppressOutput(fn() => $shopify->fetchRefundedOrders($start, $end));

                $ssEnd = date('Y-m-d', strtotime($end . ' +7 days'));
                $ssRows = [];
                if ($ctx['ssKey'] && $ctx['ssSecret']) {
                    $ssRows = self::suppressOutput(function () use ($ctx, $start, $ssEnd) {
                        $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                        return $ss->fetchAllOrders($start, $ssEnd);
                    });
                }

                $rows = self::buildRefundRows($refundedOrders, $ssRows);
                return [
                    'rows'    => $rows,
                    'start'   => $start,
                    'end'     => $end,
                    'has_ss'  => !empty($ssRows),
                    'active'  => count(array_filter($rows, fn($r) => $r['risk'] === 'active')),
                    'missing' => count(array_filter($rows, fn($r) => $r['risk'] === 'missing')),
                ];
            });

        return compact('refundsResult', 'refundsError', 'refundsStart', 'refundsEnd');
    }

    /**
     * Refunds Tracker rows: one per refunded Shopify order, cross-checked
     * against the matching ShipStation order(s) to flag whether the refund
     * still has an active (unshipped/on-hold) ShipStation counterpart.
     *
     * `refunded_amount` prefers summing refund_line_items subtotals; if that
     * comes out to 0 on a fully-refunded order (e.g. a refund with no line
     * items, just a manual adjustment), it falls back to total_price.
     *
     * @param  array<int, array<string, mixed>> $refundedOrders
     * @param  array<int, array<string, mixed>> $ssRows
     * @return array<int, array<string, mixed>>
     */
    private static function buildRefundRows(array $refundedOrders, array $ssRows): array
    {
        $ssIndex = [];
        foreach ($ssRows as $ssO) {
            $num = Comparator::normalise((string)($ssO['orderNumber'] ?? ''));
            if ($num) $ssIndex[$num][] = $ssO;
        }

        $rows = [];
        foreach ($refundedOrders as $o) {
            $num = Comparator::normalise((string)($o['order_number'] ?? ltrim($o['name'] ?? '', '#')));
            $ssMatch = $ssIndex[$num] ?? [];

            $refundedAmt = 0.0;
            foreach ($o['refunds'] ?? [] as $ref) {
                foreach ($ref['refund_line_items'] ?? [] as $rli) {
                    $refundedAmt += (float)($rli['subtotal'] ?? 0);
                }
            }
            if ($refundedAmt == 0 && ($o['financial_status'] ?? '') === 'refunded') {
                $refundedAmt = (float)($o['total_price'] ?? 0);
            }

            $ssStatuses = array_map(fn($s) => $s['orderStatus'] ?? 'unknown', $ssMatch);
            $anyActive = !empty(array_filter($ssStatuses, fn($s) => in_array($s, ['awaiting_shipment', 'awaiting_payment', 'on_hold'], true)));

            $risk = 'ok';
            if (empty($ssMatch)) $risk = 'missing';
            elseif ($anyActive) $risk = 'active';

            $rows[] = [
                'shopify_id'       => $o['id'] ?? '',
                'order_number'     => $o['name'] ?? ('#' . $num),
                'created_at'       => self::dateOnly($o['created_at'] ?? ''),
                'email'            => $o['email'] ?? '',
                'financial_status' => $o['financial_status'] ?? '',
                'total_price'      => (float)($o['total_price'] ?? 0),
                'refunded_amount'  => $refundedAmt,
                'ss_orders'        => $ssMatch,
                'ss_statuses'      => $ssStatuses,
                'risk'             => $risk,
            ];
        }
        usort($rows, function ($a, $b) {
            $rankOf = fn($r) => match($r) { 'active' => 0, 'missing' => 1, default => 2 };
            return $rankOf($a['risk']) <=> $rankOf($b['risk']);
        });
        return $rows;
    }

    private static function loadDuplicates(string $action, array $ctx): array
    {
        $dupesResult = null;
        $dupesError = '';
        [$dupesStart, $dupesEnd] = DateRange::fromRequest('dupes');

        if ($action === 'find_dupes') {
            $dupesStart = trim($_POST['dupes_start'] ?? '');
            $dupesEnd = trim($_POST['dupes_end'] ?? '');

            if ($err = self::requireShopify($ctx)) {
                $dupesError = $err;
            } elseif ($err = DateRange::validate($dupesStart, $dupesEnd)) {
                $dupesError = $err;
            } else {
                try {
                    self::setLimits(300);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $dupesResult = $shopify->findDuplicateOrders($dupesStart, $dupesEnd);
                    $dupesResult['start'] = $dupesStart;
                    $dupesResult['end'] = $dupesEnd;
                } catch (Throwable $e) {
                    $dupesError = $e->getMessage();
                }
            }
        }

        return compact('dupesResult', 'dupesError', 'dupesStart', 'dupesEnd');
    }

    private static function loadOrphans(string $action, array $ctx): array
    {
        $orphanResult = null;
        $orphanError = '';
        [$orphanStart, $orphanEnd] = DateRange::fromRequest('orphan');

        ['result' => $orphanResult, 'error' => $orphanError, 'start' => $orphanStart, 'end' => $orphanEnd] =
            ScanRunner::run($action, 'find_orphans', $ctx, 'orphan', function ($ctx, $start, $end) {
                self::setLimits(300);
                [$ssOrders, $shOrders] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    return [$ss->fetchAllOrders($start, $end), $shopify->fetchAllOrders($start, $end)];
                });

                return [
                    'rows'     => self::buildOrphanRows($ssOrders, $shOrders),
                    'ss_total' => count($ssOrders),
                    'sh_total' => count($shOrders),
                    'start'    => $start,
                    'end'      => $end,
                ];
            }, 30, true);

        return compact('orphanResult', 'orphanError', 'orphanStart', 'orphanEnd');
    }

    /**
     * SS orders with no matching Shopify order, sorted by order_date desc.
     *
     * Matches via Comparator::orderNumberKeys() - the same digit-run
     * extraction used by the main audit engine's buildSSIndex() - so that
     * compound SS order numbers (e.g. "100042-B2") resolve to the same
     * Shopify counterpart instead of false-positiving as orphans.
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @param  array<int, array<string, mixed>> $shOrders
     * @return array<int, array<string, mixed>>
     */
    private static function buildOrphanRows(array $ssOrders, array $shOrders): array
    {
        $shIndex = [];
        foreach ($shOrders as $o) {
            $num = Comparator::normalise((string)($o['order_number'] ?? ltrim($o['name'] ?? '', '#')));
            if ($num) $shIndex[$num] = true;
        }

        $rows = [];
        foreach ($ssOrders as $o) {
            $raw = (string)($o['orderNumber'] ?? '');
            $keys = Comparator::orderNumberKeys($raw);
            if (empty($keys)) continue;
            $matched = false;
            foreach ($keys as $key) {
                if (isset($shIndex[$key])) { $matched = true; break; }
            }
            if ($matched) continue;
            $rows[] = [
                'ss_order_id'  => $o['orderId']     ?? '',
                'order_number' => $o['orderNumber'] ?? '',
                'order_status' => $o['orderStatus'] ?? '',
                'order_date'   => self::dateOnly($o['orderDate'] ?? ''),
                'customer'     => trim(($o['shipTo']['name'] ?? '')),
                'email'        => $o['customerEmail'] ?? '',
                'total'        => $o['orderTotal']   ?? 0,
                'ss_url'       => $o['orderId'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode($o['orderId']) : null,
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['order_date'], $a['order_date']));
        return $rows;
    }

    private static function loadRepeatRefunds(string $action, array $ctx): array
    {
        $rrMinCount = max(2, (int)($_POST['rr_min_count'] ?? $_GET['rr_min_count'] ?? 2));

        ['result' => $rrResult, 'error' => $rrError, 'start' => $rrStart, 'end' => $rrEnd] =
            ScanRunner::run($action, 'scan_repeat_refunds', $ctx, 'rr', function ($ctx, $start, $end) use (&$rrMinCount) {
                $rrMinCount = max(2, (int)($_POST['rr_min_count'] ?? 2));
                self::setLimits(300);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                $refundedOrders = self::suppressOutput(fn() => $shopify->fetchRefundedOrders($start, $end));

                $rows = self::buildRepeatRefundRows($refundedOrders, $rrMinCount);
                return ['rows' => $rows, 'scanned' => count($refundedOrders), 'start' => $start, 'end' => $end, 'min_count' => $rrMinCount];
            }, 90);

        return compact('rrResult', 'rrError', 'rrStart', 'rrEnd', 'rrMinCount');
    }

    /**
     * Repeat Refunds rows: customers (by email) with at least $rrMinCount
     * refunded orders in range, sorted by refund_count descending.
     * Only successful refund transactions count toward refunded_amt.
     *
     * @param  array<int, array<string, mixed>> $refundedOrders
     * @return array<int, array<string, mixed>>
     */
    private static function buildRepeatRefundRows(array $refundedOrders, int $rrMinCount): array
    {
        $byEmail = [];
        foreach ($refundedOrders as $o) {
            $email = strtolower(trim($o['email'] ?? ''));
            if (!$email) continue;
            $refundedAmt = 0.0;
            foreach ($o['refunds'] ?? [] as $ref) {
                foreach ($ref['transactions'] ?? [] as $tx) {
                    if (($tx['kind'] ?? '') === 'refund' && ($tx['status'] ?? '') === 'success') {
                        $refundedAmt += (float)($tx['amount'] ?? 0);
                    }
                }
            }
            $byEmail[$email][] = [
                'order_number' => $o['name'] ?? '',
                'shopify_id'   => $o['id'] ?? '',
                'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                'refunded_amt' => $refundedAmt,
            ];
        }

        $rows = [];
        foreach ($byEmail as $email => $orders) {
            if (count($orders) < $rrMinCount) continue;
            $totalRefunded = array_sum(array_column($orders, 'refunded_amt'));
            usort($orders, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
            $rows[] = [
                'email'          => $email,
                'refund_count'   => count($orders),
                'total_refunded' => $totalRefunded,
                'orders'         => $orders,
            ];
        }
        usort($rows, fn($a, $b) => $b['refund_count'] <=> $a['refund_count']);
        return $rows;
    }

    private static function loadFailedShipments(string $action, array $ctx): array
    {
        $fsResult = null;
        $fsError = '';
        [$fsStart, $fsEnd] = DateRange::fromRequest('fs');

        if ($action === 'scan_failed_shipments') {
            $fsStart = trim($_POST['fs_start'] ?? '');
            $fsEnd = trim($_POST['fs_end'] ?? '');

            if ($err = self::requireSS($ctx)) {
                $fsError = str_replace('SS_API_KEY / SS_API_SECRET', 'SHIPSTATION_API_KEY / SHIPSTATION_API_SECRET', $err);
            } elseif ($err = DateRange::validate($fsStart, $fsEnd)) {
                $fsError = $err;
            } else {
                try {
                    self::setLimits(180);
                    $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                    $shipments = self::suppressOutput(fn() => $ss->fetchVoidedShipments($fsStart, $fsEnd));

                    $fsResult = ['rows' => self::buildFailedShipmentRows($shipments), 'start' => $fsStart, 'end' => $fsEnd];
                } catch (Throwable $e) {
                    $fsError = $e->getMessage();
                }
            }
        }

        return compact('fsResult', 'fsError', 'fsStart', 'fsEnd');
    }

    /**
     * Builds Voided Shipments rows from raw SS shipment records, sorted by
     * void_date descending. Handles a missing/null shipTo address.
     *
     * @param  array<int, array<string, mixed>> $shipments
     * @return array<int, array<string, mixed>>
     */
    private static function buildFailedShipmentRows(array $shipments): array
    {
        $rows = [];
        foreach ($shipments as $s) {
            $addr = $s['shipTo'] ?? null;
            $rows[] = [
                'order_number'    => $s['orderNumber'] ?? '',
                'shipment_id'     => $s['shipmentId']  ?? '',
                'tracking'        => $s['trackingNumber'] ?? '',
                'carrier'         => $s['carrierCode']    ?? '',
                'service'         => $s['serviceCode']    ?? '',
                'ship_date'       => self::dateOnly($s['shipDate']  ?? ''),
                'void_date'       => self::dateOnly($s['voidDate']  ?? ''),
                'ship_to_name'    => trim(($addr['name'] ?? '')),
                'ship_to_city'    => $addr['city']       ?? '',
                'ship_to_state'   => $addr['state']      ?? '',
                'ship_to_zip'     => $addr['postalCode'] ?? '',
                'ship_to_country' => $addr['country']    ?? '',
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['void_date'], $a['void_date']));
        return $rows;
    }

    private static function loadAddrChanges(string $action, array $ctx): array
    {
        $acResult = null;
        $acError = '';
        [$acStart, $acEnd] = DateRange::fromRequest('ac');

        ['result' => $acResult, 'error' => $acError, 'start' => $acStart, 'end' => $acEnd] =
            ScanRunner::run($action, 'scan_addr_changes', $ctx, 'ac', function ($ctx, $start, $end) {
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $entries = self::suppressOutput(fn() => $shopify->fetchOrdersWithAddressChanges($start, $end));

                $rows = self::buildAddrChangeRows($entries);
                return ['rows' => $rows, 'start' => $start, 'end' => $end];
            });

        return compact('acResult', 'acError', 'acStart', 'acEnd');
    }

    /**
     * Address Changes rows, one per order with an address-change event.
     * `gap_mins` is the documented "time gap between placement and change"
     * (order created_at → event changed_at, in minutes; 0 if either
     * timestamp is missing/unparseable).
     *
     * @param  array<int, array{order: array<string, mixed>, changed_at: string}> $entries
     * @return array<int, array<string, mixed>>
     */
    private static function buildAddrChangeRows(array $entries): array
    {
        $rows = [];
        foreach ($entries as $e) {
            $o = $e['order'];
            $addr = $o['shipping_address'] ?? null;
            $addrLine = $addr ? implode(', ', array_filter([
                $addr['address1']      ?? '',
                $addr['city']          ?? '',
                $addr['province_code'] ?? '',
                $addr['zip']           ?? '',
                $addr['country_code']  ?? '',
            ])) : '';
            $createdTs = strtotime($o['created_at'] ?? '');
            $changedTs = strtotime($e['changed_at'] ?? '');
            $gapMins   = ($createdTs && $changedTs) ? max(0, (int)(($changedTs - $createdTs) / 60)) : 0;
            $rows[] = [
                'shopify_id'   => $o['id']           ?? '',
                'order_number' => $o['name']         ?? '',
                'created_at'   => self::dateOnly($o['created_at']  ?? ''),
                'changed_at'   => substr($e['changed_at']  ?? '', 0, 16),
                'gap_mins'     => $gapMins,
                'email'        => $o['email']        ?? '',
                'total'        => $o['total_price']  ?? '',
                'financial'    => $o['financial_status']    ?? '',
                'fulfillment'  => $o['fulfillment_status']  ?? '',
                'addr_name'    => trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                'addr_line'    => $addrLine,
            ];
        }
        return $rows;
    }

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
    }

    private static function requireShopify(array $ctx): ?string
    {
        return (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A')
            ? 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.'
            : null;
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
}
