<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\SkuDuplicatesAnalyzer;
use PHPUnit\Framework\TestCase;

class SkuDuplicatesAnalyzerTest extends TestCase
{
    public function test_groups_across_all_statuses_and_within_a_product_sorted_by_count(): void
    {
        $result = (new SkuDuplicatesAnalyzer)->analyze([
            $this->product('1', 'ACTIVE', ['A', 'B', 'B']),
            $this->product('2', 'DRAFT', ['A', 'B']),
            $this->product('3', 'ARCHIVED', ['B']),
        ]);

        $this->assertSame(6, $result['totalVariants']);
        $this->assertSame(['B', 'A'], array_column($result['rows'], 'sku'));
        $this->assertSame([4, 2], array_column($result['rows'], 'count'));
        $this->assertSame(['active', 'active', 'draft', 'archived'], array_column($result['rows'][0]['variants'], 'product_status'));
        $this->assertSame(['product_id' => '1', 'product_title' => 'Widget', 'product_status' => 'active', 'variant_title' => 'Default'], $result['rows'][0]['variants'][0]);
    }

    public function test_ignores_blank_and_unique_skus_but_counts_all_variants(): void
    {
        $result = (new SkuDuplicatesAnalyzer)->analyze([$this->product('1', 'ACTIVE', ['', ' ', null, 'UNIQUE', 'unique'])]);

        $this->assertSame(['rows' => [], 'totalVariants' => 5], $result);
        $this->assertSame(['rows' => [], 'totalVariants' => 0], (new SkuDuplicatesAnalyzer)->analyze([]));
    }

    public function test_trims_skus_preserves_numeric_strings_and_case_sensitive_matching(): void
    {
        $result = (new SkuDuplicatesAnalyzer)->analyze([$this->product('1', 'ACTIVE', [' 0 ', '0', '01', '01', 'a', 'A'])]);

        $this->assertSame(['0', '01'], array_column($result['rows'], 'sku'));
        $this->assertSame([2, 2], array_column($result['rows'], 'count'));
    }

    public function test_handles_missing_fields_and_does_not_create_unsafe_product_links(): void
    {
        $result = (new SkuDuplicatesAnalyzer)->analyze([[], ['legacyResourceId' => 'javascript:alert(1)', 'variants' => [['sku' => 'D'], ['sku' => 'D'], ['sku' => []]]]]);

        $this->assertSame(3, $result['totalVariants']);
        $this->assertSame('', $result['rows'][0]['variants'][0]['product_id']);
    }

    private function product(string $id, string $status, array $skus): array
    {
        return ['legacyResourceId' => $id, 'title' => 'Widget', 'status' => $status, 'variants' => array_map(fn (mixed $sku): array => ['sku' => $sku, 'title' => 'Default'], $skus)];
    }
}
