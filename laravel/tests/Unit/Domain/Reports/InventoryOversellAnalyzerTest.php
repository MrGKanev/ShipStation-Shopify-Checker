<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\InventoryOversellAnalyzer;
use Tests\TestCase;

class InventoryOversellAnalyzerTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_it_reports_only_eligible_skus_whose_stock_cannot_cover_awaiting_orders(): void
    {
        $products = [
            $this->product('1', 'Risk', [['sku' => 'RISK', 'title' => 'Large', 'inventoryQuantity' => 2, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]]),
            $this->product('2', 'Continue', [['sku' => 'CONTINUE', 'inventoryQuantity' => 0, 'inventoryPolicy' => 'CONTINUE', 'inventoryItem' => ['tracked' => true]]]),
            $this->product('3', 'Untracked', [['sku' => 'UNTRACKED', 'inventoryQuantity' => 0, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => false]]]),
            $this->product('4', 'Blank', [['sku' => ' ', 'inventoryQuantity' => 0, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]]),
            $this->product('5', 'Covered', [['sku' => 'COVERED', 'inventoryQuantity' => 3, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]]),
        ];
        $orders = [['items' => [
            ['sku' => 'RISK', 'quantity' => 4],
            ['sku' => 'COVERED', 'quantity' => 3],
            ['sku' => 'CONTINUE', 'quantity' => 10],
            ['sku' => 'UNTRACKED', 'quantity' => 10],
            ['sku' => 'MISSING', 'quantity' => 10],
            ['sku' => ''],
        ]]];

        $this->assertSame([[
            'sku' => 'RISK', 'product_id' => '1', 'product_title' => 'Risk', 'variant_title' => 'Large',
            'stock' => 2, 'awaiting' => 4, 'shortfall' => 2, 'duplicate_sku' => false,
        ]], (new InventoryOversellAnalyzer)->analyze($products, $orders));
    }

    public function test_it_aggregates_duplicate_stock_and_quantities_and_sorts_by_shortfall(): void
    {
        $products = [
            $this->product('1', 'First', [['sku' => 'DUP', 'inventoryQuantity' => 2, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]]),
            $this->product('2', 'Second', [['sku' => 'DUP', 'inventoryQuantity' => 3, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]]),
            $this->product('3', 'Negative', [['sku' => 'NEG', 'inventoryQuantity' => -2, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]]),
        ];
        $orders = [
            ['items' => [['sku' => 'DUP', 'quantity' => 3], ['sku' => 'NEG']]],
            ['items' => [['sku' => 'DUP', 'quantity' => 4]]],
        ];

        $rows = (new InventoryOversellAnalyzer)->analyze($products, $orders);

        $this->assertSame(['NEG', 'DUP'], array_column($rows, 'sku'));
        $this->assertSame(3, $rows[0]['shortfall']);
        $this->assertSame([
            'product_id' => '', 'product_title' => '2 products share this SKU', 'variant_title' => '',
            'stock' => 5, 'awaiting' => 7, 'shortfall' => 2, 'duplicate_sku' => true,
        ], array_diff_key($rows[1], ['sku' => true]));
    }

    /** @param list<array<string, mixed>> $variants */
    private function product(string $id, string $title, array $variants): array
    {
        return ['legacyResourceId' => $id, 'title' => $title, 'variants' => $variants];
    }
}
