<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyOrderNormalizer;
use Tests\TestCase;

class ShopifyOrderNormalizerTest extends TestCase
{
    public function test_returns_legacy_compatible_addresses_items_and_fulfillments(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'id' => 'gid://shopify/Order/123456789',
            'legacyResourceId' => '123456789',
            'name' => '#65075',
            'createdAt' => '2026-08-30T10:15:00Z',
            'cancelledAt' => null,
            'email' => 'buyer@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '129.90']],
            'shippingAddress' => [
                'firstName' => 'Ada',
                'lastName' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'company' => 'Analytical Engines',
                'address1' => '1 Computing Lane',
                'address2' => 'Suite 2',
                'city' => 'London',
                'province' => 'England',
                'provinceCode' => 'ENG',
                'country' => 'United Kingdom',
                'countryCodeV2' => 'GB',
                'zip' => 'SW1A 1AA',
                'phone' => '+44123456789',
            ],
            'billingAddress' => null,
            'lineItems' => ['nodes' => [[
                'id' => 'gid://shopify/LineItem/987',
                'title' => 'Blue Widget',
                'name' => 'Blue Widget - Large',
                'sku' => 'WIDGET-BLUE-L',
                'quantity' => 3,
                'variantTitle' => 'Large',
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '39.95']],
            ]]],
            'fulfillments' => [[
                'id' => 'gid://shopify/Fulfillment/456',
                'legacyResourceId' => '456',
                'createdAt' => '2026-08-31T08:00:00Z',
                'status' => 'SUCCESS',
                'displayStatus' => 'DELIVERED',
                'trackingInfo' => [[
                    'company' => 'DHL',
                    'number' => 'TRACK-1',
                    'url' => 'https://tracking.example/TRACK-1',
                ]],
            ]],
        ]);

        $this->assertSame([
            'id' => 123456789,
            'order_number' => 65075,
            'name' => '#65075',
            'created_at' => '2026-08-30T10:15:00Z',
            'cancelled_at' => null,
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'total_price' => '129.90',
            'admin_graphql_api_id' => 'gid://shopify/Order/123456789',
            'shipping_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'company' => 'Analytical Engines',
                'address1' => '1 Computing Lane',
                'address2' => 'Suite 2',
                'city' => 'London',
                'province' => 'England',
                'province_code' => 'ENG',
                'country' => 'United Kingdom',
                'country_code' => 'GB',
                'zip' => 'SW1A 1AA',
                'phone' => '+44123456789',
            ],
            'billing_address' => null,
            'line_items' => [[
                'id' => 987,
                'title' => 'Blue Widget',
                'name' => 'Blue Widget - Large',
                'sku' => 'WIDGET-BLUE-L',
                'quantity' => 3,
                'variant_title' => 'Large',
                'price' => '39.95',
                'admin_graphql_api_id' => 'gid://shopify/LineItem/987',
            ]],
            'fulfillments' => [[
                'id' => 456,
                'admin_graphql_api_id' => 'gid://shopify/Fulfillment/456',
                'created_at' => '2026-08-31T08:00:00Z',
                'status' => 'success',
                'display_status' => 'delivered',
                'shipment_status' => 'delivered',
                'tracking_company' => 'DHL',
                'tracking_number' => 'TRACK-1',
                'tracking_url' => 'https://tracking.example/TRACK-1',
                'tracking_numbers' => ['TRACK-1'],
                'tracking_urls' => ['https://tracking.example/TRACK-1'],
            ]],
        ], $order);
    }

    public function test_preserves_legacy_defaults_for_incomplete_components(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'id' => 'gid://shopify/Order/123',
            'name' => 'CUSTOM-A',
            'shippingAddress' => [],
            'lineItems' => ['nodes' => [[]]],
            'fulfillments' => [[]],
        ]);

        $this->assertSame(123, $order['id']);
        $this->assertSame('CUSTOM-A', $order['order_number']);
        $this->assertSame('', $order['shipping_address']['address1']);
        $this->assertSame('', $order['line_items'][0]['sku']);
        $this->assertSame(0, $order['line_items'][0]['quantity']);
        $this->assertSame('', $order['fulfillments'][0]['status']);
        $this->assertSame([], $order['fulfillments'][0]['tracking_numbers']);
    }
}
