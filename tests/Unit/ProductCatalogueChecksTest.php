<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';

/**
 * Tests for the pure row-building logic behind the Product & Catalogue
 * checks that previously had only "missing credentials" wiring tests (see
 * docs/audit-test-coverage-gaps.md): Product Completeness, SKU Duplicates,
 * and Inventory Aging. All accessed via reflection (private methods).
 */
class ProductCatalogueChecksTest extends TestCase
{
    private static \ReflectionMethod $productCheck;
    private static \ReflectionMethod $skuDupes;
    private static \ReflectionMethod $inventoryAging;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(ProductInventoryPageLoader::class);
        self::$productCheck   = $ref->getMethod('buildProductCheckRows');
        self::$skuDupes       = $ref->getMethod('buildSkuDupeRows');
        self::$inventoryAging = $ref->getMethod('buildInventoryAgingRows');
    }

    // ── Product Completeness ─────────────────────────────────────────────────

    private function product(array $overrides = []): array
    {
        return array_merge([
            'id'           => 1,
            'title'        => 'Widget',
            'vendor'       => 'Acme',
            'product_type' => 'Gadget',
            'status'       => 'active',
            'images'       => [['src' => 'https://example.com/a.jpg']],
            'body_html'    => '<p>A great widget.</p>',
            'variants'     => [['sku' => 'W-1', 'title' => 'Default']],
        ], $overrides);
    }

    public function testCompleteProductIsNotFlagged(): void
    {
        $rows = self::$productCheck->invoke(null, [$this->product()]);

        $this->assertSame([], $rows);
    }

    public function testMissingSkuVariantIsCritical(): void
    {
        $product = $this->product(['variants' => [
            ['sku' => '', 'title' => 'A'],
            ['sku' => 'W-2', 'title' => 'B'],
        ]]);

        $rows = self::$productCheck->invoke(null, [$product]);

        $this->assertCount(1, $rows);
        $this->assertSame('critical', $rows[0]['severity']);
        $this->assertStringContainsString('1 of 2 variants missing SKU', $rows[0]['issues'][0]['message']);
    }

    public function testMissingSkuSingularVariantMessage(): void
    {
        $product = $this->product(['variants' => [['sku' => '', 'title' => 'A']]]);

        $rows = self::$productCheck->invoke(null, [$product]);

        $this->assertStringContainsString('1 of 1 variant missing SKU', $rows[0]['issues'][0]['message']);
    }

    public function testNoImagesIsWarning(): void
    {
        $product = $this->product(['images' => []]);

        $rows = self::$productCheck->invoke(null, [$product]);

        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['severity']);
    }

    public function testNoDescriptionIsWarning(): void
    {
        $product = $this->product(['body_html' => '']);

        $rows = self::$productCheck->invoke(null, [$product]);

        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['severity']);
    }

    public function testBothIssueTypesClassifiedCriticalOverall(): void
    {
        $product = $this->product(['images' => [], 'variants' => [['sku' => '', 'title' => 'A']]]);

        $rows = self::$productCheck->invoke(null, [$product]);

        $this->assertCount(1, $rows);
        $this->assertSame('critical', $rows[0]['severity']);
        $this->assertCount(2, $rows[0]['issues']);
    }

    // ── SKU Duplicates ───────────────────────────────────────────────────────

    private function productWithVariants(int $id, string $status, array $variants): array
    {
        return ['id' => $id, 'title' => "Product {$id}", 'status' => $status, 'variants' => $variants];
    }

    public function testDuplicateSkuAcrossProductsIsFlagged(): void
    {
        $products = [
            $this->productWithVariants(1, 'active', [['sku' => 'DUP', 'title' => 'A']]),
            $this->productWithVariants(2, 'active', [['sku' => 'DUP', 'title' => 'B']]),
        ];

        [$rows, $totalVariants] = self::$skuDupes->invoke(null, $products);

        $this->assertCount(1, $rows);
        $this->assertSame('DUP', $rows[0]['sku']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame(2, $totalVariants);
    }

    public function testUniqueSkuIsNotFlagged(): void
    {
        $products = [$this->productWithVariants(1, 'active', [['sku' => 'UNIQUE', 'title' => 'A']])];

        [$rows,] = self::$skuDupes->invoke(null, $products);

        $this->assertSame([], $rows);
    }

    public function testBlankSkusAreIgnored(): void
    {
        $products = [
            $this->productWithVariants(1, 'active', [['sku' => '', 'title' => 'A']]),
            $this->productWithVariants(2, 'active', [['sku' => '', 'title' => 'B']]),
        ];

        [$rows,] = self::$skuDupes->invoke(null, $products);

        $this->assertSame([], $rows, 'variants with no SKU must never be reported as duplicates of each other');
    }

    public function testDraftAndArchivedOnlyDuplicateIsCaught(): void
    {
        $products = [
            $this->productWithVariants(1, 'draft', [['sku' => 'DUP', 'title' => 'A']]),
            $this->productWithVariants(2, 'archived', [['sku' => 'DUP', 'title' => 'B']]),
        ];

        [$rows,] = self::$skuDupes->invoke(null, $products);

        $this->assertCount(1, $rows);
        $this->assertSame(['draft', 'archived'], array_column($rows[0]['variants'], 'product_status'));
    }

    public function testSkuDupesSortedByCountDescending(): void
    {
        $products = [
            $this->productWithVariants(1, 'active', [['sku' => 'A', 'title' => 'x'], ['sku' => 'B', 'title' => 'x']]),
            $this->productWithVariants(2, 'active', [['sku' => 'A', 'title' => 'x'], ['sku' => 'B', 'title' => 'x']]),
            $this->productWithVariants(3, 'active', [['sku' => 'B', 'title' => 'x']]),
        ];

        [$rows,] = self::$skuDupes->invoke(null, $products);

        $this->assertSame(['B', 'A'], array_column($rows, 'sku'));
        $this->assertSame(3, $rows[0]['count']);
    }

    // ── Inventory Aging ──────────────────────────────────────────────────────

    private function agingVariant(array $overrides = []): array
    {
        return array_merge([
            'sku'                   => 'AGE-1',
            'title'                 => 'Default',
            'inventory_management'  => 'shopify',
            'inventory_policy'      => 'deny',
            'inventory_quantity'    => 0,
        ], $overrides);
    }

    private function orderWithLineItem(string $sku, int $qty = 1, string $createdAt = '2026-06-01T00:00:00Z'): array
    {
        return [
            'name'       => '#1001',
            'created_at' => $createdAt,
            'line_items' => [['sku' => $sku, 'quantity' => $qty]],
        ];
    }

    public function testTrackedDenyZeroStockWithRecentSalesIsFlagged(): void
    {
        $products = [['id' => 1, 'title' => 'P', 'variants' => [$this->agingVariant()]]];
        $orders   = [$this->orderWithLineItem('AGE-1', 3)];

        [$rows,] = self::$inventoryAging->invoke(null, $products, $orders);

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['recent_qty']);
    }

    public function testZeroStockWithNoRecentSalesIsExcludedFalsePositiveCheck(): void
    {
        $products = [['id' => 1, 'title' => 'P', 'variants' => [$this->agingVariant()]]];

        [$rows,] = self::$inventoryAging->invoke(null, $products, []);

        $this->assertSame([], $rows);
    }

    public function testPositiveStockWithSalesIsExcludedFalseNegativeCheck(): void
    {
        $products = [['id' => 1, 'title' => 'P', 'variants' => [$this->agingVariant(['inventory_quantity' => 5])]]];
        $orders   = [$this->orderWithLineItem('AGE-1', 3)];

        [$rows,] = self::$inventoryAging->invoke(null, $products, $orders);

        $this->assertSame([], $rows);
    }

    public function testUntrackedVariantIsExcluded(): void
    {
        $products = [['id' => 1, 'title' => 'P', 'variants' => [$this->agingVariant(['inventory_management' => ''])]]];
        $orders   = [$this->orderWithLineItem('AGE-1', 3)];

        [$rows,] = self::$inventoryAging->invoke(null, $products, $orders);

        $this->assertSame([], $rows);
    }

    public function testContinueSellingPolicyIsExcluded(): void
    {
        $products = [['id' => 1, 'title' => 'P', 'variants' => [$this->agingVariant(['inventory_policy' => 'continue'])]]];
        $orders   = [$this->orderWithLineItem('AGE-1', 3)];

        [$rows,] = self::$inventoryAging->invoke(null, $products, $orders);

        $this->assertSame([], $rows);
    }
}
