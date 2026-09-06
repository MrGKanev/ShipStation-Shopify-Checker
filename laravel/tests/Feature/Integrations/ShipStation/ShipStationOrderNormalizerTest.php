<?php

namespace Tests\Feature\Integrations\ShipStation;

use App\Integrations\ShipStation\ShipStationOrderNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class ShipStationOrderNormalizerTest extends TestCase
{
    public function test_normalizes_an_order_and_its_fulfillment_data_into_a_strict_shape(): void
    {
        $order = [
            'orderId' => '501',
            'orderNumber' => 65075,
            'orderStatus' => 'Awaiting Shipment',
            'createDate' => '2026-09-01T10:15:00Z',
            'customerEmail' => ' buyer@example.com ',
            'orderTotal' => '129.9',
            'shipTo' => [
                'name' => ' Jane Doe ',
                'company' => 'Acme',
                'street1' => '1 Main St',
                'street2' => 'Suite 2',
                'city' => 'Sofia',
                'state' => 'Sofia City',
                'postalCode' => '1000',
                'country' => 'BG',
                'phone' => '+359111111',
            ],
            'billTo' => [
                'name' => 'Jane Doe',
                'city' => 'Plovdiv',
                'country' => 'BG',
            ],
            'items' => [
                [
                    'orderItemId' => '701',
                    'lineItemKey' => 901,
                    'sku' => ' Widget-1 ',
                    'name' => 'Widget',
                    'quantity' => '2',
                ],
            ],
        ];
        $shipments = [
            [
                'shipmentId' => '801',
                'orderId' => 501,
                'carrierCode' => 'ups',
                'serviceCode' => 'ups_ground',
                'trackingNumber' => ' 1Z999 ',
                'shipDate' => '2026-09-02',
                'deliveryDate' => '2026-09-04',
                'voided' => false,
            ],
        ];

        $normalized = $this->normalizer()->normalize($order, $shipments);

        $this->assertSame([
            'id' => 501,
            'order_number' => '65075',
            'status' => 'awaiting_shipment',
            'created_at' => '2026-09-01T10:15:00Z',
            'customer_email' => 'buyer@example.com',
            'total' => '129.90',
            'shipping_address' => [
                'name' => 'Jane Doe',
                'company' => 'Acme',
                'street_1' => '1 Main St',
                'street_2' => 'Suite 2',
                'city' => 'Sofia',
                'state' => 'Sofia City',
                'postal_code' => '1000',
                'country' => 'BG',
                'phone' => '+359111111',
            ],
            'billing_address' => [
                'name' => 'Jane Doe',
                'company' => null,
                'street_1' => null,
                'street_2' => null,
                'city' => 'Plovdiv',
                'state' => null,
                'postal_code' => null,
                'country' => 'BG',
                'phone' => null,
            ],
            'items' => [
                [
                    'id' => 701,
                    'line_item_key' => '901',
                    'sku' => 'Widget-1',
                    'name' => 'Widget',
                    'quantity' => 2,
                ],
            ],
            'sku_quantities' => ['widget-1' => 2],
            'shipments' => [
                [
                    'id' => 801,
                    'order_id' => 501,
                    'carrier_code' => 'ups',
                    'service_code' => 'ups_ground',
                    'tracking_number' => '1Z999',
                    'ship_date' => '2026-09-02',
                    'delivery_date' => '2026-09-04',
                    'voided' => false,
                ],
            ],
            'fulfillment' => [
                'is_shipped' => false,
                'shipment_count' => 1,
                'active_shipment_count' => 1,
                'tracking_numbers' => ['1Z999'],
            ],
        ], $normalized);
    }

    public function test_partial_order_preserves_the_legacy_default_item_quantity(): void
    {
        $normalized = $this->normalizer()->normalize([
            'orderNumber' => '1001',
            'orderDate' => '2026-09-01',
            'items' => [
                ['name' => 'Unknown item'],
            ],
        ]);

        $this->assertNull($normalized['id']);
        $this->assertSame('1001', $normalized['order_number']);
        $this->assertSame('unknown', $normalized['status']);
        $this->assertSame('2026-09-01', $normalized['created_at']);
        $this->assertNull($normalized['customer_email']);
        $this->assertNull($normalized['total']);
        $this->assertSame([], $normalized['sku_quantities']);
        $this->assertSame([
            'id' => null,
            'line_item_key' => null,
            'sku' => null,
            'name' => 'Unknown item',
            'quantity' => 1,
        ], $normalized['items'][0]);
    }

    public function test_empty_and_whitespace_fields_normalize_to_empty_or_null_values(): void
    {
        $normalized = $this->normalizer()->normalize([
            'orderId' => '',
            'orderNumber' => '   ',
            'orderStatus' => '',
            'customerEmail' => ' ',
            'shipTo' => [],
            'billTo' => null,
            'items' => null,
        ], []);

        $this->assertSame('', $normalized['order_number']);
        $this->assertSame('unknown', $normalized['status']);
        $this->assertNull($normalized['customer_email']);
        $this->assertSame([], $normalized['items']);
        $this->assertSame([], $normalized['shipments']);
        $this->assertSame([
            'is_shipped' => false,
            'shipment_count' => 0,
            'active_shipment_count' => 0,
            'tracking_numbers' => [],
        ], $normalized['fulfillment']);
        $this->assertSame(array_fill_keys([
            'name',
            'company',
            'street_1',
            'street_2',
            'city',
            'state',
            'postal_code',
            'country',
            'phone',
        ], null), $normalized['shipping_address']);
    }

    public function test_duplicate_skus_and_shipments_are_normalized_deterministically(): void
    {
        $shipment = [
            'shipmentId' => 801,
            'trackingNumber' => 'TRACK-1',
            'voided' => false,
        ];

        $normalized = $this->normalizer()->normalize([
            'orderStatus' => 'SHIPPED',
            'items' => [
                ['sku' => 'ABC', 'quantity' => 1],
                ['sku' => ' abc ', 'quantity' => '2'],
                ['sku' => 'XYZ', 'quantity' => 4],
            ],
        ], [
            $shipment,
            $shipment,
            ['shipmentId' => 802, 'trackingNumber' => 'TRACK-1', 'voided' => false],
            ['shipmentId' => 803, 'trackingNumber' => 'VOIDED', 'voided' => true],
        ]);

        $this->assertSame(['abc' => 3, 'xyz' => 4], $normalized['sku_quantities']);
        $this->assertCount(3, $normalized['shipments']);
        $this->assertSame([
            'is_shipped' => true,
            'shipment_count' => 3,
            'active_shipment_count' => 2,
            'tracking_numbers' => ['TRACK-1'],
        ], $normalized['fulfillment']);
    }

    public function test_conflicting_duplicate_shipment_ids_are_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('ShipStation returned conflicting shipment records for ID 801.');

        $this->normalizer()->normalize([], [
            ['shipmentId' => 801, 'trackingNumber' => 'TRACK-1'],
            ['shipmentId' => 801, 'trackingNumber' => 'TRACK-2'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    #[DataProvider('malformedOrderProvider')]
    public function test_malformed_order_fields_are_rejected(array $order, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $this->normalizer()->normalize($order);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedOrderProvider(): array
    {
        return [
            'shipping address is scalar' => [
                ['shipTo' => 'invalid'],
                'ShipStation returned an invalid shipTo address.',
            ],
            'billing address is scalar' => [
                ['billTo' => false],
                'ShipStation returned an invalid billTo address.',
            ],
            'items is not a list' => [
                ['items' => ['sku' => 'ABC']],
                'ShipStation returned an invalid items collection.',
            ],
            'item is not an object' => [
                ['items' => ['invalid']],
                'ShipStation returned an invalid item at index 0.',
            ],
            'quantity is fractional' => [
                ['items' => [['quantity' => '1.5']]],
                'ShipStation returned an invalid item quantity at index 0.',
            ],
            'status is structured data' => [
                ['orderStatus' => ['shipped']],
                'ShipStation returned an invalid orderStatus value.',
            ],
            'total is not numeric' => [
                ['orderTotal' => 'EUR 10'],
                'ShipStation returned an invalid orderTotal value.',
            ],
        ];
    }

    /**
     * @param  array<mixed>  $shipments
     */
    #[DataProvider('malformedShipmentProvider')]
    public function test_malformed_shipment_fields_are_rejected(array $shipments, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $this->normalizer()->normalize([], $shipments);
    }

    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function malformedShipmentProvider(): array
    {
        return [
            'shipments is not a list' => [
                ['shipment' => []],
                'ShipStation returned an invalid shipments collection.',
            ],
            'shipment is not an object' => [
                ['invalid'],
                'ShipStation returned an invalid shipment at index 0.',
            ],
            'voided has an unknown value' => [
                [['voided' => 'yes']],
                'ShipStation returned an invalid shipments.0.voided value.',
            ],
            'shipment id is boolean' => [
                [['shipmentId' => true]],
                'ShipStation returned an invalid shipments.0.shipmentId value.',
            ],
        ];
    }

    private function normalizer(): ShipStationOrderNormalizer
    {
        return new ShipStationOrderNormalizer;
    }
}
