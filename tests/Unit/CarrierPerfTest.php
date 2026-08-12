<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';

/**
 * Tests for FulfillmentIssuePageLoader::buildCarrierPerfRows() via
 * reflection (private method). Previously lived inline in loadCarrierPerf()
 * with ZERO test coverage of any kind - not even a wrapper/error-path test
 * existed for the Carrier Performance page.
 */
class CarrierPerfTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        self::$method = $ref->getMethod('buildCarrierPerfRows');
    }

    private function shipment(array $overrides = []): array
    {
        return array_merge([
            'carrierCode'  => 'ups',
            'shipDate'     => '2026-06-01T10:00:00Z',
            'deliveryDate' => '2026-06-03T10:00:00Z',
        ], $overrides);
    }

    private function buildRows(array $shipments): array
    {
        return self::$method->invoke(null, $shipments);
    }

    public function testComputesAverageDeliveryDaysPerCarrier(): void
    {
        $rows = $this->buildRows([
            $this->shipment(['shipDate' => '2026-06-01T00:00:00Z', 'deliveryDate' => '2026-06-03T00:00:00Z']),
            $this->shipment(['shipDate' => '2026-06-01T00:00:00Z', 'deliveryDate' => '2026-06-05T00:00:00Z']),
        ]);

        $this->assertSame('ups', $rows[0]['carrier']);
        $this->assertSame(2, $rows[0]['with_delivery']);
        $this->assertSame(3.0, $rows[0]['avg_days']);
    }

    public function testFlagsLateWhenOverFiveDays(): void
    {
        $rows = $this->buildRows([
            $this->shipment(['shipDate' => '2026-06-01T00:00:00Z', 'deliveryDate' => '2026-06-07T00:00:00Z']),
        ]);

        $this->assertSame(1, $rows[0]['late_count']);
        $this->assertSame(100.0, $rows[0]['late_pct']);
    }

    public function testNotLateAtExactlyFiveDays(): void
    {
        $rows = $this->buildRows([
            $this->shipment(['shipDate' => '2026-06-01T00:00:00Z', 'deliveryDate' => '2026-06-06T00:00:00Z']),
        ]);

        $this->assertSame(0, $rows[0]['late_count']);
        $this->assertSame(0.0, $rows[0]['late_pct']);
    }

    public function testShipmentMissingDeliveryDateCountsButExcludedFromAverage(): void
    {
        $rows = $this->buildRows([$this->shipment(['deliveryDate' => ''])]);

        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame(0, $rows[0]['with_delivery']);
        $this->assertNull($rows[0]['avg_days']);
        $this->assertNull($rows[0]['late_pct']);
    }

    public function testDeliveryBeforeShipDateExcludedAsBadData(): void
    {
        $rows = $this->buildRows([
            $this->shipment(['shipDate' => '2026-06-05T00:00:00Z', 'deliveryDate' => '2026-06-01T00:00:00Z']),
        ]);

        $this->assertSame(0, $rows[0]['with_delivery']);
    }

    public function testMissingCarrierCodeGroupsAsUnknown(): void
    {
        $rows = $this->buildRows([$this->shipment(['carrierCode' => ''])]);

        $this->assertSame('Unknown', $rows[0]['carrier']);
    }

    public function testGroupsByCarrierSeparately(): void
    {
        $rows = $this->buildRows([
            $this->shipment(['carrierCode' => 'ups']),
            $this->shipment(['carrierCode' => 'fedex']),
            $this->shipment(['carrierCode' => 'fedex']),
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame('fedex', $rows[0]['carrier']);
        $this->assertSame(2, $rows[0]['count']);
    }

    public function testSortedByCountDescending(): void
    {
        $rows = $this->buildRows([
            $this->shipment(['carrierCode' => 'low']),
            $this->shipment(['carrierCode' => 'high']),
            $this->shipment(['carrierCode' => 'high']),
        ]);

        $this->assertSame(['high', 'low'], array_column($rows, 'carrier'));
    }
}
