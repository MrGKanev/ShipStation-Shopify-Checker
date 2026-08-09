<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';

/**
 * Tests for OrderAnomalyPageLoader::buildOrphanRows() via reflection (private method).
 *
 * Covers priority #1 from docs/audit-test-coverage-gaps.md: orphan matching must
 * reuse the same digit-run extraction as Comparator::buildSSIndex() so compound
 * SS order numbers (e.g. "100042-B2") resolve to their Shopify counterpart.
 */
class OrphanDetectorTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderAnomalyPageLoader::class);
        self::$method = $ref->getMethod('buildOrphanRows');
    }

    private function ssOrder(array $overrides = []): array
    {
        return array_merge([
            'orderId'      => '111',
            'orderNumber'  => '100042',
            'orderStatus'  => 'awaiting_shipment',
            'orderDate'    => '2026-06-01T10:00:00.0000000',
            'shipTo'       => ['name' => 'Jane Doe'],
            'customerEmail'=> 'jane@example.com',
            'orderTotal'   => 49.99,
        ], $overrides);
    }

    private function shOrder(array $overrides = []): array
    {
        return array_merge([
            'order_number' => '100042',
        ], $overrides);
    }

    private function build(array $ssOrders, array $shOrders): array
    {
        return self::$method->invoke(null, $ssOrders, $shOrders);
    }

    private function orderNumbers(array $rows): array
    {
        return array_column($rows, 'order_number');
    }

    // ── Matching bug (priority #1) ──────────────────────────────────────────

    public function testCompoundSsOrderNumberMatchesPlainShopifyOrderNumber(): void
    {
        $rows = $this->build(
            [$this->ssOrder(['orderNumber' => '100042-B2'])],
            [$this->shOrder(['order_number' => '100042'])]
        );

        $this->assertSame([], $this->orderNumbers($rows), 'compound SS order number should match Shopify order 100042 and not be an orphan');
    }

    public function testAddonPrefixedSsOrderNumberMatchesShopifyOrderNumber(): void
    {
        $rows = $this->build(
            [$this->ssOrder(['orderNumber' => 'Addon-100031'])],
            [$this->shOrder(['order_number' => '100031'])]
        );

        $this->assertSame([], $this->orderNumbers($rows));
    }

    // ── Genuine orphan / no-orphan behavior ─────────────────────────────────

    public function testUnmatchedSsOrderIsAnOrphan(): void
    {
        $rows = $this->build(
            [$this->ssOrder(['orderNumber' => '999999'])],
            [$this->shOrder(['order_number' => '100042'])]
        );

        $this->assertSame(['999999'], $this->orderNumbers($rows));
    }

    public function testMatchedPlainOrderNumberIsNotAnOrphan(): void
    {
        $rows = $this->build(
            [$this->ssOrder(['orderNumber' => '100042'])],
            [$this->shOrder(['order_number' => '100042'])]
        );

        $this->assertSame([], $this->orderNumbers($rows));
    }

    public function testSsOrderWithNoOrderNumberIsSkipped(): void
    {
        $rows = $this->build(
            [$this->ssOrder(['orderNumber' => ''])],
            []
        );

        $this->assertSame([], $rows);
    }

    public function testShopifyOrderFallsBackToNameFieldWhenOrderNumberMissing(): void
    {
        $rows = $this->build(
            [$this->ssOrder(['orderNumber' => '100042'])],
            [['name' => '#100042']]
        );

        $this->assertSame([], $this->orderNumbers($rows));
    }

    public function testResultsSortedByOrderDateDescending(): void
    {
        $rows = $this->build(
            [
                $this->ssOrder(['orderNumber' => '1001', 'orderDate' => '2026-06-01T00:00:00.0000000']),
                $this->ssOrder(['orderNumber' => '1002', 'orderDate' => '2026-06-15T00:00:00.0000000']),
            ],
            []
        );

        $this->assertSame(['1002', '1001'], $this->orderNumbers($rows));
    }
}
