<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';

/**
 * Tests for ProductInventoryPageLoader::buildInventoryForecastRows() via
 * reflection (private method). This logic previously lived inline in
 * loadInventoryForecast() and had zero test coverage - only the wrapper's
 * missing-credentials error path was tested.
 */
class InventoryForecastTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(ProductInventoryPageLoader::class);
        self::$method = $ref->getMethod('buildInventoryForecastRows');
    }

    private function product(array $variants, array $overrides = []): array
    {
        return array_merge(['id' => 1, 'title' => 'Widget', 'variants' => $variants], $overrides);
    }

    private function variant(array $overrides = []): array
    {
        return array_merge([
            'title'                 => 'Blue',
            'sku'                   => 'SKU1',
            'inventory_management'  => 'shopify',
            'inventory_policy'      => 'deny',
            'inventory_quantity'    => 10,
        ], $overrides);
    }

    private function order(string $sku, int $qty, array $overrides = []): array
    {
        return array_merge([
            'line_items' => [['sku' => $sku, 'quantity' => $qty]],
        ], $overrides);
    }

    private function buildRows(array $products, array $orders): array
    {
        return self::$method->invoke(null, $products, $orders);
    }

    public function testComputesDailyRateAndDaysToZeroFromThirtyDaySales(): void
    {
        [$rows] = $this->buildRows(
            [$this->product([$this->variant(['sku' => 'SKU1', 'inventory_quantity' => 60])])],
            [$this->order('SKU1', 30)]
        );

        $this->assertSame(1.0, $rows[0]['daily_rate']);
        $this->assertSame(60, $rows[0]['days_to_zero']);
        $this->assertSame(30, $rows[0]['sold_30d']);
    }

    public function testExcludesVariantWithNoSalesAndHighStock(): void
    {
        [$rows] = $this->buildRows(
            [$this->product([$this->variant(['sku' => 'SKU1', 'inventory_quantity' => 40])])],
            []
        );

        $this->assertSame([], $rows);
    }

    public function testLowStockVariantWithoutAnySalesIsStillExcluded(): void
    {
        [$rows] = $this->buildRows(
            [$this->product([$this->variant(['sku' => 'SKU1', 'inventory_quantity' => 5])])],
            []
        );

        $this->assertSame([], $rows, 'no sales means daily_rate=0, so days_to_zero stays null and the "no risk, no sales" skip applies');
    }

    public function testZeroStockWithRecentSalesIsIncludedWithNullDaysToZero(): void
    {
        [$rows] = $this->buildRows(
            [$this->product([$this->variant(['sku' => 'SKU1', 'inventory_quantity' => 0])])],
            [$this->order('SKU1', 10)]
        );

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['days_to_zero']);
        $this->assertSame(10, $rows[0]['sold_30d']);
    }

    public function testCancelledOrdersExcludedFromSalesCount(): void
    {
        [$rows] = $this->buildRows(
            [$this->product([$this->variant(['sku' => 'SKU1', 'inventory_quantity' => 5])])],
            [$this->order('SKU1', 10, ['cancelled_at' => '2026-06-01T10:00:00Z'])]
        );

        $this->assertSame([], $rows);
    }

    public function testUntrackedVariantExcluded(): void
    {
        [$rows] = $this->buildRows(
            [$this->product([$this->variant(['sku' => 'SKU1', 'inventory_management' => '', 'inventory_quantity' => 0])])],
            [$this->order('SKU1', 10)]
        );

        $this->assertSame([], $rows);
    }

    public function testContinueSellingPolicyExcluded(): void
    {
        [$rows] = $this->buildRows(
            [$this->product([$this->variant(['sku' => 'SKU1', 'inventory_policy' => 'continue', 'inventory_quantity' => 0])])],
            [$this->order('SKU1', 10)]
        );

        $this->assertSame([], $rows);
    }

    public function testSortsAscendingByDaysToZeroWithNullLast(): void
    {
        $products = [$this->product([
            $this->variant(['sku' => 'SLOW', 'inventory_quantity' => 100]),
            $this->variant(['sku' => 'FAST', 'inventory_quantity' => 10]),
            $this->variant(['sku' => 'ZERO', 'inventory_quantity' => 0]),
        ])];
        $orders = [
            $this->order('SLOW', 10),
            $this->order('FAST', 10),
            $this->order('ZERO', 5),
        ];

        [$rows] = $this->buildRows($products, $orders);

        $this->assertSame(['FAST', 'SLOW', 'ZERO'], array_column($rows, 'sku'));
        $this->assertNull($rows[2]['days_to_zero']);
    }

    public function testVariantCountIncludesAllVariantsRegardlessOfFiltering(): void
    {
        [, $variantCount] = $this->buildRows(
            [$this->product([
                $this->variant(['sku' => 'A', 'inventory_management' => '']),
                $this->variant(['sku' => 'B']),
            ])],
            []
        );

        $this->assertSame(2, $variantCount);
    }
}
