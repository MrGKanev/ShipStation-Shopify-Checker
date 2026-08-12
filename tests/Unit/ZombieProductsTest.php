<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';

/**
 * Tests for ProductInventoryPageLoader::buildZombieProductRows() via
 * reflection (private method). This logic previously lived inline in
 * loadZombieProducts() and had zero test coverage - only the wrapper's
 * missing-credentials error path was tested.
 */
class ZombieProductsTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(ProductInventoryPageLoader::class);
        self::$method = $ref->getMethod('buildZombieProductRows');
    }

    private function product(array $variants, array $overrides = []): array
    {
        return array_merge([
            'id'           => 1,
            'title'        => 'Widget',
            'vendor'       => 'Acme',
            'product_type' => 'Gadgets',
            'variants'     => $variants,
        ], $overrides);
    }

    private function variant(array $overrides = []): array
    {
        return array_merge([
            'inventory_management' => 'shopify',
            'inventory_policy'     => 'deny',
            'inventory_quantity'   => 0,
        ], $overrides);
    }

    private function buildRows(array $products): array
    {
        return self::$method->invoke(null, $products);
    }

    public function testProductWithNoVariantsIsFlaggedNoVariants(): void
    {
        $rows = $this->buildRows([$this->product([])]);

        $this->assertSame('no_variants', $rows[0]['reason']);
        $this->assertNull($rows[0]['stock']);
    }

    public function testAllTrackedVariantsAtZeroStockIsFlaggedZeroStock(): void
    {
        $rows = $this->buildRows([$this->product([
            $this->variant(['inventory_quantity' => 0]),
            $this->variant(['inventory_quantity' => -2]),
        ])]);

        $this->assertSame('zero_stock', $rows[0]['reason']);
        $this->assertSame('2 tracked variants, all at 0', $rows[0]['detail']);
        $this->assertSame(-2, $rows[0]['stock']);
    }

    public function testSingularVariantWording(): void
    {
        $rows = $this->buildRows([$this->product([$this->variant(['inventory_quantity' => 0])])]);

        $this->assertSame('1 tracked variant, all at 0', $rows[0]['detail']);
    }

    public function testProductWithSomeStockIsNotFlagged(): void
    {
        $rows = $this->buildRows([$this->product([
            $this->variant(['inventory_quantity' => 0]),
            $this->variant(['inventory_quantity' => 5]),
        ])]);

        $this->assertSame([], $rows);
    }

    public function testUntrackedVariantsOnlyIsNotFlaggedZeroStock(): void
    {
        $rows = $this->buildRows([$this->product([
            $this->variant(['inventory_management' => '', 'inventory_quantity' => 0]),
        ])]);

        $this->assertSame([], $rows);
    }

    public function testContinueSellingPolicyExcludedFromTrackedCount(): void
    {
        $rows = $this->buildRows([$this->product([
            $this->variant(['inventory_policy' => 'continue', 'inventory_quantity' => 0]),
        ])]);

        $this->assertSame([], $rows);
    }

    public function testHealthyProductWithPositiveStockIsNotFlagged(): void
    {
        $rows = $this->buildRows([$this->product([$this->variant(['inventory_quantity' => 10])])]);

        $this->assertSame([], $rows);
    }
}
