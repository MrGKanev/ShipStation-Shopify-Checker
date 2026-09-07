<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\InventoryForecastAnalyzer;
use PHPUnit\Framework\TestCase;

class InventoryForecastAnalyzerTest extends TestCase
{
    public function test_calculates_rate_days_and_severity_counts(): void
    {
        $products = [$this->product([$this->variant('CRITICAL', 5), $this->variant('WARNING', 10), $this->variant('SAFE', 60)])];
        $orders = [$this->order([['sku' => 'CRITICAL', 'quantity' => 30], ['sku' => 'WARNING', 'quantity' => 30], ['sku' => 'SAFE', 'quantity' => 30]])];

        $result = (new InventoryForecastAnalyzer)->analyze($products, $orders);

        $this->assertSame(['CRITICAL', 'WARNING', 'SAFE'], array_column($result['rows'], 'sku'));
        $this->assertSame([5, 10, 60], array_column($result['rows'], 'days_to_zero'));
        $this->assertSame(1.0, $result['rows'][0]['daily_rate']);
        $this->assertSame(1, $result['critical']);
        $this->assertSame(1, $result['warning']);
    }

    public function test_excludes_cancelled_sales_untracked_continue_and_no_sales(): void
    {
        $products = [$this->product([$this->variant('CANCELLED', 5), $this->variant('UNTRACKED', 5, false), $this->variant('CONTINUE', 5, true, 'CONTINUE'), $this->variant('NO-SALES', 5)])];
        $orders = [$this->order([['sku' => 'CANCELLED', 'quantity' => 30]], '2026-09-01')];

        $result = (new InventoryForecastAnalyzer)->analyze($products, $orders);

        $this->assertSame(4, $result['variants']);
        $this->assertSame([], $result['rows']);
    }

    public function test_includes_zero_stock_with_sales_and_sorts_null_days_last(): void
    {
        $products = [$this->product([$this->variant('ZERO', 0), $this->variant('LOW', 3)])];
        $orders = [$this->order([['sku' => 'ZERO', 'quantity' => 5], ['sku' => 'LOW', 'quantity' => 30]])];

        $result = (new InventoryForecastAnalyzer)->analyze($products, $orders);

        $this->assertSame(['LOW', 'ZERO'], array_column($result['rows'], 'sku'));
        $this->assertSame(3, $result['rows'][0]['days_to_zero']);
        $this->assertNull($result['rows'][1]['days_to_zero']);
    }

    public function test_aggregates_skus_and_handles_malformed_values_safely(): void
    {
        $products = [['legacyResourceId' => 'javascript:1', 'title' => '<script>x</script>', 'variants' => [null, $this->variant(' SKU ', 30)]]];
        $orders = [$this->order([['sku' => 'SKU'], ['sku' => 'SKU', 'quantity' => 29]])];

        $result = (new InventoryForecastAnalyzer)->analyze($products, $orders);

        $this->assertSame(2, $result['variants']);
        $this->assertSame(30, $result['rows'][0]['sold_30d']);
        $this->assertSame('', $result['rows'][0]['product_id']);
        $this->assertSame('<script>x</script>', $result['rows'][0]['product_title']);
    }

    private function product(array $variants): array
    {
        return ['legacyResourceId' => '42', 'title' => 'Widget', 'variants' => $variants];
    }

    private function variant(string $sku, int $stock, bool $tracked = true, string $policy = 'DENY'): array
    {
        return ['sku' => $sku, 'title' => 'Default', 'inventoryQuantity' => $stock, 'inventoryPolicy' => $policy, 'inventoryItem' => ['tracked' => $tracked]];
    }

    private function order(array $items, ?string $cancelledAt = null): array
    {
        return ['cancelled_at' => $cancelledAt, 'line_items' => $items];
    }
}
