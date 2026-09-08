<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ReturnRmaAnalyzer;
use PHPUnit\Framework\TestCase;

class ReturnRmaAnalyzerTest extends TestCase
{
    public function test_it_creates_one_row_per_refund_with_items_reason_and_newest_first_order(): void
    {
        $orders = [
            $this->order('#OLD', [$this->refund('2026-01-02', [['quantity' => 2, 'subtotal' => 19.98, 'line_item' => ['name' => 'Widget', 'sku' => 'SKU-1']]], ' Damaged '), $this->refund('2026-01-05', [])]),
            $this->order('#NEW', [$this->refund('2026-01-10', [])]),
        ];

        $result = (new ReturnRmaAnalyzer)->analyze($orders);

        $this->assertSame(['#NEW', '#OLD', '#OLD'], array_column($result['rows'], 'order_number'));
        $this->assertSame('Damaged', $result['rows'][2]['reason']);
        $this->assertSame([['name' => 'Widget', 'sku' => 'SKU-1', 'quantity' => 2, 'subtotal' => 19.98]], $result['rows'][2]['items']);
        $this->assertSame(10.0, $result['rows'][0]['refund_total']);
    }

    public function test_it_aggregates_valid_skus_and_sorts_by_units(): void
    {
        $orders = [
            $this->order('#1', [$this->refund('2026-01-01', [
                ['quantity' => 1, 'subtotal' => 5, 'line_item' => ['name' => 'Low', 'sku' => 'LOW']],
                ['quantity' => 0, 'subtotal' => 5, 'line_item' => ['name' => 'Zero', 'sku' => 'ZERO']],
                ['quantity' => 1, 'subtotal' => 5, 'line_item' => ['name' => 'Blank', 'sku' => '']],
            ])]),
            $this->order('#2', [$this->refund('2026-01-02', [['quantity' => 3, 'subtotal' => 30, 'line_item' => ['name' => 'High', 'sku' => 'HIGH']]])]),
        ];

        $stats = (new ReturnRmaAnalyzer)->analyze($orders)['sku_stats'];

        $this->assertSame(['HIGH', 'LOW'], array_column($stats, 'sku'));
        $this->assertSame(['sku' => 'HIGH', 'units' => 3, 'events' => 1, 'revenue' => 30.0], $stats[0]);
    }

    /** @param list<array<string, mixed>> $refunds @return array<string, mixed> */
    private function order(string $name, array $refunds): array
    {
        return ['id' => '1', 'name' => $name, 'created_at' => '2026-01-01', 'email' => 'a@example.com', 'financial_status' => 'refunded', 'refunds' => $refunds];
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private function refund(string $date, array $items, string $note = ''): array
    {
        return ['created_at' => $date.'T10:00:00Z', 'total_refunded' => 10, 'note' => $note, 'refund_line_items' => $items];
    }
}
