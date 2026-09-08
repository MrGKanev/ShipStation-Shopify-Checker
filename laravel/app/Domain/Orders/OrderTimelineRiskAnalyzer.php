<?php

namespace App\Domain\Orders;

class OrderTimelineRiskAnalyzer
{
    /**
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $shipStationOrders
     * @return list<array{level: string, message: string}>
     */
    public function analyze(array $order, array $shipStationOrders): array
    {
        $risks = [];
        $timeToShip = $this->timeToShip($order);

        if ($timeToShip !== null && $timeToShip > 7) {
            $risks[] = ['level' => 'danger', 'message' => "Slow to ship: {$timeToShip} days between order placement and first fulfillment"];
        } elseif ($timeToShip !== null && $timeToShip > 3) {
            $risks[] = ['level' => 'warn', 'message' => "Slow to ship: {$timeToShip} days between order placement and first fulfillment"];
        }

        $fulfillments = is_array($order['fulfillments'] ?? null) ? $order['fulfillments'] : [];

        if (! empty($order['cancelled_at']) && $fulfillments !== []) {
            $risks[] = ['level' => 'danger', 'message' => 'Order is cancelled but has fulfillments - items may have already shipped'];
        }

        if (in_array($order['financial_status'] ?? '', ['refunded', 'partially_refunded'], true)) {
            foreach ($shipStationOrders as $shipStationOrder) {
                $status = $shipStationOrder['orderStatus'] ?? '';

                if (in_array($status, ['awaiting_shipment', 'awaiting_payment', 'on_hold'], true)) {
                    $risks[] = ['level' => 'danger', 'message' => "Order is refunded in Shopify but still active in ShipStation ({$status})"];

                    break;
                }
            }
        }

        foreach ($fulfillments as $fulfillment) {
            if (is_array($fulfillment) && empty($fulfillment['tracking_number'])) {
                $risks[] = ['level' => 'warn', 'message' => 'Fulfillment exists without a tracking number'];

                break;
            }
        }

        if (count($fulfillments) > 1) {
            $risks[] = ['level' => 'info', 'message' => 'Order has '.count($fulfillments).' separate fulfillments (split shipment)'];
        }

        return $risks;
    }

    /** @param array<string, mixed> $order */
    public function timeToShip(array $order): ?int
    {
        $fulfillments = is_array($order['fulfillments'] ?? null) ? $order['fulfillments'] : [];

        if ($fulfillments === [] || empty($order['created_at'])) {
            return null;
        }

        $orderedAt = strtotime((string) $order['created_at']);
        $fulfillmentTimestamps = array_filter(array_map(
            fn (mixed $fulfillment): int|false => is_array($fulfillment)
                ? strtotime((string) ($fulfillment['created_at'] ?? ''))
                : false,
            $fulfillments,
        ), fn (int|false $timestamp): bool => $timestamp !== false);

        if ($orderedAt === false || $fulfillmentTimestamps === []) {
            return null;
        }

        $fulfilledAt = min($fulfillmentTimestamps);

        return max(0, (int) round(($fulfilledAt - $orderedAt) / 86400));
    }
}
