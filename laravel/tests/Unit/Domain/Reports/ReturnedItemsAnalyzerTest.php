<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ReturnedItemsAnalyzer;
use PHPUnit\Framework\TestCase;

class ReturnedItemsAnalyzerTest extends TestCase
{
    public function test_it_aggregates_refunds_in_range_across_old_orders_and_sorts_labels(): void
    {
        $orders = [['created_at' => '2025-01-01', 'refunds' => [
            $this->refund('2026-07-15', [['quantity' => 2, 'line_item' => ['name' => 'Zeta']], ['quantity' => 3, 'line_item' => ['title' => 'Alpha']]]),
            $this->refund('2026-07-20', [['quantity' => 1, 'line_item' => ['name' => 'Zeta']]]),
        ]]];

        $rows = (new ReturnedItemsAnalyzer)->analyze($orders, '2026-07-01', '2026-07-31');

        $this->assertSame([['product' => 'Alpha', 'quantity' => 3], ['product' => 'Zeta', 'quantity' => 3]], $rows);
    }

    public function test_it_excludes_out_of_range_refunds_and_blank_products(): void
    {
        $orders = [['refunds' => [
            $this->refund('2026-06-30', [['quantity' => 999, 'line_item' => ['name' => 'Widget']]]),
            $this->refund('2026-07-31', [['quantity' => 5, 'line_item' => ['name' => ' Widget ']], ['quantity' => 4, 'line_item' => ['name' => '']]]),
            $this->refund('2026-08-01', [['quantity' => 999, 'line_item' => ['name' => 'Widget']]]),
        ]]];

        $this->assertSame([['product' => 'Widget', 'quantity' => 5]], (new ReturnedItemsAnalyzer)->analyze($orders, '2026-07-01', '2026-07-31'));
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private function refund(string $date, array $items): array
    {
        return ['created_at' => $date.'T10:00:00Z', 'refund_line_items' => $items];
    }
}
