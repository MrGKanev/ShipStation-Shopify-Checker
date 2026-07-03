<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/Shopify/GraphQL/Ids.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/MetafieldNormalizer.php';

use PHPUnit\Framework\TestCase;

class MetafieldNormalizerTest extends TestCase
{
    // ── normalizeMetafield ────────────────────────────────────────────────────

    public function testNormalizeMetafieldBasicNode(): void
    {
        $metafield = [
            'id'        => 'gid://shopify/Metafield/5001',
            'namespace' => 'custom',
            'key'       => 'order_source',
            'value'     => 'web',
            'type'      => 'single_line_text_field',
            'createdAt' => '2024-06-01T08:00:00Z',
            'updatedAt' => '2024-06-02T12:00:00Z',
        ];

        $result = \Shopify\GraphQL\MetafieldNormalizer::normalizeMetafield($metafield, '10001');

        $this->assertSame(5001, $result['id']);
        $this->assertSame('custom', $result['namespace']);
        $this->assertSame('order_source', $result['key']);
        $this->assertSame('web', $result['value']);
        $this->assertSame('single_line_text_field', $result['type']);
        $this->assertSame(10001, $result['owner_id']);
        $this->assertSame('order', $result['owner_resource']);
        $this->assertSame('2024-06-01T08:00:00Z', $result['created_at']);
        $this->assertSame('2024-06-02T12:00:00Z', $result['updated_at']);
        $this->assertSame('gid://shopify/Metafield/5001', $result['admin_graphql_api_id']);
    }

    public function testNormalizeMetafieldOwnerIdDerivedFromNumericString(): void
    {
        $metafield = [
            'id'        => 'gid://shopify/Metafield/5002',
            'namespace' => 'ns',
            'key'       => 'key',
            'value'     => 'val',
            'type'      => 'single_line_text_field',
        ];

        $result = \Shopify\GraphQL\MetafieldNormalizer::normalizeMetafield($metafield, '99999');

        $this->assertSame(99999, $result['owner_id']);
        $this->assertIsInt($result['owner_id']);
    }

    public function testNormalizeMetafieldOwnerIdAcceptsFullGid(): void
    {
        $metafield = [
            'id'        => 'gid://shopify/Metafield/5003',
            'namespace' => 'ns',
            'key'       => 'key',
            'value'     => 'val',
            'type'      => 'boolean',
        ];

        $result = \Shopify\GraphQL\MetafieldNormalizer::normalizeMetafield($metafield, 'gid://shopify/Order/77777');

        $this->assertSame(77777, $result['owner_id']);
    }

    public function testNormalizeMetafieldEmptyOptionalFields(): void
    {
        $metafield = [
            'id' => 'gid://shopify/Metafield/5004',
        ];

        $result = \Shopify\GraphQL\MetafieldNormalizer::normalizeMetafield($metafield, '12345');

        $this->assertSame('', $result['namespace']);
        $this->assertSame('', $result['key']);
        $this->assertSame('', $result['value']);
        $this->assertSame('', $result['type']);
        $this->assertSame('', $result['created_at']);
        $this->assertSame('', $result['updated_at']);
    }

    public function testNormalizeMetafieldOwnerResourceIsAlwaysOrder(): void
    {
        $metafield = ['id' => 'gid://shopify/Metafield/5005'];
        $result    = \Shopify\GraphQL\MetafieldNormalizer::normalizeMetafield($metafield, '1');

        $this->assertSame('order', $result['owner_resource']);
    }

    public function testNormalizeMetafieldJsonValueIsPreservedAsString(): void
    {
        $metafield = [
            'id'        => 'gid://shopify/Metafield/5006',
            'namespace' => 'custom',
            'key'       => 'data',
            'value'     => '{"foo":"bar"}',
            'type'      => 'json',
        ];

        $result = \Shopify\GraphQL\MetafieldNormalizer::normalizeMetafield($metafield, '200');

        $this->assertSame('{"foo":"bar"}', $result['value']);
    }
}
