<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';

/**
 * Tests for the pure row-building logic behind SLA Breaches and Shipment
 * Aging (previously wiring-only tests, see docs/audit-test-coverage-gaps.md),
 * accessed via reflection (private methods).
 */
class FulfillmentLogisticsChecksTest extends TestCase
{
    private static \ReflectionMethod $slaBreaches;
    private static \ReflectionMethod $shipmentAging;
    private static \ReflectionMethod $firstFulfillmentAt;
    private static \ReflectionMethod $shippingMethod;
    private static \ReflectionMethod $addressRegion;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        self::$slaBreaches        = $ref->getMethod('buildSlaBreachRows');
        self::$shipmentAging      = $ref->getMethod('buildShipmentAgingData');
        self::$firstFulfillmentAt = $ref->getMethod('firstFulfillmentAt');
        self::$shippingMethod     = $ref->getMethod('shippingMethod');
        self::$addressRegion      = $ref->getMethod('addressRegion');
    }

    protected function setUp(): void
    {
        Comparator::setOrderTypesConfig(['fallback' => 'Other', 'rules' => []]);
    }

    protected function tearDown(): void
    {
        Comparator::resetOrderTypesConfig();
    }

    // ── SLA Breaches ─────────────────────────────────────────────────────────

    private const NOW = 1_800_000_000; // fixed reference "now" for deterministic day math

    private function slaOrder(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'name'               => '#1001',
            'created_at'         => gmdate('c', self::NOW - 10 * 86400),
            'email'              => 'jane@example.com',
            'total_price'        => '99.00',
            'financial_status'   => 'paid',
            'fulfillment_status' => null,
            'cancelled_at'       => null,
            'fulfillments'       => [],
            'shipping_lines'     => [['title' => 'Standard Shipping']],
            'shipping_address'   => ['province_code' => 'MA', 'country_code' => 'US'],
        ], $overrides);
    }

    public function testOpenOrderPastThresholdMeasuredAgainstNow(): void
    {
        $order = $this->slaOrder(); // created 10 days ago, never fulfilled

        $rows = self::$slaBreaches->invoke(null, [$order], 3, self::NOW);

        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]['days']);
    }

    public function testFulfilledOrderMeasuredFromPlacementToFirstFulfillment(): void
    {
        $order = $this->slaOrder([
            'created_at'   => gmdate('c', self::NOW - 20 * 86400),
            'fulfillments' => [['created_at' => gmdate('c', self::NOW - 15 * 86400)]], // fulfilled after 5 days
        ]);

        $rows = self::$slaBreaches->invoke(null, [$order], 3, self::NOW);

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows[0]['days']);
    }

    public function testUnderThresholdIsExcluded(): void
    {
        $order = $this->slaOrder(['created_at' => gmdate('c', self::NOW - 1 * 86400)]);

        $rows = self::$slaBreaches->invoke(null, [$order], 3, self::NOW);

        $this->assertSame([], $rows);
    }

    public function testCancelledOrderIsExcluded(): void
    {
        $order = $this->slaOrder(['cancelled_at' => gmdate('c', self::NOW - 5 * 86400)]);

        $rows = self::$slaBreaches->invoke(null, [$order], 3, self::NOW);

        $this->assertSame([], $rows);
    }

    public function testRefundedOrderIsExcluded(): void
    {
        $order = $this->slaOrder(['financial_status' => 'refunded']);

        $rows = self::$slaBreaches->invoke(null, [$order], 3, self::NOW);

        $this->assertSame([], $rows);
    }

    public function testVoidedOrderIsExcluded(): void
    {
        $order = $this->slaOrder(['financial_status' => 'voided']);

        $rows = self::$slaBreaches->invoke(null, [$order], 3, self::NOW);

        $this->assertSame([], $rows);
    }

    // ── firstFulfillmentAt / shippingMethod / addressRegion ─────────────────

    public function testFirstFulfillmentAtReturnsEarliestTimestamp(): void
    {
        $order = ['fulfillments' => [
            ['created_at' => '2026-06-10T00:00:00Z'],
            ['created_at' => '2026-06-05T00:00:00Z'],
        ]];

        $this->assertSame('2026-06-05T00:00:00Z', self::$firstFulfillmentAt->invoke(null, $order));
    }

    public function testFirstFulfillmentAtEmptyWhenNoFulfillments(): void
    {
        $this->assertSame('', self::$firstFulfillmentAt->invoke(null, []));
    }

    public function testShippingMethodUsesTitle(): void
    {
        $order = ['shipping_lines' => [['title' => 'Express', 'code' => 'EXP']]];

        $this->assertSame('Express', self::$shippingMethod->invoke(null, $order));
    }

    public function testShippingMethodFallsBackToUnknown(): void
    {
        $this->assertSame('Unknown', self::$shippingMethod->invoke(null, ['shipping_lines' => []]));
    }

    public function testAddressRegionCombinesProvinceAndCountry(): void
    {
        $addr = ['province_code' => 'MA', 'country_code' => 'US'];

        $this->assertSame('MA, US', self::$addressRegion->invoke(null, $addr));
    }

    public function testAddressRegionUnknownForNullAddress(): void
    {
        $this->assertSame('Unknown', self::$addressRegion->invoke(null, null));
    }

    // ── Shipment Aging ───────────────────────────────────────────────────────

    private function ssAwaitingOrder(array $overrides = []): array
    {
        return array_merge([
            'orderId'       => 1,
            'orderNumber'   => '1001',
            'orderDate'     => gmdate('c', self::NOW - 10 * 86400),
            'shipTo'        => ['name' => 'Jane Doe'],
            'customerEmail' => 'jane@example.com',
            'orderTotal'    => 50.0,
            'orderStatus'   => 'awaiting_shipment',
            'items'         => [['sku' => 'SKU-A', 'name' => 'Widget', 'quantity' => 2]],
        ], $overrides);
    }

    public function testOverThresholdOrderIsFlagged(): void
    {
        [$rows,,] = self::$shipmentAging->invoke(null, [$this->ssAwaitingOrder()], 3, self::NOW);

        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]['days']);
    }

    public function testUnderThresholdOrderIsExcluded(): void
    {
        $order = $this->ssAwaitingOrder(['orderDate' => gmdate('c', self::NOW - 1 * 86400)]);

        [$rows,,] = self::$shipmentAging->invoke(null, [$order], 3, self::NOW);

        $this->assertSame([], $rows);
    }

    public function testBySkuAggregatesQuantityAndOrderCount(): void
    {
        $orders = [
            $this->ssAwaitingOrder(['orderId' => 1, 'items' => [['sku' => 'SKU-A', 'name' => 'Widget', 'quantity' => 2]]]),
            $this->ssAwaitingOrder(['orderId' => 2, 'items' => [['sku' => 'SKU-A', 'name' => 'Widget', 'quantity' => 3]]]),
        ];

        [, $bySku,] = self::$shipmentAging->invoke(null, $orders, 3, self::NOW);

        $this->assertCount(1, $bySku);
        $this->assertSame('SKU-A', $bySku[0]['sku']);
        $this->assertSame(5, $bySku[0]['qty']);
        $this->assertSame(2, $bySku[0]['orders']);
    }

    public function testByTypeAggregatesUsingClassifyOrder(): void
    {
        Comparator::resetOrderTypesConfig();
        Comparator::setOrderTypesConfig([
            'fallback' => 'Other',
            'rules'    => [[
                'name'  => 'Widgets',
                'match' => 'sku_starts_with',
                'value' => 'SKU-',
            ]],
        ]);

        [, , $byType] = self::$shipmentAging->invoke(null, [$this->ssAwaitingOrder()], 3, self::NOW);

        $this->assertCount(1, $byType);
        $this->assertSame('Widgets', $byType[0]['type']);
        $this->assertSame(1, $byType[0]['orders']);
    }

    public function testRowsCarrySynthesizedSkuQuantities(): void
    {
        $order = $this->ssAwaitingOrder(['items' => [
            ['sku' => 'SKU-A', 'name' => 'Widget', 'quantity' => 2],
            ['sku' => '', 'name' => 'No SKU item', 'quantity' => 1],
        ]]);

        [$rows,,] = self::$shipmentAging->invoke(null, [$order], 3, self::NOW);

        $this->assertSame(['SKU-A' => 2], $rows[0]['skus'], 'blank-SKU items must not appear in the skus breakdown');
    }
}
