<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\CatalogQualityAnalyzer;
use PHPUnit\Framework\TestCase;

class CatalogQualityAnalyzerTest extends TestCase
{
    public function test_healthy_product_is_not_reported(): void
    {
        $this->assertSame([], (new CatalogQualityAnalyzer)->analyze([$this->product()]));
    }

    public function test_reports_each_quality_issue_in_stable_order(): void
    {
        $rows = (new CatalogQualityAnalyzer)->analyze([$this->product(['onlineStoreUrl' => null, 'seo' => ['title' => ' ', 'description' => ''], 'collections' => ['nodes' => []]])]);

        $this->assertSame(['Not published to Online Store', 'Missing SEO title', 'Missing SEO description', 'Not in any collection'], $rows[0]['issues']);
    }

    public function test_reports_individual_seo_and_collection_decisions(): void
    {
        $rows = (new CatalogQualityAnalyzer)->analyze([
            $this->product(['seo' => ['title' => '', 'description' => 'Description']]),
            $this->product(['seo' => ['title' => 'Title', 'description' => '']]),
            $this->product(['collections' => ['nodes' => []]]),
        ]);

        $this->assertSame(['Missing SEO title'], $rows[0]['issues']);
        $this->assertSame(['Missing SEO description'], $rows[1]['issues']);
        $this->assertSame(['Not in any collection'], $rows[2]['issues']);
    }

    public function test_malformed_nested_data_becomes_missing_and_unsafe_id_is_not_linkable(): void
    {
        $rows = (new CatalogQualityAnalyzer)->analyze([['legacyResourceId' => 'javascript:1', 'title' => '<script>x</script>', 'vendor' => '<img>', 'productType' => '<b>', 'onlineStoreUrl' => [], 'seo' => 'bad', 'collections' => 'bad']]);

        $this->assertSame('', $rows[0]['id']);
        $this->assertSame('<script>x</script>', $rows[0]['title']);
        $this->assertCount(4, $rows[0]['issues']);
    }

    private function product(array $overrides = []): array
    {
        return array_replace(['legacyResourceId' => '42', 'title' => 'Widget', 'vendor' => 'Acme', 'productType' => 'Gadget', 'onlineStoreUrl' => 'https://example.test/products/widget', 'seo' => ['title' => 'Widget', 'description' => 'Useful widget'], 'collections' => ['nodes' => [['id' => 'gid://shopify/Collection/1']]]], $overrides);
    }
}
