<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShopifyClientTest extends TestCase
{
    private function makeStack(array $responses, array &$history = []): HandlerStack
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return $stack;
    }

    private function shopify(array $responses, array &$history = []): Shopify
    {
        return new Shopify('test.myshopify.com', 'tok_test', null, $this->makeStack($responses, $history));
    }

    private function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, array_merge(['Content-Type' => 'application/json'], $headers), json_encode($data));
    }

    private function graphQLOrders(array $nodes): Response
    {
        return $this->json([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => array_map(fn($node) => ['node' => $node], $nodes),
                ],
            ],
        ]);
    }

    private function graphQLEvents(array $nodes): Response
    {
        return $this->json([
            'data' => [
                'events' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => array_map(fn($node) => ['node' => $node], $nodes),
                ],
            ],
        ]);
    }

    private function graphQLNodes(array $nodes): Response
    {
        return $this->json([
            'data' => [
                'nodes' => $nodes,
            ],
        ]);
    }

    private function basicOrderEvent(string $message, string $createdAt, string $orderId = '77', string $action = 'update'): array
    {
        return [
            '__typename' => 'BasicEvent',
            'id' => 'gid://shopify/BasicEvent/' . preg_replace('/\D+/', '', $orderId),
            'action' => $action,
            'appTitle' => 'Shopify Admin',
            'createdAt' => $createdAt,
            'message' => $message,
            'subjectId' => 'gid://shopify/Order/' . $orderId,
            'subjectType' => 'ORDER',
        ];
    }

    private function orderNode(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'gid://shopify/Order/77',
            'legacyResourceId' => '77',
            'name' => '#1077',
            'createdAt' => '2026-06-18T12:30:00Z',
            'cancelledAt' => null,
            'email' => 'addr@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '44.95', 'currencyCode' => 'USD']],
        ], $overrides);
    }

    // ── findByOrderNumber ─────────────────────────────────────────────────────

    public function testFindByOrderNumberReturnsOrders(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/1',
            'legacyResourceId' => '1',
            'name' => '#1001',
            'createdAt' => '2026-06-19T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'buyer@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '49.99', 'currencyCode' => 'USD']],
        ];
        $history = [];
        $shopify = $this->shopify([$this->json([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['node' => $node]],
                ],
            ],
        ])], $history);

        $result = $shopify->findByOrderNumber('1001');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame(1001, $result[0]['order_number']);
        $this->assertSame('#1001', $result[0]['name']);
        $this->assertSame('paid', $result[0]['financial_status']);
        $this->assertNull($result[0]['fulfillment_status']);
        $this->assertSame('49.99', $result[0]['total_price']);
        $this->assertStringEndsWith('/graphql.json', (string) $history[0]['request']->getUri());
    }

    public function testFindByOrderNumberStripsHash(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [],
                ],
            ],
        ])], $history);

        $shopify->findByOrderNumber('#1001');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('name:1001', $body['variables']['query']);
        $this->assertStringNotContainsString('#1001', $body['variables']['query']);
    }

    public function testFindByOrderNumberSendsAuthHeader(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->json([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [],
                ],
            ],
        ])], $history);

        $shopify->findByOrderNumber('1001');

        $this->assertSame('tok_test', $history[0]['request']->getHeaderLine('X-Shopify-Access-Token'));
    }

    // ── getOrder ──────────────────────────────────────────────────────────────

    public function testGetOrderReturnsOrder(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/42',
            'legacyResourceId' => '42',
            'name' => '#1042',
            'createdAt' => '2026-06-19T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'buyer@example.com',
            'note' => 'Leave at door',
            'tags' => ['vip', 'manual-review'],
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '79.50', 'currencyCode' => 'USD']],
            'totalTaxSet' => ['shopMoney' => ['amount' => '4.50', 'currencyCode' => 'USD']],
            'shippingAddress' => [
                'firstName' => 'Ada',
                'lastName' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'company' => 'Analytical Engines',
                'address1' => '1 Main St',
                'address2' => 'Unit 2',
                'city' => 'London',
                'province' => 'England',
                'provinceCode' => 'ENG',
                'country' => 'United Kingdom',
                'countryCodeV2' => 'GB',
                'zip' => 'SW1A',
                'phone' => '123',
            ],
            'billingAddress' => null,
            'lineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/501',
                    'title' => 'Widget',
                    'name' => 'Widget - Red',
                    'sku' => 'WGT-RED',
                    'quantity' => 2,
                    'variantTitle' => 'Red',
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                ]],
            ],
            'shippingLines' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ShippingLine/601',
                    'title' => 'Ground',
                    'code' => 'GROUND',
                    'originalPriceSet' => ['shopMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']],
                ]],
            ],
        ];
        $history = [];
        $shopify = $this->shopify([$this->json(['data' => ['order' => $node]])], $history);

        $result = $shopify->getOrder('42');

        $this->assertSame(42, $result['id']);
        $this->assertSame(1042, $result['order_number']);
        $this->assertSame('#1042', $result['name']);
        $this->assertSame('paid', $result['financial_status']);
        $this->assertSame('partial', $result['fulfillment_status']);
        $this->assertSame('79.50', $result['total_price']);
        $this->assertSame('4.50', $result['total_tax']);
        $this->assertSame('vip, manual-review', $result['tags']);
        $this->assertSame('Leave at door', $result['note']);
        $this->assertSame('Ada', $result['shipping_address']['first_name']);
        $this->assertSame('GB', $result['shipping_address']['country_code']);
        $this->assertNull($result['billing_address']);
        $this->assertSame(501, $result['line_items'][0]['id']);
        $this->assertSame('WGT-RED', $result['line_items'][0]['sku']);
        $this->assertSame('25.00', $result['line_items'][0]['price']);
        $this->assertSame('Ground', $result['shipping_lines'][0]['title']);
        $this->assertSame('5.00', $result['shipping_lines'][0]['price']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('gid://shopify/Order/42', $body['variables']['id']);
    }

    public function testGetOrderThrowsOn404(): void
    {
        $shopify = $this->shopify([$this->json(['errors' => 'Not found'], 404)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/404/');

        $shopify->getOrder('99999');
    }

    // ── getOrderMetafields ──────────────────────────────────────────────────

    public function testGetOrderMetafieldsUsesGraphQLAndReturnsRestShape(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'order' => [
                        'metafields' => [
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            'nodes' => [[
                                'id' => 'gid://shopify/Metafield/9001',
                                'namespace' => 'custom',
                                'key' => 'risk_note',
                                'value' => 'Manual review',
                                'type' => 'single_line_text_field',
                                'createdAt' => '2026-06-19T10:00:00Z',
                                'updatedAt' => '2026-06-19T11:00:00Z',
                            ]],
                        ],
                    ],
                ],
            ]),
        ], $history);

        $result = $shopify->getOrderMetafields('42');

        $this->assertCount(1, $result);
        $this->assertSame(9001, $result[0]['id']);
        $this->assertSame('custom', $result[0]['namespace']);
        $this->assertSame('risk_note', $result[0]['key']);
        $this->assertSame('Manual review', $result[0]['value']);
        $this->assertSame('single_line_text_field', $result[0]['type']);
        $this->assertSame(42, $result[0]['owner_id']);
        $this->assertSame('order', $result[0]['owner_resource']);
        $this->assertSame('gid://shopify/Metafield/9001', $result[0]['admin_graphql_api_id']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/graphql.json', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('gid://shopify/Order/42', $body['variables']['id']);
        $this->assertNull($body['variables']['after']);
        $this->assertStringContainsString('order(id: $id)', $body['query']);
        $this->assertStringContainsString('metafields(first: 250, after: $after)', $body['query']);
    }

    public function testGetOrderMetafieldsPaginates(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'order' => [
                        'metafields' => [
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                            'nodes' => [[
                                'id' => 'gid://shopify/Metafield/1',
                                'namespace' => 'custom',
                                'key' => 'first',
                                'value' => 'one',
                                'type' => 'single_line_text_field',
                                'createdAt' => '2026-06-19T10:00:00Z',
                                'updatedAt' => '2026-06-19T10:00:00Z',
                            ]],
                        ],
                    ],
                ],
            ]),
            $this->json([
                'data' => [
                    'order' => [
                        'metafields' => [
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            'nodes' => [[
                                'id' => 'gid://shopify/Metafield/2',
                                'namespace' => 'custom',
                                'key' => 'second',
                                'value' => 'two',
                                'type' => 'single_line_text_field',
                                'createdAt' => '2026-06-19T10:00:00Z',
                                'updatedAt' => '2026-06-19T10:00:00Z',
                            ]],
                        ],
                    ],
                ],
            ]),
        ], $history);

        $result = $shopify->getOrderMetafields('42');

        $this->assertSame(['first', 'second'], array_column($result, 'key'));
        $secondBody = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('cursor-1', $secondBody['variables']['after']);
    }

    // ── fetchAllOrders pagination ─────────────────────────────────────────────

    public function testFetchAllOrdersPaginates(): void
    {
        $page1Orders = [
            [
                'id' => 'gid://shopify/Order/1',
                'legacyResourceId' => '1',
                'name' => '#1001',
                'createdAt' => '2024-01-01T10:00:00Z',
                'cancelledAt' => null,
                'email' => 'a@example.com',
                'displayFinancialStatus' => 'PAID',
                'displayFulfillmentStatus' => 'UNFULFILLED',
                'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
                'lineItems' => ['nodes' => []],
                'shippingLines' => ['nodes' => []],
            ],
            [
                'id' => 'gid://shopify/Order/2',
                'legacyResourceId' => '2',
                'name' => '#1002',
                'createdAt' => '2024-01-02T10:00:00Z',
                'cancelledAt' => null,
                'email' => 'b@example.com',
                'displayFinancialStatus' => 'PAID',
                'displayFulfillmentStatus' => 'FULFILLED',
                'totalPriceSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'USD']],
                'lineItems' => ['nodes' => []],
                'shippingLines' => ['nodes' => []],
            ],
        ];
        $page2Orders = [[
            'id' => 'gid://shopify/Order/3',
            'legacyResourceId' => '3',
            'name' => '#1003',
            'createdAt' => '2024-01-03T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'c@example.com',
            'displayFinancialStatus' => 'PARTIALLY_REFUNDED',
            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '30.00', 'currencyCode' => 'USD']],
            'lineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/3001',
                    'title' => 'Widget',
                    'name' => 'Widget - Blue',
                    'sku' => 'WGT-BLU',
                    'quantity' => 1,
                    'variantTitle' => 'Blue',
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                ]],
            ],
            'shippingLines' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ShippingLine/4001',
                    'title' => 'Ground',
                    'code' => 'GROUND',
                    'originalPriceSet' => ['shopMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']],
                ]],
            ],
        ]];

        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                        'edges' => array_map(fn($node) => ['node' => $node], $page1Orders),
                    ],
                ],
            ]),
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => array_map(fn($node) => ['node' => $node], $page2Orders),
                    ],
                ],
            ]),
        ], $history);

        $result = $shopify->fetchAllOrders('2024-01-01', '2024-01-31');

        $this->assertCount(3, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame(3, $result[2]['id']);
        $this->assertSame('partial', $result[2]['fulfillment_status']);
        $this->assertSame('WGT-BLU', $result[2]['line_items'][0]['sku']);
        $this->assertSame('Ground', $result[2]['shipping_lines'][0]['title']);

        $firstBody = json_decode((string) $history[0]['request']->getBody(), true);
        $secondBody = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('status:any created_at:>=2024-01-01T00:00:00Z created_at:<=2024-01-31T23:59:59Z', $firstBody['variables']['query']);
        $this->assertNull($firstBody['variables']['after']);
        $this->assertSame('cursor-1', $secondBody['variables']['after']);
        $this->assertStringEndsWith('/graphql.json', (string) $history[0]['request']->getUri());
    }

    public function testFetchAllOrdersStopsWhenNoNextPage(): void
    {
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [[
                            'node' => [
                                'id' => 'gid://shopify/Order/1',
                                'legacyResourceId' => '1',
                                'name' => '#1001',
                                'createdAt' => '2024-01-01T10:00:00Z',
                                'cancelledAt' => null,
                                'email' => 'a@example.com',
                                'displayFinancialStatus' => 'PAID',
                                'displayFulfillmentStatus' => 'UNFULFILLED',
                                'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
                                'lineItems' => ['nodes' => []],
                                'shippingLines' => ['nodes' => []],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $result = $shopify->fetchAllOrders('2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
    }

    // ── fetchOrdersForAddressScan ────────────────────────────────────────────

    public function testFetchOrdersForAddressScanUsesGraphQLAndNormalizesAddressFields(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/77',
            'legacyResourceId' => '77',
            'name' => '#1077',
            'createdAt' => '2026-06-18T12:30:00Z',
            'email' => 'addr@example.com',
            'displayFinancialStatus' => 'PARTIALLY_PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '44.95', 'currencyCode' => 'USD']],
            'shippingAddress' => [
                'firstName' => 'Grace',
                'lastName' => 'Hopper',
                'name' => 'Grace Hopper',
                'company' => null,
                'address1' => '123 Main St',
                'address2' => 'Apt 4',
                'city' => 'Arlington',
                'province' => 'Virginia',
                'provinceCode' => 'VA',
                'country' => 'United States',
                'countryCodeV2' => 'US',
                'zip' => '22201',
                'phone' => '555-0100',
            ],
            'shippingLines' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ShippingLine/701',
                    'title' => 'Ground',
                    'code' => 'GROUND',
                    'originalPriceSet' => ['shopMoney' => ['amount' => '6.95', 'currencyCode' => 'USD']],
                ]],
            ],
        ];
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [['node' => $node]],
                    ],
                ],
            ]),
        ], $history);

        $result = $shopify->fetchOrdersForAddressScan('2026-06-01', '2026-06-19');

        $this->assertCount(1, $result);
        $this->assertSame(77, $result[0]['id']);
        $this->assertSame(1077, $result[0]['order_number']);
        $this->assertSame('partially_paid', $result[0]['financial_status']);
        $this->assertNull($result[0]['fulfillment_status']);
        $this->assertSame('44.95', $result[0]['total_price']);
        $this->assertSame('Grace', $result[0]['shipping_address']['first_name']);
        $this->assertSame('US', $result[0]['shipping_address']['country_code']);
        $this->assertSame('Ground', $result[0]['shipping_lines'][0]['title']);
        $this->assertSame('6.95', $result[0]['shipping_lines'][0]['price']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/graphql.json', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(
            'status:any (financial_status:paid OR financial_status:partially_paid) created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-19T23:59:59Z',
            $body['variables']['query']
        );
        $this->assertNull($body['variables']['after']);
        $this->assertStringContainsString('shippingAddress', $body['query']);
        $this->assertStringContainsString('shippingLines(first: 250)', $body['query']);
    }

    public function testFetchOrdersForAddressScanAddsUnfulfilledFilterWhenRequested(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [],
                    ],
                ],
            ]),
        ], $history);

        $shopify->fetchOrdersForAddressScan('2026-06-01', '2026-06-19', true);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(
            'status:any (financial_status:paid OR financial_status:partially_paid) created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-19T23:59:59Z (fulfillment_status:unfulfilled OR fulfillment_status:partial)',
            $body['variables']['query']
        );
    }

    public function testFetchOrdersWithAddressChangesUsesGraphQLEventsAndBatchOrderFetch(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->graphQLEvents([
                $this->basicOrderEvent('Shipping address was updated to 1 Main St', '2026-06-19T12:00:00Z'),
                $this->basicOrderEvent('Order was paid', '2026-06-19T11:00:00Z', '88'),
            ]),
            $this->graphQLNodes([
                $this->orderNode([
                    'shippingAddress' => [
                        'firstName' => 'Grace',
                        'lastName' => 'Hopper',
                        'name' => 'Grace Hopper',
                        'company' => null,
                        'address1' => '1 Main St',
                        'address2' => '',
                        'city' => 'Arlington',
                        'province' => 'Virginia',
                        'provinceCode' => 'VA',
                        'country' => 'United States',
                        'countryCodeV2' => 'US',
                        'zip' => '22201',
                        'phone' => '555-0100',
                    ],
                ]),
            ]),
        ], $history);

        $result = $shopify->fetchOrdersWithAddressChanges('2026-06-01', '2026-06-19');

        $this->assertCount(1, $result);
        $this->assertSame(77, $result[0]['order']['id']);
        $this->assertSame('2026-06-19T12:00:00Z', $result[0]['changed_at']);
        $this->assertSame('Grace', $result[0]['order']['shipping_address']['first_name']);

        $eventsRequest = $history[0]['request'];
        $this->assertSame('POST', $eventsRequest->getMethod());
        $this->assertStringEndsWith('/graphql.json', (string) $eventsRequest->getUri());
        $eventsBody = json_decode((string) $eventsRequest->getBody(), true);
        $this->assertStringContainsString('events(first: 250', $eventsBody['query']);
        $this->assertSame(
            'subject_type:ORDER comments:false created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-19T23:59:59Z',
            $eventsBody['variables']['query']
        );

        $ordersBody = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertStringContainsString('nodes(ids: $ids)', $ordersBody['query']);
        $this->assertSame(['gid://shopify/Order/77'], $ordersBody['variables']['ids']);
    }

    public function testFetchOrdersWithAddressChangesPaginatesAcrossMultipleEventPages(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'events' => [
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                        'edges' => [['node' => $this->basicOrderEvent('Shipping address was updated to 1 Main St', '2026-06-19T12:00:00Z', '77')]],
                    ],
                ],
            ]),
            $this->json([
                'data' => [
                    'events' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [['node' => $this->basicOrderEvent('Shipping address was updated to 2 Oak Ave', '2026-06-19T13:00:00Z', '78')]],
                    ],
                ],
            ]),
            $this->graphQLNodes([
                $this->orderNode(['id' => 'gid://shopify/Order/77', 'legacyResourceId' => '77']),
                $this->orderNode(['id' => 'gid://shopify/Order/78', 'legacyResourceId' => '78']),
            ]),
        ], $history);

        $result = $shopify->fetchOrdersWithAddressChanges('2026-06-01', '2026-06-19');

        $this->assertCount(2, $result, 'both event pages must be walked, not just the first');
        $this->assertCount(3, $history, 'two event pages + one batched order fetch');

        $secondPageBody = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('cursor-1', $secondPageBody['variables']['after']);

        $ordersBody = json_decode((string) $history[2]['request']->getBody(), true);
        $this->assertEqualsCanonicalizing(
            ['gid://shopify/Order/77', 'gid://shopify/Order/78'],
            $ordersBody['variables']['ids']
        );
    }

    public function testFetchEditedOrdersUsesGraphQLEventsAndNormalizesRows(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->graphQLEvents([
                $this->basicOrderEvent('Item was added', '2026-06-19T13:45:00Z', '77', 'edit_complete'),
            ]),
            $this->graphQLNodes([
                $this->orderNode([
                    'createdAt' => '2026-06-19T12:30:00Z',
                    'displayFinancialStatus' => 'PARTIALLY_PAID',
                    'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
                    'totalPriceSet' => ['shopMoney' => ['amount' => '99.00', 'currencyCode' => 'USD']],
                ]),
            ]),
        ], $history);

        $result = $shopify->fetchEditedOrders('2026-06-01', '2026-06-19');

        $this->assertCount(1, $result);
        $this->assertSame('77', $result[0]['shopify_id']);
        $this->assertSame('#1077', $result[0]['order_number']);
        $this->assertSame('2026-06-19', $result[0]['created_at']);
        $this->assertSame('2026-06-19T13:45', $result[0]['edited_at']);
        $this->assertSame(75, $result[0]['diff_mins']);
        $this->assertSame('partially_paid', $result[0]['financial']);
        $this->assertSame('partial', $result[0]['fulfillment']);
        $this->assertSame(['Item was added'], $result[0]['edit_summary']);

        $this->assertStringEndsWith('/graphql.json', (string) $history[0]['request']->getUri());
        $this->assertStringEndsWith('/graphql.json', (string) $history[1]['request']->getUri());
    }

    public function testFetchPostShipAddressChangesUsesGraphQLFulfillmentData(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->graphQLEvents([
                $this->basicOrderEvent('Shipping address was updated to 1 Main St', '2026-06-19T14:00:00Z'),
            ]),
            $this->graphQLNodes([
                $this->orderNode([
                    'shippingAddress' => [
                        'firstName' => 'Grace',
                        'lastName' => 'Hopper',
                        'name' => 'Grace Hopper',
                        'company' => null,
                        'address1' => '1 Main St',
                        'address2' => '',
                        'city' => 'Arlington',
                        'province' => 'Virginia',
                        'provinceCode' => 'VA',
                        'country' => 'United States',
                        'countryCodeV2' => 'US',
                        'zip' => '22201',
                        'phone' => '555-0100',
                    ],
                    'fulfillments' => [[
                        'id' => 'gid://shopify/Fulfillment/500',
                        'legacyResourceId' => '500',
                        'createdAt' => '2026-06-19T13:00:00Z',
                        'status' => 'SUCCESS',
                        'displayStatus' => 'FULFILLED',
                        'trackingInfo' => [],
                        'fulfillmentLineItems' => ['edges' => []],
                    ]],
                ]),
            ]),
        ], $history);

        $result = $shopify->fetchPostShipAddressChanges('2026-06-01', '2026-06-19');

        $this->assertCount(1, $result);
        $this->assertSame(77, $result[0]['order']['id']);
        $this->assertSame('2026-06-19T14:00:00Z', $result[0]['changed_at']);
        $this->assertSame('2026-06-19T13:00:00Z', $result[0]['fulfillment_at']);

        $ordersBody = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertStringContainsString('fulfillments(first: 250)', $ordersBody['query']);
    }

    public function testGetOrderEventsUsesGraphQLAndReturnsRestShape(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'order' => [
                        'events' => [
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            'edges' => [[
                                'node' => $this->basicOrderEvent('Order was edited', '2026-06-19T15:00:00Z', '77', 'edit_complete'),
                            ]],
                        ],
                    ],
                ],
            ]),
        ], $history);

        $result = $shopify->getOrderEvents('77');

        $this->assertCount(1, $result);
        $this->assertSame('edit_complete', $result[0]['verb']);
        $this->assertSame('Order was edited', $result[0]['message']);
        $this->assertSame('2026-06-19T15:00:00Z', $result[0]['created_at']);
        $this->assertSame(77, $result[0]['subject_id']);
        $this->assertSame('order', $result[0]['subject_type']);

        $request = $history[0]['request'];
        $this->assertStringEndsWith('/graphql.json', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('gid://shopify/Order/77', $body['variables']['id']);
        $this->assertStringContainsString('order(id: $id)', $body['query']);
        $this->assertStringContainsString('events(first: 250', $body['query']);
    }

    // ── 429 retry ─────────────────────────────────────────────────────────────

    public function testRetryOn429WithRetryAfterHeader(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'legacyResourceId' => '1',
            'name' => '#1001',
            'createdAt' => '2026-06-19T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'buyer@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
        ];
        $mock  = new MockHandler([
            new Response(429, ['Retry-After' => '0']),
            $this->json([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [['node' => $order]],
                    ],
                ],
            ]),
        ]);
        $shopify = new Shopify('test.myshopify.com', 'tok_test', null, HandlerStack::create($mock));

        $result = $shopify->findByOrderNumber('1001');

        $this->assertSame(0, $mock->count()); // both responses consumed: 429 then 200
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('#1001', $result[0]['name']);
    }

    public function testStopsRetryingAfterFiveAttempts(): void
    {
        // 6 responses: original + 5 retries, all 429 - 6th passes through and throws
        $mock    = new MockHandler(array_fill(0, 6, new Response(429, ['Retry-After' => '0'], '')));
        $shopify = new Shopify('test.myshopify.com', 'tok_test', null, HandlerStack::create($mock));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        $shopify->findByOrderNumber('1001');
    }

    // ── GraphQL (searchOrdersByTag) ───────────────────────────────────────────

    public function testSearchOrdersByTagReturnsMatches(): void
    {
        $node = ['id' => 'gid://shopify/Order/1', 'name' => '#1001', 'tags' => ['wholesale']];

        $gqlResponse = [
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges'    => [['node' => $node]],
                ],
            ],
        ];

        $shopify = $this->shopify([$this->json($gqlResponse)]);
        $result  = $shopify->searchOrdersByTag('wholesale');

        $this->assertCount(1, $result['matches']);
        $this->assertSame('#1001', $result['matches'][0]['name']);
        $this->assertFalse($result['truncated']);
    }

    public function testSearchOrdersByTagThrowsOnGraphQLErrors(): void
    {
        $shopify = $this->shopify([$this->json(['errors' => [['message' => 'Syntax error']]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/GraphQL/');

        $shopify->searchOrdersByTag('test');
    }

    // ── isOnHold ─────────────────────────────────────────────────────────────

    public function testIsOnHoldReturnsTrueWhenOnHold(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'order' => [
                        'fulfillmentOrders' => [
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            'nodes' => [['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'ON_HOLD']],
                        ],
                    ],
                ],
            ]),
        ], $history);

        $this->assertTrue($shopify->isOnHold('123'));

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/graphql.json', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('gid://shopify/Order/123', $body['variables']['id']);
        $this->assertNull($body['variables']['after']);
        $this->assertStringContainsString('fulfillmentOrders(first: 250, after: $after)', $body['query']);
    }

    public function testIsOnHoldReturnsFalseWhenOpen(): void
    {
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'order' => [
                        'fulfillmentOrders' => [
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            'nodes' => [['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN']],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->assertFalse($shopify->isOnHold('123'));
    }

    public function testIsOnHoldPaginatesFulfillmentOrders(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'order' => [
                        'fulfillmentOrders' => [
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                            'nodes' => [['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN']],
                        ],
                    ],
                ],
            ]),
            $this->json([
                'data' => [
                    'order' => [
                        'fulfillmentOrders' => [
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            'nodes' => [['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'ON_HOLD']],
                        ],
                    ],
                ],
            ]),
        ], $history);

        $this->assertTrue($shopify->isOnHold('123'));

        $secondBody = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('cursor-1', $secondBody['variables']['after']);
    }

    public function testFetchOrdersForSlaUsesGraphQLAndNormalizesFulfillmentData(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([[
            'id' => 'gid://shopify/Order/610',
            'legacyResourceId' => '610',
            'name' => '#1610',
            'createdAt' => '2026-06-10T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'sla@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'USD']],
            'shippingAddress' => ['provinceCode' => 'CA', 'countryCodeV2' => 'US'],
            'shippingLines' => ['nodes' => [[
                'id' => 'gid://shopify/ShippingLine/710',
                'title' => 'Express',
                'code' => 'EXP',
                'originalPriceSet' => ['shopMoney' => ['amount' => '12.00', 'currencyCode' => 'USD']],
            ]]],
            'lineItems' => ['nodes' => [[
                'id' => 'gid://shopify/LineItem/810',
                'title' => 'Widget',
                'name' => 'Widget',
                'sku' => 'WGT',
                'quantity' => 2,
                'unfulfilledQuantity' => 0,
                'variantTitle' => null,
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '44.00', 'currencyCode' => 'USD']],
            ]]],
            'fulfillments' => [[
                'id' => 'gid://shopify/Fulfillment/910',
                'legacyResourceId' => '910',
                'createdAt' => '2026-06-12T10:00:00Z',
                'status' => 'SUCCESS',
                'displayStatus' => 'FULFILLED',
                'trackingInfo' => [['company' => 'UPS', 'number' => '1Z999', 'url' => 'https://track.example/1Z999']],
                'fulfillmentLineItems' => ['edges' => [[
                    'node' => [
                        'quantity' => 2,
                        'lineItem' => [
                            'id' => 'gid://shopify/LineItem/810',
                            'title' => 'Widget',
                            'name' => 'Widget',
                            'sku' => 'WGT',
                            'quantity' => 2,
                            'variantTitle' => null,
                            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '44.00', 'currencyCode' => 'USD']],
                        ],
                    ],
                ]]],
            ]],
        ]])], $history);

        $result = $shopify->fetchOrdersForSla('2026-06-01', '2026-06-19');

        $this->assertSame(610, $result[0]['id']);
        $this->assertSame('fulfilled', $result[0]['fulfillment_status']);
        $this->assertSame('Express', $result[0]['shipping_lines'][0]['title']);
        $this->assertSame(0, $result[0]['line_items'][0]['fulfillable_quantity']);
        $this->assertSame(910, $result[0]['fulfillments'][0]['id']);
        $this->assertSame('success', $result[0]['fulfillments'][0]['status']);
        $this->assertSame('UPS', $result[0]['fulfillments'][0]['tracking_company']);
        $this->assertSame('1Z999', $result[0]['fulfillments'][0]['tracking_number']);
        $this->assertSame('https://track.example/1Z999', $result[0]['fulfillments'][0]['tracking_url']);
        $this->assertSame(2, $result[0]['fulfillments'][0]['line_items'][0]['quantity']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringEndsWith('/graphql.json', (string) $history[0]['request']->getUri());
        $this->assertSame(
            'status:any (financial_status:paid OR financial_status:partially_paid) created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-19T23:59:59Z',
            $body['variables']['query']
        );
        $this->assertStringContainsString('fulfillments(first: 250)', $body['query']);
        $this->assertStringContainsString('trackingInfo(first: 10)', $body['query']);
        $this->assertStringContainsString('unfulfilledQuantity', $body['query']);
    }

    public function testFetchOrdersForDiscountAuditMapsDiscountApplicationsToCodes(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([[
            'id' => 'gid://shopify/Order/620',
            'legacyResourceId' => '620',
            'name' => '#1620',
            'createdAt' => '2026-06-11T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'discount@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '80.00', 'currencyCode' => 'USD']],
            'shippingAddress' => ['address1' => '10 Shared St', 'countryCodeV2' => 'US'],
            'discountApplications' => ['nodes' => [
                [
                    '__typename' => 'DiscountCodeApplication',
                    'code' => 'SAVE10',
                    'allocationMethod' => 'ACROSS',
                    'targetSelection' => 'ALL',
                    'targetType' => 'LINE_ITEM',
                    'value' => ['__typename' => 'PricingPercentageValue', 'percentage' => 10.0],
                ],
                [
                    '__typename' => 'AutomaticDiscountApplication',
                    'allocationMethod' => 'ACROSS',
                    'targetSelection' => 'ALL',
                    'targetType' => 'LINE_ITEM',
                    'value' => ['__typename' => 'MoneyV2', 'amount' => '5.00', 'currencyCode' => 'USD'],
                ],
            ]],
        ]])], $history);

        $result = $shopify->fetchOrdersForDiscountAudit('2026-06-01', '2026-06-19');

        $this->assertSame('10 Shared St', $result[0]['shipping_address']['address1']);
        $this->assertCount(1, $result[0]['discount_codes']);
        $this->assertSame('SAVE10', $result[0]['discount_codes'][0]['code']);
        $this->assertSame('10', $result[0]['discount_codes'][0]['amount']);
        $this->assertSame('percentage', $result[0]['discount_codes'][0]['type']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringEndsWith('/graphql.json', (string) $history[0]['request']->getUri());
        $this->assertStringContainsString('discountApplications(first: 250)', $body['query']);
        $this->assertStringContainsString('DiscountCodeApplication', $body['query']);
        $this->assertStringContainsString('shippingAddress', $body['query']);
    }

    public function testFetchRefundedOrdersUsesGraphQLAndNormalizesRefunds(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([[
            'id' => 'gid://shopify/Order/630',
            'legacyResourceId' => '630',
            'name' => '#1630',
            'createdAt' => '2026-06-12T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'refund@example.com',
            'displayFinancialStatus' => 'PARTIALLY_REFUNDED',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '120.00', 'currencyCode' => 'USD']],
            'refunds' => [[
                'id' => 'gid://shopify/Refund/930',
                'legacyResourceId' => '930',
                'createdAt' => '2026-06-13T10:00:00Z',
                'note' => 'Damaged',
                'totalRefundedSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                'refundLineItems' => ['nodes' => [[
                    'quantity' => 1,
                    'subtotalSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                    'lineItem' => [
                        'id' => 'gid://shopify/LineItem/830',
                        'title' => 'Widget',
                        'name' => 'Widget',
                        'sku' => 'WGT',
                        'quantity' => 2,
                    ],
                ]]],
                'transactions' => ['nodes' => [[
                    'id' => 'gid://shopify/OrderTransaction/1030',
                    'kind' => 'REFUND',
                    'status' => 'SUCCESS',
                    'amountSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                ]]],
            ]],
        ]])], $history);

        $result = $shopify->fetchRefundedOrders('2026-06-01', '2026-06-19');

        $this->assertSame('partially_refunded', $result[0]['financial_status']);
        $this->assertSame(930, $result[0]['refunds'][0]['id']);
        $this->assertSame('25.00', $result[0]['refunds'][0]['total_refunded']);
        $this->assertSame('25.00', $result[0]['refunds'][0]['refund_line_items'][0]['subtotal']);
        $this->assertSame(830, $result[0]['refunds'][0]['refund_line_items'][0]['line_item_id']);
        $this->assertSame('refund', $result[0]['refunds'][0]['transactions'][0]['kind']);
        $this->assertSame('success', $result[0]['refunds'][0]['transactions'][0]['status']);
        $this->assertSame('25.00', $result[0]['refunds'][0]['transactions'][0]['amount']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('(financial_status:refunded OR financial_status:partially_refunded)', $body['variables']['query']);
        $this->assertStringContainsString('refundLineItems(first: 250)', $body['query']);
        $this->assertStringContainsString('transactions(first: 250)', $body['query']);
    }

    public function testFetchOrdersRefundedSinceUsesUpdatedAtFilterWithNoUpperBound(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([[
            'id' => 'gid://shopify/Order/670',
            'legacyResourceId' => '670',
            'name' => '#1670',
            'createdAt' => '2026-03-02T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'refunded-late@example.com',
            'displayFinancialStatus' => 'PARTIALLY_REFUNDED',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '120.00', 'currencyCode' => 'USD']],
            'refunds' => [[
                'id' => 'gid://shopify/Refund/940',
                'legacyResourceId' => '940',
                'createdAt' => '2026-07-15T10:00:00Z',
                'note' => '',
                'totalRefundedSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                'refundLineItems' => ['nodes' => [[
                    'quantity' => 1,
                    'subtotalSet' => ['shopMoney' => ['amount' => '25.00', 'currencyCode' => 'USD']],
                    'lineItem' => [
                        'id' => 'gid://shopify/LineItem/840',
                        'title' => 'Widget',
                        'name' => 'Widget',
                        'sku' => 'WGT',
                        'quantity' => 1,
                    ],
                ]]],
                'transactions' => ['nodes' => []],
            ]],
        ]])], $history);

        $result = $shopify->fetchOrdersRefundedSince('2026-07-01');

        // Order was created in March but fetched because it was refunded in July —
        // this is the whole point: the query must not filter on created_at.
        $this->assertSame(670, $result[0]['id']);
        $this->assertSame('2026-03-02T10:00:00Z', $result[0]['created_at']);
        $this->assertSame('2026-07-15T10:00:00Z', $result[0]['refunds'][0]['created_at']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(
            'status:any (financial_status:refunded OR financial_status:partially_refunded) updated_at:>=2026-07-01T00:00:00Z',
            $body['variables']['query']
        );
        $this->assertStringNotContainsString('created_at', $body['variables']['query']);
        $this->assertStringContainsString('refundLineItems(first: 250)', $body['query']);
    }

    public function testFetchPartiallyFulfilledOrdersUsesGraphQLPartialFilter(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([[
            'id' => 'gid://shopify/Order/640',
            'legacyResourceId' => '640',
            'name' => '#1640',
            'createdAt' => '2026-06-14T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'partial@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '150.00', 'currencyCode' => 'USD']],
            'lineItems' => ['nodes' => [[
                'id' => 'gid://shopify/LineItem/840',
                'title' => 'Remaining Widget',
                'name' => 'Remaining Widget',
                'sku' => 'REM',
                'quantity' => 3,
                'unfulfilledQuantity' => 1,
                'variantTitle' => null,
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']],
            ]]],
            'fulfillments' => [],
        ]])], $history);

        $result = $shopify->fetchPartiallyFulfilledOrders('2026-06-01', '2026-06-19');

        $this->assertSame('partial', $result[0]['fulfillment_status']);
        $this->assertSame(1, $result[0]['line_items'][0]['fulfillable_quantity']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(
            'status:open fulfillment_status:partial -financial_status:refunded created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-19T23:59:59Z',
            $body['variables']['query']
        );
        $this->assertStringContainsString('lineItems(first: 250)', $body['query']);
        $this->assertStringContainsString('fulfillments(first: 250)', $body['query']);
    }

    public function testFetchOrdersFulfilledSinceUsesUpdatedAtFilterWithNoUpperBound(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([[
            'id' => 'gid://shopify/Order/660',
            'legacyResourceId' => '660',
            'name' => '#1660',
            'createdAt' => '2026-03-02T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'shipped-late@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '60.00', 'currencyCode' => 'USD']],
            'fulfillments' => [[
                'id' => 'gid://shopify/Fulfillment/960',
                'legacyResourceId' => '960',
                'createdAt' => '2026-07-15T10:00:00Z',
                'status' => 'SUCCESS',
                'displayStatus' => 'FULFILLED',
                'trackingInfo' => [],
                'fulfillmentLineItems' => ['edges' => [[
                    'node' => [
                        'quantity' => 1,
                        'lineItem' => [
                            'id' => 'gid://shopify/LineItem/860',
                            'title' => 'Widget',
                            'name' => 'Widget',
                            'sku' => 'WGT',
                            'quantity' => 1,
                            'variantTitle' => null,
                            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '60.00', 'currencyCode' => 'USD']],
                        ],
                    ],
                ]]],
            ]],
        ]])], $history);

        $result = $shopify->fetchOrdersFulfilledSince('2026-07-01');

        // Order was created in March but fetched because it was fulfilled in July —
        // this is the whole point: the query must not filter on created_at.
        $this->assertSame(660, $result[0]['id']);
        $this->assertSame('2026-03-02T10:00:00Z', $result[0]['created_at']);
        $this->assertSame('2026-07-15T10:00:00Z', $result[0]['fulfillments'][0]['created_at']);
        $this->assertSame('Widget', $result[0]['fulfillments'][0]['line_items'][0]['title']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(
            'status:any (fulfillment_status:fulfilled OR fulfillment_status:partial) updated_at:>=2026-07-01T00:00:00Z',
            $body['variables']['query']
        );
        $this->assertStringNotContainsString('created_at', $body['variables']['query']);
        $this->assertStringContainsString('fulfillments(first: 250)', $body['query']);
    }

    public function testFetchOrdersFulfilledSinceWithShippingUsesUpdatedAtFilterAndShippingLines(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([[
            'id' => 'gid://shopify/Order/680',
            'legacyResourceId' => '680',
            'name' => '#1680',
            'createdAt' => '2026-03-05T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'margin-late@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '90.00', 'currencyCode' => 'USD']],
            'shippingLines' => ['nodes' => [[
                'id' => 'gid://shopify/ShippingLine/770',
                'title' => 'Standard',
                'code' => 'standard',
                'originalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
            ]]],
        ]])], $history);

        $result = $shopify->fetchOrdersFulfilledSinceWithShipping('2026-07-01');

        // Order was created in March but fetched because it was fulfilled in July —
        // matching loadShippingMargin's shipment-date-bounded shipments requires the
        // order set to not be filtered on created_at either.
        $this->assertSame(680, $result[0]['id']);
        $this->assertSame('2026-03-05T10:00:00Z', $result[0]['created_at']);
        $this->assertSame('10.00', $result[0]['shipping_lines'][0]['price']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(
            'status:any (fulfillment_status:fulfilled OR fulfillment_status:partial) updated_at:>=2026-07-01T00:00:00Z',
            $body['variables']['query']
        );
        $this->assertStringNotContainsString('created_at', $body['variables']['query']);
        $this->assertStringContainsString('shippingLines(first: 250)', $body['query']);
        $this->assertStringNotContainsString('fulfillments(first: 250)', $body['query']);
    }

    public function testFetchOrdersForHighValueUsesGraphQLAndUnfulfilledFilter(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/501',
            'legacyResourceId' => '501',
            'name' => '#1501',
            'createdAt' => '2026-06-10T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'vip@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '250.00', 'currencyCode' => 'USD']],
            'shippingAddress' => [
                'firstName' => 'Linus',
                'lastName' => 'Torvalds',
                'name' => 'Linus Torvalds',
                'company' => null,
                'address1' => '1 Kernel Way',
                'address2' => '',
                'city' => 'Portland',
                'province' => 'Oregon',
                'provinceCode' => 'OR',
                'country' => 'United States',
                'countryCodeV2' => 'US',
                'zip' => '97035',
                'phone' => '',
            ],
            'shippingLines' => ['nodes' => []],
        ];
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([$node])], $history);

        $result = $shopify->fetchOrdersForHighValue('2026-06-01', '2026-06-19');

        $this->assertSame(501, $result[0]['id']);
        $this->assertSame('250.00', $result[0]['total_price']);
        $this->assertSame('partial', $result[0]['fulfillment_status']);
        $this->assertSame('US', $result[0]['shipping_address']['country_code']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringEndsWith('/graphql.json', (string) $history[0]['request']->getUri());
        $this->assertStringContainsString('(fulfillment_status:unfulfilled OR fulfillment_status:partial)', $body['variables']['query']);
        $this->assertStringContainsString('shippingAddress', $body['query']);
        $this->assertStringContainsString('shippingLines(first: 250)', $body['query']);
    }

    public function testFetchOrdersForCountryMismatchUsesGraphQLAddresses(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/502',
            'legacyResourceId' => '502',
            'name' => '#1502',
            'createdAt' => '2026-06-11T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'buyer@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '90.00', 'currencyCode' => 'USD']],
            'billingAddress' => ['country' => 'Bulgaria', 'countryCodeV2' => 'BG'],
            'shippingAddress' => ['country' => 'United States', 'countryCodeV2' => 'US'],
        ];
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([$node])], $history);

        $result = $shopify->fetchOrdersForCountryMismatch('2026-06-01', '2026-06-19');

        $this->assertSame('BG', $result[0]['billing_address']['country_code']);
        $this->assertSame('US', $result[0]['shipping_address']['country_code']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('billingAddress', $body['query']);
        $this->assertStringContainsString('shippingAddress', $body['query']);
        $this->assertStringNotContainsString('fulfillment_status', $body['variables']['query']);
    }

    public function testFetchOrdersWithNotesUsesGraphQLAndNormalizesNote(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/503',
            'legacyResourceId' => '503',
            'name' => '#1503',
            'createdAt' => '2026-06-12T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'note@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']],
            'note' => 'Do not ship yet',
        ];
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([$node])], $history);

        $result = $shopify->fetchOrdersWithNotes('2026-06-01', '2026-06-19');

        $this->assertSame('Do not ship yet', $result[0]['note']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('note', $body['query']);
        $this->assertStringContainsString('(fulfillment_status:unfulfilled OR fulfillment_status:partial)', $body['variables']['query']);
    }

    public function testFetchOrdersForAddrDupesUsesGraphQLShippingAddress(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/507',
            'legacyResourceId' => '507',
            'name' => '#1507',
            'createdAt' => '2026-06-16T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'dupe@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '80.00', 'currencyCode' => 'USD']],
            'shippingAddress' => [
                'address1' => '10 Shared St',
                'city' => 'Sofia',
                'zip' => '1000',
                'countryCodeV2' => 'BG',
            ],
        ];
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([$node])], $history);

        $result = $shopify->fetchOrdersForAddrDupes('2026-06-01', '2026-06-19');

        $this->assertSame('10 Shared St', $result[0]['shipping_address']['address1']);
        $this->assertSame('BG', $result[0]['shipping_address']['country_code']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('shippingAddress', $body['query']);
        $this->assertStringNotContainsString('billingAddress', $body['query']);
    }

    public function testFetchCancelledOrdersUsesGraphQLAndFiltersCancelledAt(): void
    {
        $cancelled = [
            'id' => 'gid://shopify/Order/504',
            'legacyResourceId' => '504',
            'name' => '#1504',
            'createdAt' => '2026-06-13T10:00:00Z',
            'cancelledAt' => '2026-06-14T10:00:00Z',
            'cancelReason' => 'CUSTOMER',
            'email' => 'cancelled@example.com',
            'displayFinancialStatus' => 'VOIDED',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '30.00', 'currencyCode' => 'USD']],
        ];
        $open = $cancelled;
        $open['id'] = 'gid://shopify/Order/505';
        $open['legacyResourceId'] = '505';
        $open['cancelledAt'] = null;
        $open['cancelReason'] = null;

        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([$cancelled, $open])], $history);

        $result = $shopify->fetchCancelledOrders('2026-06-01', '2026-06-19');

        $this->assertCount(1, $result);
        $this->assertSame(504, $result[0]['id']);
        $this->assertSame('customer', $result[0]['cancel_reason']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(
            'status:any created_at:>=2026-06-01T00:00:00Z created_at:<=2026-06-19T23:59:59Z',
            $body['variables']['query']
        );
        $this->assertStringContainsString('cancelReason', $body['query']);
    }

    public function testFetchOrdersForTagPolicyUsesGraphQLTags(): void
    {
        $node = [
            'id' => 'gid://shopify/Order/506',
            'legacyResourceId' => '506',
            'name' => '#1506',
            'createdAt' => '2026-06-15T10:00:00Z',
            'cancelledAt' => null,
            'email' => 'tag@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '70.00', 'currencyCode' => 'USD']],
            'tags' => ['vip', 'manual-review'],
        ];
        $history = [];
        $shopify = $this->shopify([$this->graphQLOrders([$node])], $history);

        $result = $shopify->fetchOrdersForTagPolicy('2026-06-01', '2026-06-19');

        $this->assertSame('vip, manual-review', $result[0]['tags']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('tags', $body['query']);
        $this->assertStringContainsString('(financial_status:paid OR financial_status:partially_paid)', $body['variables']['query']);
    }

    // ── Client-side node-filter exclusion ────────────────────────────────────
    //
    // Each of these fetchers passes a node filter to OrderFetcher::fetchOrdersByQuery()
    // as a defense-in-depth check against Shopify's search index returning stale/
    // borderline results. The happy-path tests above only ever feed a single
    // matching node, so the exclusion branch itself was never exercised.

    private function baseGraphQLOrder(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gid://shopify/Order/900', 'legacyResourceId' => '900', 'name' => '#1900',
            'createdAt' => '2026-06-01T10:00:00Z', 'cancelledAt' => null,
            'email' => 'x@example.com', 'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
        ], $overrides);
    }

    public function testFetchRefundedOrdersExcludesNonRefundedNode(): void
    {
        $matching    = $this->baseGraphQLOrder(['legacyResourceId' => '1', 'displayFinancialStatus' => 'REFUNDED', 'refunds' => []]);
        $nonMatching = $this->baseGraphQLOrder(['legacyResourceId' => '2', 'displayFinancialStatus' => 'PAID', 'refunds' => []]);
        $shopify = $this->shopify([$this->graphQLOrders([$matching, $nonMatching])]);

        $result = $shopify->fetchRefundedOrders('2026-06-01', '2026-06-19');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
    }

    public function testFetchPartiallyFulfilledOrdersExcludesFullyFulfilledNode(): void
    {
        $matching    = $this->baseGraphQLOrder(['legacyResourceId' => '1', 'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED', 'lineItems' => ['nodes' => []], 'fulfillments' => []]);
        $nonMatching = $this->baseGraphQLOrder(['legacyResourceId' => '2', 'displayFulfillmentStatus' => 'FULFILLED', 'lineItems' => ['nodes' => []], 'fulfillments' => []]);
        $shopify = $this->shopify([$this->graphQLOrders([$matching, $nonMatching])]);

        $result = $shopify->fetchPartiallyFulfilledOrders('2026-06-01', '2026-06-19');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
    }

    public function testFetchOrdersFulfilledSinceExcludesUnfulfilledNode(): void
    {
        $matching    = $this->baseGraphQLOrder(['legacyResourceId' => '1', 'displayFulfillmentStatus' => 'FULFILLED', 'fulfillments' => []]);
        $nonMatching = $this->baseGraphQLOrder(['legacyResourceId' => '2', 'displayFulfillmentStatus' => 'UNFULFILLED', 'fulfillments' => []]);
        $shopify = $this->shopify([$this->graphQLOrders([$matching, $nonMatching])]);

        $result = $shopify->fetchOrdersFulfilledSince('2026-06-01');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
    }

    public function testFetchOrdersFulfilledSinceWithShippingExcludesUnfulfilledNode(): void
    {
        $matching    = $this->baseGraphQLOrder(['legacyResourceId' => '1', 'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED', 'shippingLines' => ['nodes' => []]]);
        $nonMatching = $this->baseGraphQLOrder(['legacyResourceId' => '2', 'displayFulfillmentStatus' => 'UNFULFILLED', 'shippingLines' => ['nodes' => []]]);
        $shopify = $this->shopify([$this->graphQLOrders([$matching, $nonMatching])]);

        $result = $shopify->fetchOrdersFulfilledSinceWithShipping('2026-06-01');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
    }

    public function testFetchOrdersRefundedSinceExcludesNonRefundedNode(): void
    {
        $matching    = $this->baseGraphQLOrder(['legacyResourceId' => '1', 'displayFinancialStatus' => 'PARTIALLY_REFUNDED', 'refunds' => []]);
        $nonMatching = $this->baseGraphQLOrder(['legacyResourceId' => '2', 'displayFinancialStatus' => 'PAID', 'refunds' => []]);
        $shopify = $this->shopify([$this->graphQLOrders([$matching, $nonMatching])]);

        $result = $shopify->fetchOrdersRefundedSince('2026-06-01');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
    }

    public function testFetchAllProductsUsesGraphQLAndNormalizesRestShape(): void
    {
        $history = [];
        $shopify = $this->shopify([
            $this->json([
                'data' => [
                    'products' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [[
                            'node' => [
                                'id' => 'gid://shopify/Product/101',
                                'legacyResourceId' => '101',
                                'title' => 'Widget',
                                'status' => 'ACTIVE',
                                'descriptionHtml' => '<p>Strong widget</p>',
                                'vendor' => 'ACME',
                                'productType' => 'Tools',
                                'mediaCount' => ['count' => 1],
                                'variants' => [
                                    'edges' => [[
                                        'node' => [
                                            'id' => 'gid://shopify/ProductVariant/301',
                                            'legacyResourceId' => '301',
                                            'title' => 'Default Title',
                                            'sku' => 'WGT-1',
                                            'barcode' => '12345',
                                            'inventoryQuantity' => 7,
                                            'inventoryPolicy' => 'DENY',
                                            'inventoryItem' => ['tracked' => true],
                                        ],
                                    ]],
                                ],
                            ],
                        ]],
                    ],
                ],
            ]),
        ], $history);

        $products = $shopify->fetchAllProducts();

        $this->assertCount(1, $products);
        $this->assertSame(101, $products[0]['id']);
        $this->assertSame('active', $products[0]['status']);
        $this->assertSame('<p>Strong widget</p>', $products[0]['body_html']);
        $this->assertSame('Tools', $products[0]['product_type']);
        $this->assertCount(1, $products[0]['images']);
        $this->assertSame(301, $products[0]['variants'][0]['id']);
        $this->assertSame(101, $products[0]['variants'][0]['product_id']);
        $this->assertSame('WGT-1', $products[0]['variants'][0]['sku']);
        $this->assertSame(7, $products[0]['variants'][0]['inventory_quantity']);
        $this->assertSame('deny', $products[0]['variants'][0]['inventory_policy']);
        $this->assertSame('shopify', $products[0]['variants'][0]['inventory_management']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/graphql.json', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('products(first: 250, query: "status:active"', $body['query']);
    }
}
