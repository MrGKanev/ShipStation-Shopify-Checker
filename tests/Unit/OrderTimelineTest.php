<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderInsightPageLoader.php';

/**
 * Tests for OrderInsightPageLoader's core timeline/risk logic
 * (buildOrderTimeline, analyzeOrderRisks, calcTimeToShip) via reflection
 * (private methods). Previously these had zero coverage - only the
 * wrapper's input-parsing/error paths were tested.
 */
class OrderTimelineTest extends TestCase
{
    private static \ReflectionMethod $buildTimeline;
    private static \ReflectionMethod $analyzeRisks;
    private static \ReflectionMethod $calcTimeToShip;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderInsightPageLoader::class);
        self::$buildTimeline  = $ref->getMethod('buildOrderTimeline');
        self::$analyzeRisks   = $ref->getMethod('analyzeOrderRisks');
        self::$calcTimeToShip = $ref->getMethod('calcTimeToShip');
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'created_at' => '2026-06-01T10:00:00Z',
        ], $overrides);
    }

    private function timeline(array $order, array $events = [], array $ssOrders = [], array $ssShipments = []): array
    {
        return self::$buildTimeline->invoke(null, $order, $events, $ssOrders, $ssShipments);
    }

    private function risks(array $order, array $ssOrders = []): array
    {
        return self::$analyzeRisks->invoke(null, $order, $ssOrders);
    }

    private function timeToShip(array $order): ?int
    {
        return self::$calcTimeToShip->invoke(null, $order);
    }

    // ── calcTimeToShip ───────────────────────────────────────────────────────

    public function testTimeToShipNullWithoutFulfillments(): void
    {
        $this->assertNull($this->timeToShip($this->order()));
    }

    public function testTimeToShipNullWithoutCreatedAt(): void
    {
        $order = ['fulfillments' => [['created_at' => '2026-06-02T10:00:00Z']]];
        $this->assertNull($this->timeToShip($order));
    }

    public function testTimeToShipComputesDaysFromFirstFulfillment(): void
    {
        $order = $this->order([
            'created_at'   => '2026-06-01T10:00:00Z',
            'fulfillments' => [['created_at' => '2026-06-05T10:00:00Z']],
        ]);

        $this->assertSame(4, $this->timeToShip($order));
    }

    public function testTimeToShipClampsNegativeToZero(): void
    {
        $order = $this->order([
            'created_at'   => '2026-06-05T10:00:00Z',
            'fulfillments' => [['created_at' => '2026-06-01T10:00:00Z']],
        ]);

        $this->assertSame(0, $this->timeToShip($order));
    }

    // ── analyzeOrderRisks ────────────────────────────────────────────────────

    public function testNoRisksForCleanRecentOrder(): void
    {
        $order = $this->order([
            'fulfillments' => [['created_at' => '2026-06-02T10:00:00Z', 'tracking_number' => 'TRK1']],
        ]);

        $this->assertSame([], $this->risks($order));
    }

    public function testDangerRiskWhenSlowToShipOverSevenDays(): void
    {
        $order = $this->order([
            'created_at'   => '2026-06-01T10:00:00Z',
            'fulfillments' => [['created_at' => '2026-06-10T10:00:00Z', 'tracking_number' => 'TRK1']],
        ]);

        $risks = $this->risks($order);

        $this->assertSame('danger', $risks[0]['level']);
        $this->assertStringContainsString('Slow to ship: 9 days', $risks[0]['msg']);
    }

    public function testWarnRiskWhenSlowToShipBetweenFourAndSevenDays(): void
    {
        $order = $this->order([
            'created_at'   => '2026-06-01T10:00:00Z',
            'fulfillments' => [['created_at' => '2026-06-05T10:00:00Z', 'tracking_number' => 'TRK1']],
        ]);

        $this->assertSame('warn', $this->risks($order)[0]['level']);
    }

    public function testNoSlowShipRiskAtThreeDaysOrLess(): void
    {
        $order = $this->order([
            'created_at'   => '2026-06-01T10:00:00Z',
            'fulfillments' => [['created_at' => '2026-06-03T10:00:00Z', 'tracking_number' => 'TRK1']],
        ]);

        $this->assertSame([], $this->risks($order));
    }

    public function testDangerRiskWhenCancelledButHasFulfillments(): void
    {
        $order = $this->order([
            'cancelled_at' => '2026-06-05T10:00:00Z',
            'fulfillments' => [['created_at' => '2026-06-02T10:00:00Z', 'tracking_number' => 'TRK1']],
        ]);

        $risks = $this->risks($order);

        $this->assertNotEmpty(array_filter($risks, fn($r) => str_contains($r['msg'], 'cancelled but has fulfillments')));
    }

    public function testDangerRiskWhenRefundedButActiveInShipStation(): void
    {
        $order = $this->order(['financial_status' => 'refunded']);
        $ssOrders = [['orderStatus' => 'awaiting_shipment']];

        $risks = $this->risks($order, $ssOrders);

        $this->assertNotEmpty(array_filter($risks, fn($r) => str_contains($r['msg'], 'refunded in Shopify but still active')));
    }

    public function testNoActiveRefundRiskWhenShipStationNotActive(): void
    {
        $order = $this->order(['financial_status' => 'refunded']);
        $ssOrders = [['orderStatus' => 'shipped']];

        $risks = $this->risks($order, $ssOrders);

        $this->assertEmpty(array_filter($risks, fn($r) => str_contains($r['msg'], 'still active')));
    }

    public function testWarnRiskWhenFulfillmentMissingTracking(): void
    {
        $order = $this->order(['fulfillments' => [['created_at' => '2026-06-02T10:00:00Z', 'tracking_number' => '']]]);

        $risks = $this->risks($order);

        $this->assertNotEmpty(array_filter($risks, fn($r) => $r['msg'] === 'Fulfillment exists without a tracking number'));
    }

    public function testInfoRiskWhenMultipleFulfillments(): void
    {
        $order = $this->order(['fulfillments' => [
            ['created_at' => '2026-06-02T10:00:00Z', 'tracking_number' => 'TRK1'],
            ['created_at' => '2026-06-03T10:00:00Z', 'tracking_number' => 'TRK2'],
        ]]);

        $risks = $this->risks($order);

        $this->assertNotEmpty(array_filter($risks, fn($r) => $r['level'] === 'info' && str_contains($r['msg'], '2 separate fulfillments')));
    }

    // ── buildOrderTimeline ───────────────────────────────────────────────────

    public function testIncludesOrderPlacedItem(): void
    {
        $items = $this->timeline($this->order(['email' => 'jane@example.com']));

        $this->assertSame('order_placed', $items[0]['type']);
        $this->assertSame('jane@example.com', $items[0]['detail']);
    }

    public function testIncludesPaymentItemOnlyWhenPaidWithProcessedAt(): void
    {
        $paid = $this->timeline($this->order(['financial_status' => 'paid', 'processed_at' => '2026-06-01T11:00:00Z', 'total_price' => '49.99']));
        $unpaid = $this->timeline($this->order(['financial_status' => 'pending']));

        $this->assertNotEmpty(array_filter($paid, fn($i) => $i['type'] === 'payment' && $i['detail'] === '$49.99'));
        $this->assertEmpty(array_filter($unpaid, fn($i) => $i['type'] === 'payment'));
    }

    public function testIncludesFulfillmentItemWithCarrierAndItemCount(): void
    {
        $order = $this->order(['fulfillments' => [[
            'created_at' => '2026-06-02T10:00:00Z',
            'line_items' => [['id' => 1], ['id' => 2]],
            'tracking_number' => 'TRK1',
            'tracking_company' => 'UPS',
            'tracking_url' => 'https://track.test/TRK1',
        ]]]);

        $items = $this->timeline($order);
        $fulfillment = current(array_filter($items, fn($i) => $i['type'] === 'fulfillment'));

        $this->assertSame('2 items · UPS', $fulfillment['detail']);
        $this->assertSame('TRK1', $fulfillment['tracking']);
        $this->assertSame('https://track.test/TRK1', $fulfillment['url']);
    }

    public function testRefundAmountSumsOnlySuccessfulRefundTransactions(): void
    {
        $order = $this->order(['refunds' => [[
            'created_at' => '2026-06-03T10:00:00Z',
            'transactions' => [
                ['kind' => 'refund', 'status' => 'success', 'amount' => '10.00'],
                ['kind' => 'refund', 'status' => 'failure', 'amount' => '99.00'],
                ['kind' => 'sale', 'status' => 'success', 'amount' => '5.00'],
            ],
        ]]]);

        $items = $this->timeline($order);
        $refund = current(array_filter($items, fn($i) => $i['type'] === 'refund'));

        $this->assertSame('$10.00', $refund['detail']);
    }

    public function testRefundDetailIncludesNoteWhenPresent(): void
    {
        $order = $this->order(['refunds' => [[
            'created_at' => '2026-06-03T10:00:00Z',
            'note' => 'Wrong size',
            'transactions' => [
                ['kind' => 'refund', 'status' => 'success', 'amount' => '10.00'],
            ],
        ]]]);

        $items = $this->timeline($order);
        $refund = current(array_filter($items, fn($i) => $i['type'] === 'refund'));

        $this->assertSame('$10.00 · Wrong size', $refund['detail']);
    }

    public function testRefundDetailIsJustNoteWhenNoAmount(): void
    {
        $order = $this->order(['refunds' => [[
            'created_at' => '2026-06-03T10:00:00Z',
            'note' => 'Customer requested cancellation',
            'transactions' => [],
        ]]]);

        $items = $this->timeline($order);
        $refund = current(array_filter($items, fn($i) => $i['type'] === 'refund'));

        $this->assertSame('Customer requested cancellation', $refund['detail']);
    }

    public function testIncludesCancelledItemWithFormattedReason(): void
    {
        $order = $this->order(['cancelled_at' => '2026-06-04T10:00:00Z', 'cancel_reason' => 'customer_request']);

        $items = $this->timeline($order);
        $cancelled = current(array_filter($items, fn($i) => $i['type'] === 'cancelled'));

        $this->assertSame('Customer request', $cancelled['detail']);
    }

    public function testIncludesClosedItem(): void
    {
        $items = $this->timeline($this->order(['closed_at' => '2026-06-06T10:00:00Z']));

        $this->assertNotEmpty(array_filter($items, fn($i) => $i['type'] === 'closed'));
    }

    public function testSkipsRedundantShopifyEventVerbsButKeepsOthers(): void
    {
        $events = [
            ['verb' => 'placed', 'created_at' => '2026-06-01T10:00:00Z', 'message' => 'noise'],
            ['verb' => 'note_added', 'created_at' => '2026-06-02T10:00:00Z', 'message' => 'Left a note'],
        ];

        $items = $this->timeline($this->order(), $events);
        $eventItems = array_filter($items, fn($i) => $i['type'] === 'shopify_event');

        $this->assertCount(1, $eventItems);
        $this->assertSame('Left a note', current($eventItems)['title']);
    }

    public function testSsOrderItemSkippedWithoutTimestampAndIncludesUrlWhenPresent(): void
    {
        $ssOrders = [
            ['orderId' => '', 'orderStatus' => 'awaiting_shipment'],
            ['orderId' => '555', 'orderStatus' => 'on_hold', 'createDate' => '2026-06-02T10:00:00Z'],
        ];

        $items = $this->timeline($this->order(), [], $ssOrders);
        $ssItems = array_values(array_filter($items, fn($i) => $i['type'] === 'ss_order'));

        $this->assertCount(1, $ssItems);
        $this->assertSame('ShipStation: On hold', $ssItems[0]['title']);
        $this->assertStringContainsString('555', $ssItems[0]['url']);
    }

    public function testSsOrderItemHandlesIntegerOrderId(): void
    {
        // ShipStation's API returns orderId as an int; urlencode() requires a
        // string under strict_types, so this must not throw a TypeError.
        $ssOrders = [
            ['orderId' => 555, 'orderStatus' => 'on_hold', 'createDate' => '2026-06-02T10:00:00Z'],
        ];

        $items = $this->timeline($this->order(), [], $ssOrders);
        $ssItems = array_values(array_filter($items, fn($i) => $i['type'] === 'ss_order'));

        $this->assertCount(1, $ssItems);
        $this->assertStringContainsString('555', $ssItems[0]['url']);
    }

    public function testSsShipmentItemSkippedWithoutShipDate(): void
    {
        $ssShipments = [
            ['carrierCode' => 'ups', 'trackingNumber' => 'TRK9'],
            ['shipDate' => '2026-06-03T10:00:00Z', 'carrierCode' => 'ups', 'trackingNumber' => 'TRK9'],
        ];

        $items = $this->timeline($this->order(), [], [], $ssShipments);
        $shipItems = array_values(array_filter($items, fn($i) => $i['type'] === 'ss_shipment'));

        $this->assertCount(1, $shipItems);
        $this->assertSame('UPS · TRK9', $shipItems[0]['detail']);
    }

    public function testItemsAreSortedNewestFirst(): void
    {
        $order = $this->order([
            'created_at'   => '2026-06-01T10:00:00Z',
            'closed_at'    => '2026-06-10T10:00:00Z',
        ]);

        $items = $this->timeline($order);

        $this->assertSame('closed', $items[0]['type']);
        $this->assertSame('order_placed', $items[1]['type']);
    }
}
