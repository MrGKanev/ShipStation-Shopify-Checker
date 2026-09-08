<?php

namespace App\Domain\Reports;

class RefundTrackerAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array<string, mixed>>  $shipStationOrders
     * @return list<array<string, mixed>>
     */
    public function analyze(array $orders, array $shipStationOrders): array
    {
        $shipStationIndex = [];

        foreach ($shipStationOrders as $order) {
            $number = $this->orderNumber($order['orderNumber'] ?? '');

            if ($number !== '') {
                $shipStationIndex[$number][] = $order;
            }
        }

        $rows = [];

        foreach ($orders as $order) {
            $number = $this->orderNumber($order['order_number'] ?? $order['name'] ?? '');
            $matches = $shipStationIndex[$number] ?? [];
            $statuses = array_map(
                fn (array $match): string => $this->text($match['orderStatus'] ?? 'unknown'),
                $matches,
            );
            $active = count(array_intersect($statuses, ['awaiting_shipment', 'awaiting_payment', 'on_hold'])) > 0;
            $risk = $matches === [] ? 'missing' : ($active ? 'active' : 'ok');

            $rows[] = [
                'shopify_id' => $this->text($order['id'] ?? ''),
                'order_number' => $this->text($order['name'] ?? ($number === '' ? '' : '#'.$number)),
                'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10),
                'email' => $this->text($order['email'] ?? ''),
                'financial_status' => $this->text($order['financial_status'] ?? ''),
                'total_price' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0,
                'refunded_amount' => $this->refundedAmount($order),
                'shipstation_orders' => $matches,
                'shipstation_statuses' => $statuses,
                'risk' => $risk,
            ];
        }

        $rank = ['active' => 0, 'missing' => 1, 'ok' => 2];
        usort($rows, fn (array $left, array $right): int => $rank[$left['risk']] <=> $rank[$right['risk']]);

        return $rows;
    }

    /** @param array<string, mixed> $order */
    private function refundedAmount(array $order): float
    {
        $amount = 0.0;

        foreach (is_array($order['refunds'] ?? null) ? $order['refunds'] : [] as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            foreach (is_array($refund['refund_line_items'] ?? null) ? $refund['refund_line_items'] : [] as $item) {
                if (is_array($item) && is_numeric($item['subtotal'] ?? null)) {
                    $amount += (float) $item['subtotal'];
                }
            }
        }

        if ($amount === 0.0 && ($order['financial_status'] ?? '') === 'refunded' && is_numeric($order['total_price'] ?? null)) {
            return (float) $order['total_price'];
        }

        return $amount;
    }

    private function orderNumber(mixed $value): string
    {
        return preg_replace('/\D+/', '', $this->text($value)) ?? '';
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
