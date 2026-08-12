<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';

/**
 * Tests for OrderAnomalyPageLoader::buildRefundRows() via reflection (private
 * method). Covers the Refunds Tracker's risk classification (missing/active/ok)
 * and refunded-amount aggregation, which previously lived in an untested
 * inline closure inside loadRefunds().
 */
class RefundsTrackerTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderAnomalyPageLoader::class);
        self::$method = $ref->getMethod('buildRefundRows');
    }

    private function refundedOrder(array $overrides = []): array
    {
        return array_merge([
            'id'                => 111,
            'name'              => '#1001',
            'order_number'      => '1001',
            'created_at'        => '2026-06-01T10:00:00Z',
            'email'             => 'jane@example.com',
            'financial_status'  => 'refunded',
            'total_price'       => '49.99',
            'refunds'           => [],
        ], $overrides);
    }

    private function ssOrder(array $overrides = []): array
    {
        return array_merge([
            'orderId'     => '555',
            'orderNumber' => '1001',
            'orderStatus' => 'shipped',
        ], $overrides);
    }

    private function buildRows(array $refundedOrders, array $ssRows = []): array
    {
        return self::$method->invoke(null, $refundedOrders, $ssRows);
    }

    public function testNoMatchingShipStationOrderIsRiskMissing(): void
    {
        $rows = $this->buildRows([$this->refundedOrder()], []);

        $this->assertSame('missing', $rows[0]['risk']);
        $this->assertSame([], $rows[0]['ss_orders']);
    }

    public function testMatchedButShippedOrderIsRiskOk(): void
    {
        $rows = $this->buildRows([$this->refundedOrder()], [$this->ssOrder(['orderStatus' => 'shipped'])]);

        $this->assertSame('ok', $rows[0]['risk']);
    }

    public function testMatchedAndAwaitingShipmentIsRiskActive(): void
    {
        $rows = $this->buildRows([$this->refundedOrder()], [$this->ssOrder(['orderStatus' => 'awaiting_shipment'])]);

        $this->assertSame('active', $rows[0]['risk']);
        $this->assertSame(['awaiting_shipment'], $rows[0]['ss_statuses']);
    }

    public function testMatchedAndOnHoldIsRiskActive(): void
    {
        $rows = $this->buildRows([$this->refundedOrder()], [$this->ssOrder(['orderStatus' => 'on_hold'])]);

        $this->assertSame('active', $rows[0]['risk']);
    }

    public function testRefundedAmountSumsRefundLineItemSubtotals(): void
    {
        $order = $this->refundedOrder([
            'refunds' => [
                ['refund_line_items' => [['subtotal' => '10.00'], ['subtotal' => '5.50']]],
                ['refund_line_items' => [['subtotal' => '2.00']]],
            ],
        ]);

        $rows = $this->buildRows([$order]);

        $this->assertSame(17.5, $rows[0]['refunded_amount']);
    }

    public function testRefundedAmountFallsBackToTotalPriceWhenNoLineItems(): void
    {
        $order = $this->refundedOrder(['financial_status' => 'refunded', 'total_price' => '49.99', 'refunds' => []]);

        $rows = $this->buildRows([$order]);

        $this->assertSame(49.99, $rows[0]['refunded_amount']);
    }

    public function testRefundedAmountNotBackfilledWhenNotFullyRefunded(): void
    {
        $order = $this->refundedOrder(['financial_status' => 'partially_refunded', 'total_price' => '49.99', 'refunds' => []]);

        $rows = $this->buildRows([$order]);

        $this->assertSame(0.0, $rows[0]['refunded_amount']);
    }

    public function testSortsActiveBeforeMissingBeforeOk(): void
    {
        $orders = [
            $this->refundedOrder(['name' => '#OK',      'order_number' => '1']),
            $this->refundedOrder(['name' => '#MISSING', 'order_number' => '2']),
            $this->refundedOrder(['name' => '#ACTIVE',  'order_number' => '3']),
        ];
        $ssRows = [
            $this->ssOrder(['orderNumber' => '1', 'orderStatus' => 'shipped']),
            $this->ssOrder(['orderNumber' => '3', 'orderStatus' => 'awaiting_payment']),
        ];

        $rows = $this->buildRows($orders, $ssRows);

        $this->assertSame(['#ACTIVE', '#MISSING', '#OK'], array_column($rows, 'order_number'));
    }

    public function testMatchesShipStationOrderIgnoringHashPrefix(): void
    {
        $order = $this->refundedOrder(['name' => '#1001', 'order_number' => '1001']);
        $rows  = $this->buildRows([$order], [$this->ssOrder(['orderNumber' => '#1001', 'orderStatus' => 'shipped'])]);

        $this->assertSame('ok', $rows[0]['risk']);
    }

    /**
     * Unlike the Orphan Detector (which uses Comparator::orderNumberKeys()
     * for digit-run extraction so compound SS numbers like "1001-B2" resolve
     * to their Shopify counterpart), the Refunds Tracker matches on plain
     * Comparator::normalise(), which folds "-B2" into the digit string and
     * so does NOT recognise a compound suffix as the same order.
     */
    public function testCompoundShipStationOrderNumberDoesNotMatch(): void
    {
        $order = $this->refundedOrder(['name' => '#1001', 'order_number' => '1001']);
        $rows  = $this->buildRows([$order], [$this->ssOrder(['orderNumber' => '1001-B2', 'orderStatus' => 'shipped'])]);

        $this->assertSame('missing', $rows[0]['risk']);
    }
}
