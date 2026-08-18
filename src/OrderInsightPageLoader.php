<?php
declare(strict_types=1);

/**
 * Loads order comparison and timeline insight pages.
 */
class OrderInsightPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'compare'  => self::loadCompare($action, $ctx),
            'timeline' => self::loadTimeline($action, $ctx),
            default    => [],
        };
    }

    private static function loadCompare(string $action, array $ctx): array
    {
        $compareResult = null;
        $compareError = '';
        $compareA = trim($_POST['compare_a'] ?? $_GET['a'] ?? '');
        $compareB = trim($_POST['compare_b'] ?? $_GET['b'] ?? '');

        if ($action === 'compare_orders') {
            $compareA = ltrim(trim($_POST['compare_a'] ?? ''), '#');
            $compareB = ltrim(trim($_POST['compare_b'] ?? ''), '#');

            if (!$compareA || !$compareB) {
                $compareError = 'Enter two order numbers to compare.';
            } elseif ($err = self::requireShopify($ctx)) {
                $compareError = $err;
            } else {
                try {
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], null, $ctx['httpStack'] ?? null);
                    $ss = ($ctx['ssKey'] && $ctx['ssSecret'])
                        ? new ShipStation($ctx['ssKey'], $ctx['ssSecret'], null, $ctx['httpStack'] ?? null) : null;

                    $fetchOrder = function (string $num) use ($shopify, $ss): array {
                        $shOrders = $shopify->findByOrderNumber($num);
                        $shOrder = !empty($shOrders) ? $shopify->getOrder((string)($shOrders[0]['id'] ?? '')) : null;
                        $ssOrders = $ss ? $ss->findByOrderNumber($num) : [];
                        return ['shopify' => $shOrder, 'ss' => $ssOrders, 'num' => $num];
                    };

                    $compareResult = ['a' => $fetchOrder($compareA), 'b' => $fetchOrder($compareB)];
                } catch (Throwable $e) {
                    $compareError = $e->getMessage();
                }
            }
        }

        return compact('compareResult', 'compareError', 'compareA', 'compareB');
    }

    private static function loadTimeline(string $action, array $ctx): array
    {
        $tlInput = trim($_POST['tl_order'] ?? $_GET['order'] ?? '');
        $tlResult = null;
        $tlError = '';

        if ($action === 'order_timeline') {
            $num = ltrim($tlInput, '#');

            if (!$num) {
                $tlError = 'Enter an order number.';
            } elseif ($err = self::requireShopify($ctx)) {
                $tlError = $err;
            } else {
                try {
                    self::setLimits(60);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], null, $ctx['httpStack'] ?? null);
                    $matches = $shopify->findByOrderNumber($num);

                    if (empty($matches)) {
                        $tlError = "Order #{$num} not found in Shopify.";
                    } else {
                        $shopifyId = (string)($matches[0]['id'] ?? '');
                        $order = $shopify->getOrder($shopifyId);
                        $events = $shopify->getOrderEvents($shopifyId);

                        $ssOrders = [];
                        $ssShipments = [];
                        if ($ctx['ssKey'] && $ctx['ssSecret']) {
                            $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], null, $ctx['httpStack'] ?? null);
                            $ssOrders = $ss->findByOrderNumber($num);
                            $ssShipments = $ss->getOrderShipments($num);
                        }

                        $timeline = self::buildOrderTimeline($order, $events, $ssOrders, $ssShipments);
                        $risks = self::analyzeOrderRisks($order, $ssOrders);
                        $timeToShip = self::calcTimeToShip($order);
                        $riskScore = RiskScorer::score($order);

                        $tlResult = [
                            'order'        => $order,
                            'ss_orders'    => $ssOrders,
                            'ss_shipments' => $ssShipments,
                            'timeline'     => $timeline,
                            'risks'        => $risks,
                            'time_to_ship' => $timeToShip,
                            'risk_score'   => $riskScore,
                            'label'        => $order['name'] ?? ('#' . $num),
                        ];
                    }
                } catch (Throwable $e) {
                    $tlError = $e->getMessage();
                }
            }
        }

        return compact('tlInput', 'tlResult', 'tlError');
    }

    private static function buildOrderTimeline(
        array $order,
        array $events,
        array $ssOrders,
        array $ssShipments
    ): array {
        $items = [];

        if (!empty($order['created_at'])) {
            $items[] = [
                'ts'       => $order['created_at'],
                'type'     => 'order_placed',
                'source'   => 'shopify',
                'title'    => 'Order placed',
                'detail'   => trim(($order['email'] ?? '')),
                'tracking' => '',
                'url'      => '',
            ];
        }

        $finStatus = $order['financial_status'] ?? '';
        if (in_array($finStatus, ['paid', 'partially_paid'], true) && !empty($order['processed_at'])) {
            $total = (float)($order['total_price'] ?? 0);
            $items[] = [
                'ts'       => $order['processed_at'],
                'type'     => 'payment',
                'source'   => 'shopify',
                'title'    => 'Payment captured',
                'detail'   => '$' . number_format($total, 2),
                'tracking' => '',
                'url'      => '',
            ];
        }

        foreach ($order['fulfillments'] ?? [] as $f) {
            $itemCount = count($f['line_items'] ?? []);
            $tracking = $f['tracking_number'] ?? '';
            $carrier = $f['tracking_company'] ?? '';
            $detail = $itemCount . ' item' . ($itemCount !== 1 ? 's' : '');
            if ($carrier) $detail .= ' · ' . $carrier;

            $items[] = [
                'ts'       => $f['created_at'],
                'type'     => 'fulfillment',
                'source'   => 'shopify',
                'title'    => 'Fulfillment created',
                'detail'   => $detail,
                'tracking' => $tracking,
                'url'      => $tracking && $f['tracking_url'] ? $f['tracking_url'] : '',
            ];
        }

        foreach ($order['refunds'] ?? [] as $r) {
            $amt = 0.0;
            foreach ($r['transactions'] ?? [] as $tx) {
                if (($tx['kind'] ?? '') === 'refund' && ($tx['status'] ?? '') === 'success') {
                    $amt += (float)($tx['amount'] ?? 0);
                }
            }
            $detailParts = [];
            if ($amt > 0) $detailParts[] = '$' . number_format($amt, 2);
            if (!empty($r['note'])) $detailParts[] = $r['note'];

            $items[] = [
                'ts'       => $r['created_at'],
                'type'     => 'refund',
                'source'   => 'shopify',
                'title'    => 'Refund processed',
                'detail'   => implode(' · ', $detailParts),
                'tracking' => '',
                'url'      => '',
            ];
        }

        if (!empty($order['cancelled_at'])) {
            $reason = $order['cancel_reason'] ?? '';
            $items[] = [
                'ts'       => $order['cancelled_at'],
                'type'     => 'cancelled',
                'source'   => 'shopify',
                'title'    => 'Order cancelled',
                'detail'   => $reason ? ucfirst(str_replace('_', ' ', $reason)) : '',
                'tracking' => '',
                'url'      => '',
            ];
        }

        if (!empty($order['closed_at'])) {
            $items[] = [
                'ts'       => $order['closed_at'],
                'type'     => 'closed',
                'source'   => 'shopify',
                'title'    => 'Order closed',
                'detail'   => '',
                'tracking' => '',
                'url'      => '',
            ];
        }

        $skipVerbs = ['placed', 'confirmed', 'fulfillment_created', 'fulfillment_success',
                      'fulfillment_shipped', 'closed', 'cancelled'];
        foreach ($events as $ev) {
            if (in_array($ev['verb'] ?? '', $skipVerbs, true)) continue;
            $msg = $ev['message'] ?? ucfirst(str_replace('_', ' ', $ev['verb'] ?? ''));
            $items[] = [
                'ts'       => $ev['created_at'],
                'type'     => 'shopify_event',
                'source'   => 'shopify',
                'title'    => $msg,
                'detail'   => '',
                'tracking' => '',
                'url'      => '',
            ];
        }

        foreach ($ssOrders as $sso) {
            $ts = $sso['createDate'] ?? $sso['orderDate'] ?? '';
            if (!$ts) continue;
            $ssId = $sso['orderId'] ?? '';
            $status = $sso['orderStatus'] ?? 'unknown';
            $items[] = [
                'ts'       => $ts,
                'type'     => 'ss_order',
                'source'   => 'shipstation',
                'title'    => 'ShipStation: ' . ucfirst(str_replace('_', ' ', $status)),
                'detail'   => $ssId ? 'SS ID ' . $ssId : '',
                'tracking' => '',
                'url'      => $ssId ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode((string)$ssId) : '',
            ];
        }

        foreach ($ssShipments as $s) {
            $ts = $s['shipDate'] ?? '';
            if (!$ts) continue;
            $carrier = strtoupper($s['carrierCode'] ?? '');
            $tracking = $s['trackingNumber'] ?? '';
            $detail = implode(' · ', array_filter([$carrier, $tracking]));
            $items[] = [
                'ts'       => $ts,
                'type'     => 'ss_shipment',
                'source'   => 'shipstation',
                'title'    => 'Shipped via ShipStation',
                'detail'   => $detail,
                'tracking' => $tracking,
                'url'      => '',
            ];
        }

        usort($items, fn($a, $b) => strcmp($b['ts'], $a['ts']));

        foreach ($items as &$item) {
            $item['ts_fmt'] = $item['ts']
                ? date('Y-m-d H:i', strtotime($item['ts']))
                : '';
        }
        unset($item);

        return $items;
    }

    private static function analyzeOrderRisks(array $order, array $ssOrders): array
    {
        $risks = [];

        $timeToShip = self::calcTimeToShip($order);
        if ($timeToShip !== null) {
            if ($timeToShip > 7) {
                $risks[] = ['level' => 'danger', 'msg' => "Slow to ship: {$timeToShip} days between order placement and first fulfillment"];
            } elseif ($timeToShip > 3) {
                $risks[] = ['level' => 'warn', 'msg' => "Slow to ship: {$timeToShip} days between order placement and first fulfillment"];
            }
        }

        if (!empty($order['cancelled_at']) && !empty($order['fulfillments'])) {
            $risks[] = ['level' => 'danger', 'msg' => 'Order is cancelled but has fulfillments - items may have already shipped'];
        }

        $finStatus = $order['financial_status'] ?? '';
        if (in_array($finStatus, ['refunded', 'partially_refunded'], true)) {
            $activeStatuses = ['awaiting_shipment', 'awaiting_payment', 'on_hold'];
            foreach ($ssOrders as $sso) {
                if (in_array($sso['orderStatus'] ?? '', $activeStatuses, true)) {
                    $risks[] = ['level' => 'danger', 'msg' => 'Order is refunded in Shopify but still active in ShipStation (' . ($sso['orderStatus'] ?? '') . ')'];
                    break;
                }
            }
        }

        foreach ($order['fulfillments'] ?? [] as $f) {
            if (empty($f['tracking_number'])) {
                $risks[] = ['level' => 'warn', 'msg' => 'Fulfillment exists without a tracking number'];
                break;
            }
        }

        $fCount = count($order['fulfillments'] ?? []);
        if ($fCount > 1) {
            $risks[] = ['level' => 'info', 'msg' => "Order has {$fCount} separate fulfillments (split shipment)"];
        }

        return $risks;
    }

    private static function calcTimeToShip(array $order): ?int
    {
        $fulfillments = $order['fulfillments'] ?? [];
        if (empty($fulfillments) || empty($order['created_at'])) return null;

        $ordered = strtotime($order['created_at']);
        $fulfilled = strtotime($fulfillments[0]['created_at']);
        return max(0, (int)round(($fulfilled - $ordered) / 86400));
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
}
