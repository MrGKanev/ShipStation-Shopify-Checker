<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';

/**
 * Tests for FulfillmentIssuePageLoader::buildSsShippedUnfulfilledRows() via
 * reflection (private method). Previously lived inline in
 * loadSsShippedUnfulfilled() with zero coverage beyond the wrapper's
 * missing-credentials test.
 */
class SsShippedUnfulfilledTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        self::$method = $ref->getMethod('buildSsShippedUnfulfilledRows');
    }

    private function ssOrder(array $overrides = []): array
    {
        return array_merge([
            'orderId'      => '555',
            'orderNumber'  => '1001',
            'orderStatus'  => 'shipped',
            'orderDate'    => '2026-06-01T10:00:00Z',
            'shipTo'       => ['name' => 'Jane Doe'],
            'customerEmail'=> 'jane@example.com',
            'orderTotal'   => 49.99,
        ], $overrides);
    }

    private function shOrder(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 111,
            'name'               => '#1001',
            'order_number'       => '1001',
            'fulfillment_status' => null,
            'financial_status'   => 'paid',
        ], $overrides);
    }

    private function buildRows(array $ssOrders, array $shOrders): array
    {
        return self::$method->invoke(null, $ssOrders, $shOrders);
    }

    public function testFlagsShippedSsOrderWithUnfulfilledShopifyMatch(): void
    {
        $rows = $this->buildRows([$this->ssOrder()], [$this->shOrder(['fulfillment_status' => null])]);

        $this->assertCount(1, $rows);
        $this->assertSame('unfulfilled', $rows[0]['sh_fulfillment']);
    }

    public function testExcludesWhenShopifyOrderIsFulfilled(): void
    {
        $rows = $this->buildRows([$this->ssOrder()], [$this->shOrder(['fulfillment_status' => 'fulfilled'])]);

        $this->assertSame([], $rows);
    }

    public function testExcludesWhenSsOrderNotShipped(): void
    {
        $rows = $this->buildRows([$this->ssOrder(['orderStatus' => 'awaiting_shipment'])], [$this->shOrder()]);

        $this->assertSame([], $rows);
    }

    public function testExcludesWhenNoMatchingShopifyOrder(): void
    {
        $rows = $this->buildRows([$this->ssOrder(['orderNumber' => '9999'])], [$this->shOrder()]);

        $this->assertSame([], $rows);
    }

    public function testPartialFulfillmentStatusIsSurfacedAsIs(): void
    {
        $rows = $this->buildRows([$this->ssOrder()], [$this->shOrder(['fulfillment_status' => 'partial'])]);

        $this->assertSame('partial', $rows[0]['sh_fulfillment']);
    }

    public function testSortedByOrderDateDescending(): void
    {
        $ssOrders = [
            $this->ssOrder(['orderNumber' => '1', 'orderDate' => '2026-06-01T10:00:00Z']),
            $this->ssOrder(['orderNumber' => '2', 'orderDate' => '2026-06-10T10:00:00Z']),
        ];
        $shOrders = [
            $this->shOrder(['order_number' => '1', 'name' => '#OLD']),
            $this->shOrder(['order_number' => '2', 'name' => '#NEW']),
        ];

        $rows = $this->buildRows($ssOrders, $shOrders);

        $this->assertSame(['2', '1'], array_column($rows, 'order_number'));
    }
}
