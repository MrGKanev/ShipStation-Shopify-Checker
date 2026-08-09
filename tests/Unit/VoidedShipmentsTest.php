<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';

/**
 * Tests for OrderAnomalyPageLoader::buildFailedShipmentRows() via reflection
 * (private method). Covers priority #4 from docs/audit-test-coverage-gaps.md:
 * Voided Shipments row-building/sorting had zero coverage.
 */
class VoidedShipmentsTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderAnomalyPageLoader::class);
        self::$method = $ref->getMethod('buildFailedShipmentRows');
    }

    private function shipment(array $overrides = []): array
    {
        return array_merge([
            'orderNumber'     => '1001',
            'shipmentId'      => 555,
            'trackingNumber'  => '1Z999',
            'carrierCode'     => 'ups',
            'serviceCode'     => 'ups_ground',
            'shipDate'        => '2026-06-01T10:00:00.0000000',
            'voidDate'        => '2026-06-02T10:00:00.0000000',
            'shipTo'          => [
                'name'       => 'Jane Doe',
                'city'       => 'Boston',
                'state'      => 'MA',
                'postalCode' => '02101',
                'country'    => 'US',
            ],
        ], $overrides);
    }

    private function build(array $shipments): array
    {
        return self::$method->invoke(null, $shipments);
    }

    public function testBuildsRowFromShipment(): void
    {
        $rows = $this->build([$this->shipment()]);

        $this->assertCount(1, $rows);
        $this->assertSame('1001', $rows[0]['order_number']);
        $this->assertSame(555, $rows[0]['shipment_id']);
        $this->assertSame('1Z999', $rows[0]['tracking']);
        $this->assertSame('ups', $rows[0]['carrier']);
        $this->assertSame('2026-06-02', $rows[0]['void_date']);
        $this->assertSame('Jane Doe', $rows[0]['ship_to_name']);
        $this->assertSame('Boston', $rows[0]['ship_to_city']);
    }

    public function testMissingShipToAddressDoesNotError(): void
    {
        $rows = $this->build([$this->shipment(['shipTo' => null])]);

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['ship_to_name']);
        $this->assertSame('', $rows[0]['ship_to_city']);
        $this->assertSame('', $rows[0]['ship_to_state']);
        $this->assertSame('', $rows[0]['ship_to_zip']);
        $this->assertSame('', $rows[0]['ship_to_country']);
    }

    public function testAbsentShipToKeyDoesNotError(): void
    {
        $shipment = $this->shipment();
        unset($shipment['shipTo']);

        $rows = $this->build([$shipment]);

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['ship_to_name']);
    }

    public function testRowsSortedByVoidDateDescending(): void
    {
        $rows = $this->build([
            $this->shipment(['orderNumber' => 'A', 'voidDate' => '2026-06-01T00:00:00.0000000']),
            $this->shipment(['orderNumber' => 'B', 'voidDate' => '2026-06-15T00:00:00.0000000']),
            $this->shipment(['orderNumber' => 'C', 'voidDate' => '2026-06-08T00:00:00.0000000']),
        ]);

        $this->assertSame(['B', 'C', 'A'], array_column($rows, 'order_number'));
    }

    public function testEmptyShipmentsReturnsEmptyRows(): void
    {
        $this->assertSame([], $this->build([]));
    }
}
