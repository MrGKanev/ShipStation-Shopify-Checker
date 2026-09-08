<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\OrderChannelComparator;
use PHPUnit\Framework\TestCase;

class OrderChannelComparatorTest extends TestCase
{
    public function test_equivalent_values_match_after_conservative_normalization(): void
    {
        $result = $this->comparator()->compare($this->shopifyOrder(), $this->shipStationOrder());
        $states = array_column($result['fields'], 'state', 'key');

        $this->assertSame('match', $states['customer_email']);
        $this->assertSame('match', $states['total']);
        $this->assertSame('match', $states['shipping_name']);
        $this->assertSame('match', $states['shipping_street_1']);
        $this->assertSame('review', $states['fulfillment_status']);
        $this->assertSame('match', $result['items']['state']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_item_comparison_aggregates_duplicate_skus_and_reports_quantity_differences(): void
    {
        $shopify = $this->shopifyOrder(['line_items' => [
            ['sku' => ' WIDGET ', 'quantity' => 1],
            ['sku' => 'widget', 'quantity' => 2],
            ['sku' => 'cable', 'quantity' => 2],
            ['sku' => '', 'quantity' => 99],
        ]]);
        $shipStation = $this->shipStationOrder(['items' => [
            ['sku' => 'widget', 'quantity' => 2],
            ['sku' => 'EXTRA', 'quantity' => 4],
            ['sku' => null, 'quantity' => 99],
        ]]);

        $items = $this->comparator()->compare($shopify, $shipStation)['items'];

        $this->assertSame(['cable' => 2, 'widget' => 3], $items['shopify']);
        $this->assertSame(['extra' => 4, 'widget' => 2], $items['shipstation']);
        $this->assertSame(['cable' => 2, 'widget' => 1], $items['missing']);
        $this->assertSame(['extra' => 4], $items['extra']);
        $this->assertSame('different', $items['state']);
    }

    public function test_real_address_difference_and_missing_value_are_distinguished(): void
    {
        $shipStation = $this->shipStationOrder();
        $shipStation['shipping_address']['street_1'] = '999 Other Street';
        $shipStation['shipping_address']['phone'] = null;

        $result = $this->comparator()->compare($this->shopifyOrder(), $shipStation);
        $states = array_column($result['fields'], 'state', 'key');

        $this->assertSame('different', $states['shipping_street_1']);
        $this->assertSame('missing', $states['shipping_phone']);
    }

    public function test_established_status_inconsistencies_produce_danger_warnings(): void
    {
        $shippedResult = $this->comparator()->compare(
            $this->shopifyOrder(['fulfillment_status' => null]),
            $this->shipStationOrder(['status' => 'shipped']),
        );
        $refundedResult = $this->comparator()->compare(
            $this->shopifyOrder(['financial_status' => 'partially_refunded']),
            $this->shipStationOrder(['status' => 'awaiting_shipment']),
        );

        $this->assertSame('shipstation_shipped_shopify_unfulfilled', $shippedResult['warnings'][0]['code']);
        $this->assertSame('shopify_refunded_shipstation_active', $refundedResult['warnings'][0]['code']);
    }

    /** @param array<string, mixed> $overrides */
    private function shopifyOrder(array $overrides = []): array
    {
        return [
            'email' => ' Buyer@Example.com ',
            'total_price' => '129.9',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'shipping_address' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'company' => '',
                'address1' => '123   Main Street', 'address2' => null, 'city' => 'Sofia',
                'province_code' => 'SOF', 'zip' => '1000', 'country_code' => 'BG',
                'phone' => '+359 2 123 456',
            ],
            'line_items' => [
                ['sku' => 'Widget', 'quantity' => 2],
                ['sku' => 'Cable', 'quantity' => 1],
            ],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function shipStationOrder(array $overrides = []): array
    {
        return [
            'customer_email' => 'buyer@example.com',
            'total' => '129.90',
            'status' => 'shipped',
            'shipping_address' => [
                'name' => 'jane doe', 'company' => null, 'street_1' => '123 Main Street',
                'street_2' => '', 'city' => 'SOFIA', 'state' => 'sof', 'postal_code' => '1000',
                'country' => 'bg', 'phone' => '+359 2 123 456',
            ],
            'items' => [
                ['sku' => 'widget', 'quantity' => 2],
                ['sku' => 'cable', 'quantity' => 1],
            ],
            ...$overrides,
        ];
    }

    private function comparator(): OrderChannelComparator
    {
        return new OrderChannelComparator;
    }
}
