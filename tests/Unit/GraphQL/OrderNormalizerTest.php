<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/Shopify/GraphQL/Ids.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/OrderComponentNormalizer.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/OrderNormalizer.php';

use PHPUnit\Framework\TestCase;

class OrderNormalizerTest extends TestCase
{
    // ── normalizeFinancialStatus ──────────────────────────────────────────────

    public function testNormalizeFinancialStatusPaid(): void
    {
        $this->assertSame('paid', \Shopify\GraphQL\OrderNormalizer::normalizeFinancialStatus('PAID'));
    }

    public function testNormalizeFinancialStatusPartiallyPaid(): void
    {
        $this->assertSame('partially_paid', \Shopify\GraphQL\OrderNormalizer::normalizeFinancialStatus('PARTIALLY_PAID'));
    }

    public function testNormalizeFinancialStatusNull(): void
    {
        $this->assertSame('', \Shopify\GraphQL\OrderNormalizer::normalizeFinancialStatus(null));
    }

    public function testNormalizeFinancialStatusEmptyString(): void
    {
        $this->assertSame('', \Shopify\GraphQL\OrderNormalizer::normalizeFinancialStatus(''));
    }

    public function testNormalizeFinancialStatusRefunded(): void
    {
        $this->assertSame('refunded', \Shopify\GraphQL\OrderNormalizer::normalizeFinancialStatus('REFUNDED'));
    }

    // ── normalizeFulfillmentStatus ────────────────────────────────────────────

    public function testNormalizeFulfillmentStatusEmptyStringReturnsNull(): void
    {
        $this->assertNull(\Shopify\GraphQL\OrderNormalizer::normalizeFulfillmentStatus(''));
    }

    public function testNormalizeFulfillmentStatusNullReturnsNull(): void
    {
        $this->assertNull(\Shopify\GraphQL\OrderNormalizer::normalizeFulfillmentStatus(null));
    }

    public function testNormalizeFulfillmentStatusUnfulfilledReturnsNull(): void
    {
        $this->assertNull(\Shopify\GraphQL\OrderNormalizer::normalizeFulfillmentStatus('UNFULFILLED'));
    }

    public function testNormalizeFulfillmentStatusPartiallyFulfilledReturnsPartial(): void
    {
        $this->assertSame('partial', \Shopify\GraphQL\OrderNormalizer::normalizeFulfillmentStatus('PARTIALLY_FULFILLED'));
    }

    public function testNormalizeFulfillmentStatusFulfilledReturnsFulfilled(): void
    {
        $this->assertSame('fulfilled', \Shopify\GraphQL\OrderNormalizer::normalizeFulfillmentStatus('FULFILLED'));
    }

    public function testNormalizeFulfillmentStatusInTransitReturnsLowercase(): void
    {
        $this->assertSame('in_transit', \Shopify\GraphQL\OrderNormalizer::normalizeFulfillmentStatus('IN_TRANSIT'));
    }

    // ── normalizeOrder ─────────────────────────────────────────────────────────

    private function makeMinimalNode(array $overrides = []): array
    {
        return array_merge([
            'id'                        => 'gid://shopify/Order/10001',
            'legacyResourceId'          => '10001',
            'name'                      => '#1001',
            'createdAt'                 => '2024-01-15T08:00:00Z',
            'cancelledAt'               => null,
            'email'                     => 'buyer@example.com',
            'displayFinancialStatus'    => 'PAID',
            'displayFulfillmentStatus'  => 'UNFULFILLED',
            'totalPriceSet'             => ['shopMoney' => ['amount' => '99.00']],
        ], $overrides);
    }

    public function testNormalizeOrderMinimalNode(): void
    {
        $node   = $this->makeMinimalNode();
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame(10001, $result['id']);
        $this->assertSame(1001, $result['order_number']);
        $this->assertSame('#1001', $result['name']);
        $this->assertSame('2024-01-15T08:00:00Z', $result['created_at']);
        $this->assertNull($result['cancelled_at']);
        $this->assertSame('buyer@example.com', $result['email']);
        $this->assertSame('paid', $result['financial_status']);
        $this->assertNull($result['fulfillment_status']);
        $this->assertSame('99.00', $result['total_price']);
        $this->assertSame('gid://shopify/Order/10001', $result['admin_graphql_api_id']);
    }

    public function testNormalizeOrderOmitsOptionalFieldsWhenAbsent(): void
    {
        $node   = $this->makeMinimalNode();
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayNotHasKey('tags', $result);
        $this->assertArrayNotHasKey('note', $result);
        $this->assertArrayNotHasKey('shipping_address', $result);
        $this->assertArrayNotHasKey('billing_address', $result);
        $this->assertArrayNotHasKey('line_items', $result);
        $this->assertArrayNotHasKey('shipping_lines', $result);
        $this->assertArrayNotHasKey('fulfillments', $result);
        $this->assertArrayNotHasKey('refunds', $result);
        $this->assertArrayNotHasKey('discount_codes', $result);
        $this->assertArrayNotHasKey('total_tax', $result);
        $this->assertArrayNotHasKey('cancel_reason', $result);
        $this->assertArrayNotHasKey('note_attributes', $result);
        $this->assertArrayNotHasKey('risk_level', $result);
        $this->assertArrayNotHasKey('risk_recommendation', $result);
        $this->assertArrayNotHasKey('risk_assessments', $result);
        $this->assertArrayNotHasKey('client_ip', $result);
        $this->assertArrayNotHasKey('test', $result);
        $this->assertArrayNotHasKey('customer_journey', $result);
        $this->assertArrayNotHasKey('source_name', $result);
        $this->assertArrayNotHasKey('app_name', $result);
        $this->assertArrayNotHasKey('current_total_price', $result);
        $this->assertArrayNotHasKey('edited', $result);
        $this->assertArrayNotHasKey('payment_gateway_names', $result);
        $this->assertArrayNotHasKey('po_number', $result);
        $this->assertArrayNotHasKey('confirmation_number', $result);
        $this->assertArrayNotHasKey('status_page_url', $result);
        $this->assertArrayNotHasKey('customer_locale', $result);
    }

    public function testNormalizeOrderIncludesTagsWhenPresent(): void
    {
        $node   = $this->makeMinimalNode(['tags' => ['vip', 'new-customer']]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('tags', $result);
        $this->assertSame('vip, new-customer', $result['tags']);
    }

    public function testNormalizeOrderTagsEmptyArrayGivesEmptyString(): void
    {
        $node   = $this->makeMinimalNode(['tags' => []]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame('', $result['tags']);
    }

    public function testNormalizeOrderIncludesNoteWhenPresent(): void
    {
        $node   = $this->makeMinimalNode(['note' => 'Please gift wrap']);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('note', $result);
        $this->assertSame('Please gift wrap', $result['note']);
    }

    public function testNormalizeOrderNoteKeyPresentEvenWhenNull(): void
    {
        $node   = $this->makeMinimalNode(['note' => null]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('note', $result);
        $this->assertSame('', $result['note']);
    }

    public function testNormalizeOrderIncludesTotalTaxWhenPresent(): void
    {
        $node   = $this->makeMinimalNode(['totalTaxSet' => ['shopMoney' => ['amount' => '8.50']]]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('total_tax', $result);
        $this->assertSame('8.50', $result['total_tax']);
    }

    public function testNormalizeOrderIncludesCancelReasonWhenPresent(): void
    {
        $node   = $this->makeMinimalNode(['cancelReason' => 'CUSTOMER']);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('cancel_reason', $result);
        $this->assertSame('customer', $result['cancel_reason']);
    }

    public function testNormalizeOrderCancelReasonNullWhenKeyPresent(): void
    {
        $node   = $this->makeMinimalNode(['cancelReason' => null]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('cancel_reason', $result);
        $this->assertNull($result['cancel_reason']);
    }

    public function testNormalizeOrderIncludesShippingAddressWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'shippingAddress' => [
                'firstName' => 'Alice',
                'lastName'  => 'Smith',
                'name'      => 'Alice Smith',
                'address1'  => '1 Apple Park Way',
                'city'      => 'Cupertino',
                'province'  => 'California',
                'provinceCode' => 'CA',
                'country'   => 'United States',
                'countryCodeV2' => 'US',
                'zip'       => '95014',
                'phone'     => '',
                'address2'  => '',
                'company'   => null,
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('shipping_address', $result);
        $this->assertSame('Alice', $result['shipping_address']['first_name']);
    }

    public function testNormalizeOrderShippingAddressNullWhenKeyPresent(): void
    {
        $node   = $this->makeMinimalNode(['shippingAddress' => null]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('shipping_address', $result);
        $this->assertNull($result['shipping_address']);
    }

    public function testNormalizeOrderIncludesLineItemsWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'lineItems' => [
                'nodes' => [
                    [
                        'id'       => 'gid://shopify/LineItem/10',
                        'title'    => 'T-Shirt',
                        'quantity' => 2,
                        'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00']],
                    ],
                ],
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('line_items', $result);
        $this->assertCount(1, $result['line_items']);
        $this->assertSame('T-Shirt', $result['line_items'][0]['title']);
    }

    public function testNormalizeOrderIncludesShippingLinesWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'shippingLines' => [
                'nodes' => [
                    [
                        'id'    => 'gid://shopify/ShippingLine/20',
                        'title' => 'Express',
                        'code'  => 'EXPRESS',
                        'originalPriceSet' => ['shopMoney' => ['amount' => '12.00']],
                    ],
                ],
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('shipping_lines', $result);
        $this->assertCount(1, $result['shipping_lines']);
        $this->assertSame('Express', $result['shipping_lines'][0]['title']);
    }

    public function testNormalizeOrderIncludesFulfillmentsWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'fulfillments' => [
                [
                    'id'               => 'gid://shopify/Fulfillment/30',
                    'legacyResourceId' => '30',
                    'status'           => 'SUCCESS',
                    'displayStatus'    => 'DELIVERED',
                    'trackingInfo'     => [],
                    'fulfillmentLineItems' => ['edges' => []],
                ],
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('fulfillments', $result);
        $this->assertCount(1, $result['fulfillments']);
        $this->assertSame('success', $result['fulfillments'][0]['status']);
    }

    public function testNormalizeOrderIncludesRefundsWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'refunds' => [
                [
                    'id'               => 'gid://shopify/Refund/40',
                    'legacyResourceId' => '40',
                    'refundLineItems'  => ['nodes' => []],
                    'transactions'     => ['nodes' => []],
                ],
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('refunds', $result);
        $this->assertCount(1, $result['refunds']);
    }

    public function testNormalizeOrderIncludesDiscountCodesWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'discountApplications' => [
                'nodes' => [
                    [
                        '__typename'       => 'DiscountCodeApplication',
                        'code'             => 'DEAL10',
                        'allocationMethod' => 'ACROSS',
                        'targetSelection'  => 'ALL',
                        'targetType'       => 'LINE_ITEM',
                        'value'            => [
                            '__typename' => 'MoneyV2',
                            'amount'     => '10.00',
                        ],
                    ],
                ],
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('discount_codes', $result);
        $this->assertCount(1, $result['discount_codes']);
        $this->assertSame('DEAL10', $result['discount_codes'][0]['code']);
    }

    public function testNormalizeOrderFiltersOutNullDiscounts(): void
    {
        $node = $this->makeMinimalNode([
            'discountApplications' => [
                'nodes' => [
                    ['__typename' => 'AutomaticDiscountApplication', 'code' => 'AUTO'],
                ],
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('discount_codes', $result);
        $this->assertSame([], $result['discount_codes']);
    }

    // ── customAttributes / risk ───────────────────────────────────────────────

    public function testNormalizeOrderIncludesNoteAttributesWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'customAttributes' => [
                ['key' => 'checkout_session_id', 'value' => '87823702-7746-4191'],
                ['key' => 'bsure-attribute', 'value' => 'Plug Type: Type B (US)'],
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayHasKey('note_attributes', $result);
        $this->assertCount(2, $result['note_attributes']);
        $this->assertSame('checkout_session_id', $result['note_attributes'][0]['key']);
        $this->assertSame('87823702-7746-4191', $result['note_attributes'][0]['value']);
    }

    public function testNormalizeOrderNoteAttributesEmptyArrayWhenNoCustomAttributes(): void
    {
        $node   = $this->makeMinimalNode(['customAttributes' => []]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame([], $result['note_attributes']);
    }

    public function testNormalizeOrderRiskLevelPicksHighestSeverityAcrossAssessments(): void
    {
        $node = $this->makeMinimalNode([
            'risk' => [
                'recommendation' => 'INVESTIGATE',
                'assessments' => [
                    ['riskLevel' => 'LOW', 'provider' => ['title' => 'Shopify'], 'facts' => []],
                    ['riskLevel' => 'HIGH', 'provider' => null, 'facts' => []],
                    ['riskLevel' => 'MEDIUM', 'provider' => ['title' => 'App X'], 'facts' => []],
                ],
            ],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame('HIGH', $result['risk_level']);
        $this->assertSame('INVESTIGATE', $result['risk_recommendation']);
        $this->assertCount(3, $result['risk_assessments']);
    }

    public function testNormalizeOrderRiskLevelEmptyWhenNoAssessments(): void
    {
        $node = $this->makeMinimalNode([
            'risk' => ['recommendation' => 'NONE', 'assessments' => []],
        ]);

        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame('', $result['risk_level']);
        $this->assertSame('NONE', $result['risk_recommendation']);
        $this->assertSame([], $result['risk_assessments']);
    }

    public function testNormalizeOrderRiskAbsentWhenKeyNotPresent(): void
    {
        $node   = $this->makeMinimalNode();
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertArrayNotHasKey('risk_level', $result);
        $this->assertArrayNotHasKey('risk_recommendation', $result);
        $this->assertArrayNotHasKey('risk_assessments', $result);
    }

    // ── fraud / attribution / channel / finance / support fields ─────────────

    public function testNormalizeOrderIncludesClientIpWhenPresent(): void
    {
        $node   = $this->makeMinimalNode(['clientIp' => '203.0.113.7']);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame('203.0.113.7', $result['client_ip']);
    }

    public function testNormalizeOrderIncludesTestFlagWhenPresent(): void
    {
        $node   = $this->makeMinimalNode(['test' => true]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertTrue($result['test']);
    }

    public function testNormalizeOrderIncludesCustomerJourneyWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'customerJourneySummary' => [
                'daysToConversion' => 3,
                'firstVisit' => [
                    'landingPage'    => '/products/widget',
                    'referrerUrl'    => 'https://google.com',
                    'source'         => 'google',
                    'utmParameters'  => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'summer'],
                ],
                'lastVisit' => ['landingPage' => '/cart', 'referrerUrl' => null, 'source' => 'direct'],
            ],
        ]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame(3, $result['customer_journey']['days_to_conversion']);
        $this->assertSame('/products/widget', $result['customer_journey']['first_visit']['landing_page']);
        $this->assertSame('google', $result['customer_journey']['first_visit']['utm']['source']);
        $this->assertSame('direct', $result['customer_journey']['last_visit']['source']);
    }

    public function testNormalizeOrderIncludesSourceNameAndAppWhenPresent(): void
    {
        $node   = $this->makeMinimalNode(['sourceName' => 'web', 'app' => ['name' => 'Online Store']]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame('web', $result['source_name']);
        $this->assertSame('Online Store', $result['app_name']);
    }

    public function testNormalizeOrderIncludesFinanceFieldsWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '89.00']],
            'edited'               => true,
            'paymentGatewayNames'  => ['shopify_payments', 'manual'],
            'poNumber'             => 'PO-42',
        ]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame('89.00', $result['current_total_price']);
        $this->assertTrue($result['edited']);
        $this->assertSame(['shopify_payments', 'manual'], $result['payment_gateway_names']);
        $this->assertSame('PO-42', $result['po_number']);
    }

    public function testNormalizeOrderIncludesSupportFieldsWhenPresent(): void
    {
        $node = $this->makeMinimalNode([
            'confirmationNumber' => 'ABC123XYZ',
            'statusPageUrl'      => 'https://shop.example/orders/abc/status',
            'customerLocale'     => 'en-US',
        ]);
        $result = \Shopify\GraphQL\OrderNormalizer::normalizeOrder($node);

        $this->assertSame('ABC123XYZ', $result['confirmation_number']);
        $this->assertSame('https://shop.example/orders/abc/status', $result['status_page_url']);
        $this->assertSame('en-US', $result['customer_locale']);
    }
}
