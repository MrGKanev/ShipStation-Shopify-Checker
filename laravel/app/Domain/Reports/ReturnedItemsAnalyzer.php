<?php

namespace App\Domain\Reports;

class ReturnedItemsAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array{product: string, quantity: int}>
     */
    public function analyze(array $orders, string $startDate, string $endDate): array
    {
        $rangeStart = $startDate.'T00:00:00Z';
        $rangeEnd = $endDate.'T23:59:59Z';
        $totals = [];

        foreach ($orders as $order) {
            foreach (is_array($order['refunds'] ?? null) ? $order['refunds'] : [] as $refund) {
                if (! is_array($refund)) {
                    continue;
                }

                $createdAt = $this->text($refund['created_at'] ?? '');
                if ($createdAt < $rangeStart || $createdAt > $rangeEnd) {
                    continue;
                }

                foreach (is_array($refund['refund_line_items'] ?? null) ? $refund['refund_line_items'] : [] as $refundLineItem) {
                    if (! is_array($refundLineItem)) {
                        continue;
                    }

                    $lineItem = is_array($refundLineItem['line_item'] ?? null) ? $refundLineItem['line_item'] : [];
                    $product = $this->text($lineItem['name'] ?? $lineItem['title'] ?? '');
                    if ($product === '') {
                        continue;
                    }

                    $quantity = is_numeric($refundLineItem['quantity'] ?? null) ? (int) $refundLineItem['quantity'] : 0;
                    $totals[$product] = ($totals[$product] ?? 0) + $quantity;
                }
            }
        }

        ksort($totals);

        return array_map(fn (string $product, int $quantity): array => ['product' => $product, 'quantity' => $quantity], array_keys($totals), array_values($totals));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
