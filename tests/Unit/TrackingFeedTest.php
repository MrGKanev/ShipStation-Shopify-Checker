<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/SearchLookupPageLoader.php';

/**
 * Tests for SearchLookupPageLoader::buildTrackingResult() via reflection
 * (private method). Previously the carrier-URL lookup and shipment shaping
 * lived inline inside loadTracking(), coupled to a live ShipStation call,
 * with zero coverage beyond the wrapper's input-validation error paths.
 */
class TrackingFeedTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(SearchLookupPageLoader::class);
        self::$method = $ref->getMethod('buildTrackingResult');
    }

    private function build(string $clean, array $ssOrders): array
    {
        return self::$method->invoke(null, $clean, $ssOrders);
    }

    public function testNoMatchingOrderIsNotFound(): void
    {
        $result = $this->build('1001', []);

        $this->assertFalse($result['found']);
        $this->assertSame([], $result['shipments']);
    }

    public function testKnownCarrierBuildsTrackingUrl(): void
    {
        $result = $this->build('1001', [[
            'orderId' => '555', 'carrierCode' => 'ups', 'trackingNumber' => '1Z999', 'orderStatus' => 'shipped',
        ]]);

        $this->assertTrue($result['found']);
        $this->assertSame('https://www.ups.com/track?tracknum=1Z999', $result['shipments'][0]['trackingUrl']);
        $this->assertStringContainsString('555', $result['shipments'][0]['ssUrl']);
    }

    public function testUnknownCarrierYieldsNullTrackingUrl(): void
    {
        $result = $this->build('1001', [[
            'orderId' => '1', 'carrierCode' => 'some_unlisted_carrier', 'trackingNumber' => 'ABC123',
        ]]);

        $this->assertNull($result['shipments'][0]['trackingUrl']);
    }

    public function testMissingTrackingNumberYieldsNullTrackingUrlEvenForKnownCarrier(): void
    {
        $result = $this->build('1001', [[
            'orderId' => '1', 'carrierCode' => 'fedex', 'trackingNumber' => '',
        ]]);

        $this->assertNull($result['shipments'][0]['trackingUrl']);
    }

    public function testMissingOrderIdYieldsNullSsUrl(): void
    {
        $result = $this->build('1001', [['orderId' => '', 'carrierCode' => 'ups', 'trackingNumber' => 'X']]);

        $this->assertNull($result['shipments'][0]['ssUrl']);
    }

    public function testTrackingNumberIsUrlEncoded(): void
    {
        $result = $this->build('1001', [['orderId' => '1', 'carrierCode' => 'usps', 'trackingNumber' => 'ABC 123/DEF']]);

        $this->assertStringContainsString(urlencode('ABC 123/DEF'), $result['shipments'][0]['trackingUrl']);
    }

    public function testMultipleShipmentsForOneOrderNumberAreAllIncluded(): void
    {
        $result = $this->build('1001', [
            ['orderId' => '1', 'carrierCode' => 'ups', 'trackingNumber' => 'A'],
            ['orderId' => '2', 'carrierCode' => 'fedex', 'trackingNumber' => 'B'],
        ]);

        $this->assertCount(2, $result['shipments']);
    }
}
