<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/Shopify/GraphQL/Ids.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/OrderComponentNormalizer.php';

use PHPUnit\Framework\TestCase;

class OrderComponentNormalizerTest extends TestCase
{
    // ── orderNumberFromName ───────────────────────────────────────────────────

    public function testOrderNumberFromNameStripsHash(): void
    {
        $result = \Shopify\GraphQL\OrderComponentNormalizer::orderNumberFromName('#1234');
        $this->assertSame(1234, $result);
        $this->assertIsInt($result);
    }

    public function testOrderNumberFromNamePlainNumeric(): void
    {
        $result = \Shopify\GraphQL\OrderComponentNormalizer::orderNumberFromName('1234');
        $this->assertSame(1234, $result);
        $this->assertIsInt($result);
    }

    public function testOrderNumberFromNameNonNumericReturnsString(): void
    {
        $result = \Shopify\GraphQL\OrderComponentNormalizer::orderNumberFromName('CUSTOM-1');
        $this->assertSame('CUSTOM-1', $result);
        $this->assertIsString($result);
    }

    public function testOrderNumberFromNameTrimsWhitespace(): void
    {
        $result = \Shopify\GraphQL\OrderComponentNormalizer::orderNumberFromName('  #5678  ');
        $this->assertSame(5678, $result);
    }

    public function testOrderNumberFromNameHashWithNonNumericBody(): void
    {
        $result = \Shopify\GraphQL\OrderComponentNormalizer::orderNumberFromName('#CUSTOM-99');
        $this->assertSame('CUSTOM-99', $result);
        $this->assertIsString($result);
    }

    // ── normalizeAddress ──────────────────────────────────────────────────────

    public function testNormalizeAddressNullReturnsNull(): void
    {
        $this->assertNull(\Shopify\GraphQL\OrderComponentNormalizer::normalizeAddress(null));
    }

    public function testNormalizeAddressFullNode(): void
    {
        $address = [
            'firstName'    => 'Jane',
            'lastName'     => 'Doe',
            'name'         => 'Jane Doe',
            'company'      => 'Acme Corp',
            'address1'     => '123 Main St',
            'address2'     => 'Apt 4B',
            'city'         => 'Springfield',
            'province'     => 'Illinois',
            'provinceCode' => 'IL',
            'country'      => 'United States',
            'countryCodeV2' => 'US',
            'zip'          => '62701',
            'phone'        => '555-1234',
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeAddress($address);

        $this->assertIsArray($result);
        $this->assertSame('Jane', $result['first_name']);
        $this->assertSame('Doe', $result['last_name']);
        $this->assertSame('Jane Doe', $result['name']);
        $this->assertSame('Acme Corp', $result['company']);
        $this->assertSame('123 Main St', $result['address1']);
        $this->assertSame('Apt 4B', $result['address2']);
        $this->assertSame('Springfield', $result['city']);
        $this->assertSame('Illinois', $result['province']);
        $this->assertSame('IL', $result['province_code']);
        $this->assertSame('United States', $result['country']);
        $this->assertSame('US', $result['country_code']);
        $this->assertSame('62701', $result['zip']);
        $this->assertSame('555-1234', $result['phone']);
    }

    public function testNormalizeAddressEmptyArrayUsesDefaults(): void
    {
        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeAddress([]);

        $this->assertIsArray($result);
        $this->assertSame('', $result['first_name']);
        $this->assertSame('', $result['last_name']);
        $this->assertNull($result['company']);
        $this->assertSame('', $result['address1']);
        $this->assertSame('', $result['city']);
        $this->assertSame('', $result['country_code']);
    }

    public function testNormalizeAddressUsesSnakeCaseKeys(): void
    {
        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeAddress(['firstName' => 'Bob']);

        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayNotHasKey('firstName', $result);
    }

    // ── normalizeLineItem ─────────────────────────────────────────────────────

    public function testNormalizeLineItemBasicNode(): void
    {
        $lineItem = [
            'id'            => 'gid://shopify/LineItem/111',
            'title'         => 'Awesome Widget',
            'name'          => 'Awesome Widget - Red',
            'sku'           => 'AWG-RED',
            'quantity'      => 3,
            'variantTitle'  => 'Red',
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '29.99']],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeLineItem($lineItem);

        $this->assertSame(111, $result['id']);
        $this->assertSame('Awesome Widget', $result['title']);
        $this->assertSame('Awesome Widget - Red', $result['name']);
        $this->assertSame('AWG-RED', $result['sku']);
        $this->assertSame(3, $result['quantity']);
        $this->assertSame('Red', $result['variant_title']);
        $this->assertSame('29.99', $result['price']);
        $this->assertSame('gid://shopify/LineItem/111', $result['admin_graphql_api_id']);
    }

    public function testNormalizeLineItemWithUnfulfilledQuantity(): void
    {
        $lineItem = [
            'id'                  => 'gid://shopify/LineItem/222',
            'title'               => 'Widget',
            'quantity'            => 5,
            'unfulfilledQuantity' => 2,
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeLineItem($lineItem);

        $this->assertArrayHasKey('fulfillable_quantity', $result);
        $this->assertSame(2, $result['fulfillable_quantity']);
    }

    public function testNormalizeLineItemWithoutUnfulfilledQuantityOmitsKey(): void
    {
        $lineItem = [
            'id'       => 'gid://shopify/LineItem/333',
            'title'    => 'Widget',
            'quantity' => 1,
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '5.00']],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeLineItem($lineItem);

        $this->assertArrayNotHasKey('fulfillable_quantity', $result);
    }

    public function testNormalizeLineItemFallsBackTitleToName(): void
    {
        $lineItem = [
            'id'       => 'gid://shopify/LineItem/444',
            'name'     => 'Only Name',
            'quantity' => 1,
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '1.00']],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeLineItem($lineItem);

        $this->assertSame('Only Name', $result['title']);
        $this->assertSame('Only Name', $result['name']);
    }

    public function testNormalizeLineItemDefaultsToZeroPriceWhenMissing(): void
    {
        $lineItem = [
            'id'       => 'gid://shopify/LineItem/555',
            'title'    => 'Free Item',
            'quantity' => 1,
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeLineItem($lineItem);

        $this->assertSame('0.00', $result['price']);
    }

    // ── normalizeShippingLine ─────────────────────────────────────────────────

    public function testNormalizeShippingLineBasicNode(): void
    {
        $shippingLine = [
            'id'    => 'gid://shopify/ShippingLine/77',
            'title' => 'Standard Shipping',
            'code'  => 'STANDARD',
            'originalPriceSet' => ['shopMoney' => ['amount' => '5.99']],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeShippingLine($shippingLine);

        $this->assertSame(77, $result['id']);
        $this->assertSame('Standard Shipping', $result['title']);
        $this->assertSame('STANDARD', $result['code']);
        $this->assertSame('5.99', $result['price']);
        $this->assertSame('gid://shopify/ShippingLine/77', $result['admin_graphql_api_id']);
    }

    public function testNormalizeShippingLineDefaultsToZeroPriceWhenMissing(): void
    {
        $shippingLine = [
            'id'    => 'gid://shopify/ShippingLine/88',
            'title' => 'Free Shipping',
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeShippingLine($shippingLine);

        $this->assertSame('0.00', $result['price']);
    }

    // ── normalizeFulfillment ──────────────────────────────────────────────────

    public function testNormalizeFulfillmentWithTrackingInfo(): void
    {
        $fulfillment = [
            'id'                => 'gid://shopify/Fulfillment/500',
            'legacyResourceId'  => '500',
            'createdAt'         => '2024-03-01T10:00:00Z',
            'status'            => 'SUCCESS',
            'displayStatus'     => 'DELIVERED',
            'trackingInfo'      => [
                ['company' => 'UPS', 'number' => '1Z999AA10123456784', 'url' => 'https://ups.com/track?num=1Z999AA10123456784'],
                ['company' => 'UPS', 'number' => '1Z999AA10123456785', 'url' => 'https://ups.com/track?num=1Z999AA10123456785'],
            ],
            'fulfillmentLineItems' => ['edges' => []],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeFulfillment($fulfillment);

        $this->assertSame(500, $result['id']);
        $this->assertSame('gid://shopify/Fulfillment/500', $result['admin_graphql_api_id']);
        $this->assertSame('success', $result['status']);
        $this->assertSame('delivered', $result['display_status']);
        $this->assertSame('UPS', $result['tracking_company']);
        $this->assertSame('1Z999AA10123456784', $result['tracking_number']);
        $this->assertCount(2, $result['tracking_numbers']);
        $this->assertCount(2, $result['tracking_urls']);
        $this->assertSame([], $result['line_items']);
    }

    public function testNormalizeFulfillmentWithLineItemsViaEdges(): void
    {
        $fulfillment = [
            'id'               => 'gid://shopify/Fulfillment/600',
            'legacyResourceId' => '600',
            'status'           => 'SUCCESS',
            'displayStatus'    => 'FULFILLED',
            'trackingInfo'     => [],
            'fulfillmentLineItems' => [
                'edges' => [
                    [
                        'node' => [
                            'quantity' => 2,
                            'lineItem' => [
                                'id'       => 'gid://shopify/LineItem/100',
                                'title'    => 'Gadget',
                                'quantity' => 5,
                                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '19.99']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeFulfillment($fulfillment);

        $this->assertCount(1, $result['line_items']);
        $this->assertSame(2, $result['line_items'][0]['quantity']);
        $this->assertSame('Gadget', $result['line_items'][0]['title']);
    }

    public function testNormalizeFulfillmentNoTracking(): void
    {
        $fulfillment = [
            'id'               => 'gid://shopify/Fulfillment/700',
            'legacyResourceId' => '700',
            'status'           => 'PENDING',
            'displayStatus'    => 'IN_TRANSIT',
            'trackingInfo'     => [],
            'fulfillmentLineItems' => ['edges' => []],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeFulfillment($fulfillment);

        $this->assertSame('', $result['tracking_company']);
        $this->assertSame('', $result['tracking_number']);
        $this->assertSame('', $result['tracking_url']);
        $this->assertSame([], $result['tracking_numbers']);
        $this->assertSame([], $result['tracking_urls']);
    }

    // ── normalizeRefund ────────────────────────────────────────────────────────

    public function testNormalizeRefundWithLineItemsAndTransactions(): void
    {
        $refund = [
            'id'               => 'gid://shopify/Refund/800',
            'legacyResourceId' => '800',
            'createdAt'        => '2024-04-01T12:00:00Z',
            'note'             => 'Customer request',
            'totalRefundedSet' => ['shopMoney' => ['amount' => '29.99']],
            'refundLineItems'  => [
                'nodes' => [
                    [
                        'quantity'   => 1,
                        'subtotalSet' => ['shopMoney' => ['amount' => '29.99']],
                        'lineItem'   => [
                            'id'       => 'gid://shopify/LineItem/201',
                            'title'    => 'Refunded Item',
                            'quantity' => 1,
                            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '29.99']],
                        ],
                    ],
                ],
            ],
            'transactions' => [
                'nodes' => [
                    [
                        'id'        => 'gid://shopify/OrderTransaction/901',
                        'kind'      => 'REFUND',
                        'status'    => 'SUCCESS',
                        'amountSet' => ['shopMoney' => ['amount' => '29.99']],
                    ],
                ],
            ],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeRefund($refund);

        $this->assertSame(800, $result['id']);
        $this->assertSame('gid://shopify/Refund/800', $result['admin_graphql_api_id']);
        $this->assertSame('2024-04-01T12:00:00Z', $result['created_at']);
        $this->assertSame('Customer request', $result['note']);
        $this->assertSame('29.99', $result['total_refunded']);

        $this->assertCount(1, $result['refund_line_items']);
        $this->assertSame(1, $result['refund_line_items'][0]['quantity']);
        $this->assertSame('29.99', $result['refund_line_items'][0]['subtotal']);
        $this->assertSame(201, $result['refund_line_items'][0]['line_item_id']);

        $this->assertCount(1, $result['transactions']);
        $this->assertSame(901, $result['transactions'][0]['id']);
        $this->assertSame('refund', $result['transactions'][0]['kind']);
        $this->assertSame('success', $result['transactions'][0]['status']);
        $this->assertSame('29.99', $result['transactions'][0]['amount']);
    }

    public function testNormalizeRefundEmpty(): void
    {
        $refund = [
            'id'               => 'gid://shopify/Refund/801',
            'legacyResourceId' => '801',
            'refundLineItems'  => ['nodes' => []],
            'transactions'     => ['nodes' => []],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeRefund($refund);

        $this->assertSame([], $result['refund_line_items']);
        $this->assertSame([], $result['transactions']);
        $this->assertSame('0.00', $result['total_refunded']);
    }

    // ── normalizeDiscountCode ─────────────────────────────────────────────────

    public function testNormalizeDiscountCodeNonDiscountCodeApplicationTypenameReturnsNull(): void
    {
        $discount = [
            '__typename' => 'AutomaticDiscountApplication',
            'code'       => 'SAVE10',
        ];

        $this->assertNull(\Shopify\GraphQL\OrderComponentNormalizer::normalizeDiscountCode($discount));
    }

    public function testNormalizeDiscountCodeEmptyCodeReturnsNull(): void
    {
        $discount = [
            '__typename' => 'DiscountCodeApplication',
            'code'       => '   ',
        ];

        $this->assertNull(\Shopify\GraphQL\OrderComponentNormalizer::normalizeDiscountCode($discount));
    }

    public function testNormalizeDiscountCodeMissingCodeReturnsNull(): void
    {
        $discount = [
            '__typename' => 'DiscountCodeApplication',
        ];

        $this->assertNull(\Shopify\GraphQL\OrderComponentNormalizer::normalizeDiscountCode($discount));
    }

    public function testNormalizeDiscountCodeFixedAmount(): void
    {
        $discount = [
            '__typename'       => 'DiscountCodeApplication',
            'code'             => 'SAVE5',
            'allocationMethod' => 'ACROSS',
            'targetSelection'  => 'ALL',
            'targetType'       => 'LINE_ITEM',
            'value'            => [
                '__typename' => 'MoneyV2',
                'amount'     => '5.00',
            ],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeDiscountCode($discount);

        $this->assertNotNull($result);
        $this->assertSame('SAVE5', $result['code']);
        $this->assertSame('fixed_amount', $result['type']);
        $this->assertSame('5.00', $result['amount']);
        $this->assertSame('across', $result['allocation_method']);
        $this->assertSame('all', $result['target_selection']);
        $this->assertSame('line_item', $result['target_type']);
    }

    public function testNormalizeDiscountCodePercentage(): void
    {
        $discount = [
            '__typename'       => 'DiscountCodeApplication',
            'code'             => 'PCT20',
            'allocationMethod' => 'EACH',
            'targetSelection'  => 'ALL',
            'targetType'       => 'LINE_ITEM',
            'value'            => [
                '__typename' => 'PricingPercentageValue',
                'percentage' => 20.0,
            ],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeDiscountCode($discount);

        $this->assertNotNull($result);
        $this->assertSame('PCT20', $result['code']);
        $this->assertSame('percentage', $result['type']);
        $this->assertSame('20', $result['amount']);
    }

    public function testNormalizeDiscountCodeUnknownValueTypeUsesLowercasedTypename(): void
    {
        $discount = [
            '__typename' => 'DiscountCodeApplication',
            'code'       => 'MYSTERY',
            'value'      => [
                '__typename' => 'SomeOtherValue',
            ],
        ];

        $result = \Shopify\GraphQL\OrderComponentNormalizer::normalizeDiscountCode($discount);

        $this->assertNotNull($result);
        $this->assertSame('someothervalue', $result['type']);
    }
}
