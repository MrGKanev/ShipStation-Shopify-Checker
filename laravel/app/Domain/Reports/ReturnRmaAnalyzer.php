<?php

namespace App\Domain\Reports;

class ReturnRmaAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array{rows: list<array<string, mixed>>, sku_stats: list<array<string, mixed>>}
     */
    public function analyze(array $orders): array
    {
        $rows = [];
        $skuStats = [];

        foreach ($orders as $order) {
            foreach (is_array($order['refunds'] ?? null) ? $order['refunds'] : [] as $refund) {
                if (! is_array($refund)) {
                    continue;
                }

                $items = [];

                foreach (is_array($refund['refund_line_items'] ?? null) ? $refund['refund_line_items'] : [] as $refundLineItem) {
                    if (! is_array($refundLineItem)) {
                        continue;
                    }

                    $lineItem = is_array($refundLineItem['line_item'] ?? null) ? $refundLineItem['line_item'] : [];
                    $sku = $this->text($lineItem['sku'] ?? '');
                    $quantity = is_numeric($refundLineItem['quantity'] ?? null) ? (int) $refundLineItem['quantity'] : 0;
                    $subtotal = is_numeric($refundLineItem['subtotal'] ?? null) ? (float) $refundLineItem['subtotal'] : 0.0;
                    $items[] = [
                        'name' => $this->text($lineItem['name'] ?? $lineItem['title'] ?? ''),
                        'sku' => $sku,
                        'quantity' => $quantity,
                        'subtotal' => $subtotal,
                    ];

                    if ($sku !== '' && $quantity > 0) {
                        $skuStats[$sku] ??= ['sku' => $sku, 'units' => 0, 'events' => 0, 'revenue' => 0.0];
                        $skuStats[$sku]['units'] += $quantity;
                        $skuStats[$sku]['events']++;
                        $skuStats[$sku]['revenue'] += $subtotal;
                    }
                }

                $rows[] = [
                    'shopify_id' => $this->text($order['id'] ?? ''),
                    'order_number' => $this->text($order['name'] ?? ''),
                    'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10),
                    'refund_date' => substr($this->text($refund['created_at'] ?? ''), 0, 10),
                    'email' => $this->text($order['email'] ?? ''),
                    'financial_status' => $this->text($order['financial_status'] ?? ''),
                    'refund_total' => is_numeric($refund['total_refunded'] ?? null) ? (float) $refund['total_refunded'] : 0.0,
                    'reason' => $this->text($refund['note'] ?? ''),
                    'items' => $items,
                ];
            }
        }

        usort($rows, fn (array $left, array $right): int => strcmp($right['refund_date'], $left['refund_date']));
        $skuStats = array_values($skuStats);
        usort($skuStats, fn (array $left, array $right): int => $right['units'] <=> $left['units']);

        return ['rows' => $rows, 'sku_stats' => $skuStats];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
