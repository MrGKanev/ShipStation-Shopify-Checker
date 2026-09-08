<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\InventoryAgingAnalyzer;
use PHPUnit\Framework\TestCase;

class InventoryAgingAnalyzerTest extends TestCase
{
    public function test_reports_zero_stock_recent_sellers_sorted_by_quantity_with_latest_sale(): void
    {
        $products = [$this->product('1', [$this->variant('A', 0), $this->variant('B', -2)])];
        $orders = [$this->order('#1', '2026-06-01T12:00:00Z', [['sku' => 'A', 'quantity' => 2], ['sku' => 'B', 'quantity' => 4]]), $this->order('#2', '2026-06-10T12:00:00Z', [['sku' => 'A', 'quantity' => 3]])];

        $result = (new InventoryAgingAnalyzer)->analyze($products, $orders);

        $this->assertSame(2, $result['variants']);
        $this->assertSame(['A', 'B'], array_column($result['rows'], 'sku'));
        $this->assertSame([5, 4], array_column($result['rows'], 'recent_qty'));
        $this->assertSame('#2', $result['rows'][0]['last_order']);
        $this->assertSame('2026-06-10', $result['rows'][0]['last_date']);
    }

    public function test_excludes_no_sales_positive_stock_untracked_continue_and_blank_sku(): void
    {
        $products = [$this->product('1', [$this->variant('NONE', 0), $this->variant('POS', 1), $this->variant('UNTRACKED', 0, false), $this->variant('CONTINUE', 0, true, 'CONTINUE'), $this->variant('', 0)])];
        $orders = [$this->order('#1', '2026-06-01', [['sku' => 'POS'], ['sku' => 'UNTRACKED'], ['sku' => 'CONTINUE'], ['sku' => '']])];

        $result = (new InventoryAgingAnalyzer)->analyze($products, $orders);

        $this->assertSame(5, $result['variants']);
        $this->assertSame([], $result['rows']);
    }

    public function test_aggregates_same_sku_and_defaults_missing_quantity_to_one(): void
    {
        $products = [$this->product('1', [$this->variant(' SKU ', 0)])];
        $orders = [$this->order('#1', 'invalid', [['sku' => 'SKU'], ['sku' => 'SKU', 'quantity' => 2]])];

        $result = (new InventoryAgingAnalyzer)->analyze($products, $orders);

        $this->assertSame(3, $result['rows'][0]['recent_qty']);
        $this->assertSame('', $result['rows'][0]['last_order']);
        $this->assertSame('', $result['rows'][0]['last_date']);
    }

    public function test_handles_malformed_values_and_rejects_unsafe_product_id(): void
    {
        $result = (new InventoryAgingAnalyzer)->analyze([['legacyResourceId' => 'javascript:1', 'title' => '<script>x</script>', 'variants' => [null, $this->variant('X', 0)]]], [$this->order('<img>', '2026-01-01', [['sku' => 'X']])]);

        $this->assertSame(2, $result['variants']);
        $this->assertSame('', $result['rows'][0]['product_id']);
        $this->assertSame('<script>x</script>', $result['rows'][0]['product_title']);
    }

    private function product(string $id, array $variants): array
    {
        return ['legacyResourceId' => $id, 'title' => 'Widget', 'variants' => $variants];
    }

    private function variant(string $sku, int $stock, bool $tracked = true, string $policy = 'DENY'): array
    {
        return ['sku' => $sku, 'title' => 'Default', 'inventoryQuantity' => $stock, 'inventoryPolicy' => $policy, 'inventoryItem' => ['tracked' => $tracked]];
    }

    private function order(string $name, string $date, array $items): array
    {
        return ['name' => $name, 'created_at' => $date, 'line_items' => $items];
    }
}
