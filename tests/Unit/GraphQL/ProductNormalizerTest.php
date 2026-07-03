<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/Shopify/GraphQL/Ids.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/ProductNormalizer.php';

use PHPUnit\Framework\TestCase;

class ProductNormalizerTest extends TestCase
{
    // ── normalizeProduct ──────────────────────────────────────────────────────

    private function makeVariantEdge(array $overrides = []): array
    {
        return [
            'node' => array_merge([
                'id'                => 'gid://shopify/ProductVariant/1001',
                'legacyResourceId'  => '1001',
                'title'             => 'Default Title',
                'sku'               => 'PROD-SKU-001',
                'barcode'           => '0123456789',
                'inventoryQuantity' => 10,
                'inventoryPolicy'   => 'DENY',
                'inventoryItem'     => ['tracked' => true],
            ], $overrides),
        ];
    }

    public function testNormalizeProductBasicNode(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/7000',
            'legacyResourceId' => '7000',
            'title'            => 'Super Widget',
            'status'           => 'ACTIVE',
            'descriptionHtml'  => '<p>Great product</p>',
            'vendor'           => 'WidgetCo',
            'productType'      => 'Gadgets',
            'mediaCount'       => ['count' => 2],
            'variants'         => ['edges' => [$this->makeVariantEdge()]],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertSame(7000, $result['id']);
        $this->assertSame('Super Widget', $result['title']);
        $this->assertSame('active', $result['status']);
        $this->assertSame('<p>Great product</p>', $result['body_html']);
        $this->assertSame('WidgetCo', $result['vendor']);
        $this->assertSame('Gadgets', $result['product_type']);
        $this->assertCount(2, $result['images']);
        $this->assertSame('gid://shopify/Product/7000', $result['admin_graphql_api_id']);
    }

    public function testNormalizeProductTwoVariants(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/7001',
            'legacyResourceId' => '7001',
            'title'            => 'Multi Variant Widget',
            'status'           => 'ACTIVE',
            'mediaCount'       => ['count' => 0],
            'variants'         => [
                'edges' => [
                    $this->makeVariantEdge([
                        'id'               => 'gid://shopify/ProductVariant/2001',
                        'legacyResourceId' => '2001',
                        'title'            => 'Red',
                        'sku'              => 'WDGT-RED',
                        'barcode'          => null,
                        'inventoryQuantity' => 5,
                        'inventoryPolicy'  => 'CONTINUE',
                        'inventoryItem'    => ['tracked' => true],
                    ]),
                    $this->makeVariantEdge([
                        'id'               => 'gid://shopify/ProductVariant/2002',
                        'legacyResourceId' => '2002',
                        'title'            => 'Blue',
                        'sku'              => 'WDGT-BLUE',
                        'barcode'          => '987654321',
                        'inventoryQuantity' => 3,
                        'inventoryPolicy'  => 'DENY',
                        'inventoryItem'    => ['tracked' => false],
                    ]),
                ],
            ],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertCount(2, $result['variants']);

        $v1 = $result['variants'][0];
        $this->assertSame(2001, $v1['id']);
        $this->assertSame(7001, $v1['product_id']);
        $this->assertSame('Red', $v1['title']);
        $this->assertSame('WDGT-RED', $v1['sku']);
        $this->assertNull($v1['barcode']);
        $this->assertSame(5, $v1['inventory_quantity']);
        $this->assertSame('continue', $v1['inventory_policy']);
        $this->assertSame('shopify', $v1['inventory_management']);
        $this->assertSame('gid://shopify/ProductVariant/2001', $v1['admin_graphql_api_id']);

        $v2 = $result['variants'][1];
        $this->assertSame(2002, $v2['id']);
        $this->assertSame(3, $v2['inventory_quantity']);
        $this->assertNull($v2['inventory_management']);
    }

    public function testNormalizeProductEmptyVariants(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/7002',
            'legacyResourceId' => '7002',
            'title'            => 'Simple Product',
            'status'           => 'DRAFT',
            'mediaCount'       => ['count' => 0],
            'variants'         => ['edges' => []],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertSame([], $result['variants']);
        $this->assertSame([], $result['images']);
    }

    public function testNormalizeProductImagesFromMediaCount(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/7003',
            'legacyResourceId' => '7003',
            'title'            => 'Image Rich Product',
            'status'           => 'ACTIVE',
            'mediaCount'       => ['count' => 5],
            'variants'         => ['edges' => []],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertCount(5, $result['images']);
    }

    public function testNormalizeProductMediaCountZeroGivesEmptyImages(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/7004',
            'legacyResourceId' => '7004',
            'title'            => 'No Image Product',
            'status'           => 'ACTIVE',
            'mediaCount'       => ['count' => 0],
            'variants'         => ['edges' => []],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertSame([], $result['images']);
    }

    public function testNormalizeProductMissingMediaCountGivesEmptyImages(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/7005',
            'legacyResourceId' => '7005',
            'title'            => 'Product',
            'status'           => 'ACTIVE',
            'variants'         => ['edges' => []],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertSame([], $result['images']);
    }

    public function testNormalizeProductStatusIsLowercased(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/7006',
            'legacyResourceId' => '7006',
            'status'           => 'ARCHIVED',
            'variants'         => ['edges' => []],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertSame('archived', $result['status']);
    }

    public function testNormalizeProductVariantInventoryManagementNullWhenNotTracked(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/7007',
            'legacyResourceId' => '7007',
            'status'           => 'ACTIVE',
            'mediaCount'       => ['count' => 0],
            'variants'         => [
                'edges' => [
                    $this->makeVariantEdge([
                        'inventoryItem' => ['tracked' => false],
                    ]),
                ],
            ],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertNull($result['variants'][0]['inventory_management']);
    }

    public function testNormalizeProductUsesLegacyResourceIdForProductId(): void
    {
        $node = [
            'id'               => 'gid://shopify/Product/8888',
            'legacyResourceId' => '8888',
            'title'            => 'Product',
            'status'           => 'ACTIVE',
            'variants'         => ['edges' => []],
        ];

        $result = \Shopify\GraphQL\ProductNormalizer::normalizeProduct($node);

        $this->assertSame(8888, $result['id']);
    }
}
