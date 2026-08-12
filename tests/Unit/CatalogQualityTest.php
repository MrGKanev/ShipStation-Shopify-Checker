<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';

/**
 * Tests for ProductInventoryPageLoader::buildCatalogQualityRows() via reflection (private method).
 */
class CatalogQualityTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(ProductInventoryPageLoader::class);
        self::$method = $ref->getMethod('buildCatalogQualityRows');
    }

    private function product(array $overrides = []): array
    {
        return array_merge([
            'id'                => 1,
            'title'             => 'Widget',
            'vendor'            => 'Acme',
            'product_type'      => 'Gadgets',
            'published'         => true,
            'seo_title'         => 'Widget - Buy now',
            'seo_description'   => 'The best widget.',
            'collection_count'  => 1,
        ], $overrides);
    }

    private function buildRows(array $products): array
    {
        return self::$method->invoke(null, $products);
    }

    public function testHealthyProductIsNotFlagged(): void
    {
        $rows = $this->buildRows([$this->product()]);

        $this->assertSame([], $rows);
    }

    public function testUnpublishedProductIsFlagged(): void
    {
        $rows = $this->buildRows([$this->product(['published' => false])]);

        $this->assertSame(['Not published to Online Store'], $rows[0]['issues']);
    }

    public function testMissingSeoTitleIsFlagged(): void
    {
        $rows = $this->buildRows([$this->product(['seo_title' => ''])]);

        $this->assertSame(['Missing SEO title'], $rows[0]['issues']);
    }

    public function testMissingSeoDescriptionIsFlagged(): void
    {
        $rows = $this->buildRows([$this->product(['seo_description' => ''])]);

        $this->assertSame(['Missing SEO description'], $rows[0]['issues']);
    }

    public function testNoCollectionIsFlagged(): void
    {
        $rows = $this->buildRows([$this->product(['collection_count' => 0])]);

        $this->assertSame(['Not in any collection'], $rows[0]['issues']);
    }

    public function testMultipleIssuesAreAllListed(): void
    {
        $rows = $this->buildRows([$this->product([
            'published'        => false,
            'seo_title'        => '',
            'seo_description'  => '',
            'collection_count' => 0,
        ])]);

        $this->assertSame([
            'Not published to Online Store',
            'Missing SEO title',
            'Missing SEO description',
            'Not in any collection',
        ], $rows[0]['issues']);
    }
}
