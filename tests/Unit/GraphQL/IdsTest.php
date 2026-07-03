<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/Shopify/GraphQL/Ids.php';

use PHPUnit\Framework\TestCase;

class IdsTest extends TestCase
{
    // ── orderGid ──────────────────────────────────────────────────────────────

    public function testOrderGidWithNumericId(): void
    {
        $this->assertSame(
            'gid://shopify/Order/1234567890',
            \Shopify\GraphQL\Ids::orderGid('1234567890')
        );
    }

    public function testOrderGidWithAlreadyFullGid(): void
    {
        $gid = 'gid://shopify/Order/1234567890';
        $this->assertSame($gid, \Shopify\GraphQL\Ids::orderGid($gid));
    }

    public function testOrderGidWithLeadingAndTrailingSpaces(): void
    {
        $this->assertSame(
            'gid://shopify/Order/999',
            \Shopify\GraphQL\Ids::orderGid('  999  ')
        );
    }

    public function testOrderGidWithSpacedFullGid(): void
    {
        $gid = 'gid://shopify/Order/42';
        $this->assertSame($gid, \Shopify\GraphQL\Ids::orderGid("  {$gid}  "));
    }

    public function testOrderGidThrowsOnNonNumericString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Shopify\GraphQL\Ids::orderGid('abc123');
    }

    public function testOrderGidThrowsOnHashPrefixedString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Shopify\GraphQL\Ids::orderGid('#1001');
    }

    public function testOrderGidThrowsOnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Shopify\GraphQL\Ids::orderGid('');
    }

    public function testOrderGidThrowsOnOtherResourceGid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Shopify\GraphQL\Ids::orderGid('gid://shopify/Product/123');
    }

    // ── legacyId ──────────────────────────────────────────────────────────────

    public function testLegacyIdWithLegacyResourceIdReturnsInt(): void
    {
        $result = \Shopify\GraphQL\Ids::legacyId('8675309', 'gid://shopify/Order/8675309');
        $this->assertSame(8675309, $result);
        $this->assertIsInt($result);
    }

    public function testLegacyIdPreferslLegacyResourceIdOverGid(): void
    {
        $result = \Shopify\GraphQL\Ids::legacyId('111', 'gid://shopify/Order/999');
        $this->assertSame(111, $result);
    }

    public function testLegacyIdWithOnlyGidExtractsNumericId(): void
    {
        $result = \Shopify\GraphQL\Ids::legacyId(null, 'gid://shopify/Order/4242424242');
        $this->assertSame(4242424242, $result);
        $this->assertIsInt($result);
    }

    public function testLegacyIdWithGidContainingQueryString(): void
    {
        $result = \Shopify\GraphQL\Ids::legacyId(null, 'gid://shopify/Order/7777?key=val');
        $this->assertSame(7777, $result);
    }

    public function testLegacyIdWithNullBothReturnsEmptyString(): void
    {
        $result = \Shopify\GraphQL\Ids::legacyId(null, null);
        $this->assertSame('', $result);
    }

    public function testLegacyIdWithEmptyStringLegacyIdAndNullGid(): void
    {
        $result = \Shopify\GraphQL\Ids::legacyId('', null);
        $this->assertSame('', $result);
    }

    public function testLegacyIdWithEmptyStringLegacyIdAndValidGid(): void
    {
        $result = \Shopify\GraphQL\Ids::legacyId('', 'gid://shopify/Order/300');
        $this->assertSame(300, $result);
    }

    public function testLegacyIdWithNonNumericGidReturnsString(): void
    {
        // This GID has no trailing numeric segment — result stays as empty string
        $result = \Shopify\GraphQL\Ids::legacyId(null, 'gid://shopify/Order/');
        $this->assertSame('', $result);
    }

    public function testLegacyIdWithNonNumericLegacyResourceId(): void
    {
        $result = \Shopify\GraphQL\Ids::legacyId('ABC-123', null);
        $this->assertSame('ABC-123', $result);
        $this->assertIsString($result);
    }
}
