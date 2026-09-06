<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\TrackingFeedBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrackingFeedBuilderTest extends TestCase
{
    public function test_missing_order_is_not_found(): void
    {
        $this->assertSame(['number' => '1001', 'found' => false, 'shipments' => []], (new TrackingFeedBuilder)->build('1001', [], []));
    }

    public function test_unshipped_order_retains_status_and_safe_order_link(): void
    {
        $result = (new TrackingFeedBuilder)->build('1001', [['orderId' => 42, 'orderStatus' => 'awaiting_shipment']], []);
        $this->assertTrue($result['found']);
        $this->assertSame('awaiting_shipment', $result['shipments'][0]['orderStatus']);
        $this->assertNull($result['shipments'][0]['trackingUrl']);
        $this->assertSame('https://app.shipstation.com/#!/orders/order-details/42', $result['shipments'][0]['ssUrl']);
    }

    #[DataProvider('carriers')]
    public function test_builds_allowlisted_carrier_urls(string $carrier, string $expected): void
    {
        $result = (new TrackingFeedBuilder)->build('1001', [['orderId' => 42]], [['carrierCode' => $carrier, 'trackingNumber' => 'ABC 1']]);
        $this->assertSame($expected.urlencode('ABC 1'), $result['shipments'][0]['trackingUrl']);
    }

    public static function carriers(): array
    {
        return [
            ['USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels='], ['stamps_com', 'https://tools.usps.com/go/TrackConfirmAction?tLabels='],
            ['fedex', 'https://www.fedex.com/fedextrack/?tracknumbers='], ['ups', 'https://www.ups.com/track?tracknum='],
            ['dhl', 'https://www.dhl.com/en/express/tracking.html?AWB='], ['ontrac', 'https://www.ontrac.com/tracking/?number='],
            ['lasership', 'https://www.lasership.com/track/'],
        ];
    }

    public function test_unknown_carrier_malformed_values_and_unsafe_order_id_never_create_links(): void
    {
        $result = (new TrackingFeedBuilder)->build('1001', [['orderId' => 'javascript:alert(1)']], [['carrierCode' => ['bad'], 'trackingNumber' => ['bad'], 'shipDate' => ['bad']]]);
        $this->assertNull($result['shipments'][0]['trackingUrl']);
        $this->assertNull($result['shipments'][0]['ssUrl']);
        $this->assertSame('', $result['shipments'][0]['shipDate']);
    }
}
