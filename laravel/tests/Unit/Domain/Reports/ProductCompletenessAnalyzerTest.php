<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ProductCompletenessAnalyzer;
use PHPUnit\Framework\TestCase;

class ProductCompletenessAnalyzerTest extends TestCase
{
    public function test_complete_product_is_not_reported(): void
    {
        $this->assertSame([], (new ProductCompletenessAnalyzer)->analyze([$this->product()]));
    }

    public function test_detects_missing_image_entity_only_description_and_missing_skus(): void
    {
        $rows = (new ProductCompletenessAnalyzer)->analyze([$this->product(['images' => ['nodes' => [[]]], 'descriptionHtml' => '<p>&nbsp; &#8203;</p>', 'variants' => [['sku' => ' '], ['sku' => 'OK']]])]);
        $this->assertSame('critical', $rows[0]['severity']);
        $this->assertSame(['No product images', 'No description', '1 of 2 variants missing SKU'], array_column($rows[0]['issues'], 'message'));
    }

    public function test_no_variants_is_critical(): void
    {
        $rows = (new ProductCompletenessAnalyzer)->analyze([$this->product(['variants' => []])]);
        $this->assertSame('No variants', $rows[0]['issues'][0]['message']);
    }

    public function test_orders_critical_before_warning_then_by_title(): void
    {
        $rows = (new ProductCompletenessAnalyzer)->analyze([$this->product(['title' => 'Zulu', 'images' => ['nodes' => []]]), $this->product(['title' => 'beta', 'variants' => []]), $this->product(['title' => 'Alpha', 'variants' => []])]);
        $this->assertSame(['Alpha', 'beta', 'Zulu'], array_column($rows, 'title'));
    }

    private function product(array $overrides = []): array
    {
        return array_merge(['legacyResourceId' => '1', 'title' => 'Widget', 'vendor' => 'Acme', 'productType' => 'Gadget', 'descriptionHtml' => '<p>Useful</p>', 'images' => ['nodes' => [['id' => 'image']]], 'variants' => [['sku' => 'W-1']]], $overrides);
    }
}
