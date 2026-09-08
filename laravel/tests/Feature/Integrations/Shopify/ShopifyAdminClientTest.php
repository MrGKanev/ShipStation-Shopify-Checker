<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\Exceptions\ShopifyResponseException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyAdminClientTest extends TestCase
{
    public function test_health_check_returns_shop_and_unique_access_scopes(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => [
                'shop' => ['name' => 'Acme'],
                'currentAppInstallation' => ['accessScopes' => [
                    ['handle' => 'read_orders'],
                    ['handle' => 'read_fulfillments'],
                    ['handle' => 'read_orders'],
                ]],
            ]], 200, ['X-Shopify-API-Version' => '2026-07']),
        ]);

        $this->assertSame([
            'shop_name' => 'Acme',
            'scopes' => ['read_orders', 'read_fulfillments'],
            'requested_version' => '2026-07',
            'returned_version' => '2026-07',
        ], $this->client()->healthCheck($this->store()));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'currentAppInstallation')
            && str_contains((string) $request['query'], 'accessScopes { handle }'));
    }

    public function test_returns_graphql_data_with_store_authentication_and_versioned_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['shop' => ['name' => 'Acme']],
            ]),
        ]);

        $result = $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');

        $this->assertSame(['data' => ['shop' => ['name' => 'Acme']]], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://acme.myshopify.com/admin/api/2026-07/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'shpat_test-token')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_finds_an_order_number_and_returns_the_legacy_order_shape(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'orders' => [
                        'edges' => [[
                            'node' => [
                                'id' => 'gid://shopify/Order/123456789',
                                'legacyResourceId' => '123456789',
                                'name' => '#65075',
                                'createdAt' => '2026-08-30T10:15:00Z',
                                'cancelledAt' => null,
                                'email' => 'buyer@example.com',
                                'displayFinancialStatus' => 'PAID',
                                'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
                                'totalPriceSet' => ['shopMoney' => ['amount' => '129.90', 'currencyCode' => 'EUR']],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $orders = $this->client()->findByOrderNumber($this->store(), '#65075');

        $this->assertSame([[
            'id' => 123456789,
            'order_number' => 65075,
            'name' => '#65075',
            'created_at' => '2026-08-30T10:15:00Z',
            'cancelled_at' => null,
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'partial',
            'total_price' => '129.90',
            'admin_graphql_api_id' => 'gid://shopify/Order/123456789',
            'currency' => 'EUR',
        ]], $orders);
        Http::assertSent(fn (Request $request): bool => $request['variables'] === ['query' => 'name:65075']
            && str_contains((string) $request['query'], 'query FindOrderByName')
            && str_contains((string) $request['query'], 'shippingAddress')
            && str_contains((string) $request['query'], 'billingAddress')
            && str_contains((string) $request['query'], 'note')
            && str_contains((string) $request['query'], 'tags')
            && str_contains((string) $request['query'], 'lineItems(first: 250)')
            && str_contains((string) $request['query'], 'fulfillments(first: 250)')
            && str_contains((string) $request['query'], 'processedAt')
            && str_contains((string) $request['query'], 'closedAt')
            && str_contains((string) $request['query'], 'cancelReason')
            && str_contains((string) $request['query'], 'risk {')
            && str_contains((string) $request['query'], 'assessments { riskLevel }')
            && str_contains((string) $request['query'], 'fulfillmentLineItems(first: 250)')
            && str_contains((string) $request['query'], 'transactions(first: 250)'));
    }

    public function test_empty_batch_returns_without_an_external_request(): void
    {
        Http::preventStrayRequests();

        $empty = $this->client()->findByOrderNumbers($this->store(), []);
        $blank = $this->client()->findByOrderNumbers($this->store(), ['', '  ', '#']);

        $this->assertSame([], $empty);
        $this->assertSame([], $blank);
        Http::assertNothingSent();
    }

    public function test_batch_lookup_deduplicates_inputs_and_keys_exact_matches_and_misses(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [
                        ['node' => [
                            'id' => 'gid://shopify/Order/1',
                            'legacyResourceId' => '1',
                            'name' => '#1001',
                            'createdAt' => '2026-09-01T10:00:00Z',
                            'cancelledAt' => null,
                            'email' => 'buyer@example.com',
                            'tags' => ['vip'],
                            'displayFinancialStatus' => 'PAID',
                            'displayFulfillmentStatus' => 'UNFULFILLED',
                            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00']],
                            'shippingAddress' => [
                                'address1' => '1 Main Street',
                                'country' => 'Bulgaria',
                                'countryCodeV2' => 'BG',
                                'phone' => '+359888123456',
                            ],
                            'billingAddress' => [
                                'country' => 'Bulgaria',
                                'countryCodeV2' => 'BG',
                            ],
                            'risk' => [
                                'recommendation' => 'CANCEL',
                                'assessments' => [['riskLevel' => 'HIGH']],
                            ],
                        ]],
                        ['node' => [
                            'id' => 'gid://shopify/Order/999',
                            'legacyResourceId' => '999',
                            'name' => '#10010',
                        ]],
                    ],
                ]],
            ]),
        ]);

        $result = $this->client()->findByOrderNumbers(
            $this->store(),
            ['#1001', '1001', ' #1002 ', ''],
        );

        $this->assertSame([1001, 1002], array_keys($result));
        $this->assertSame('#1001', $result['1001'][0]['name']);
        $this->assertSame(['vip'], $result['1001'][0]['tags']);
        $this->assertSame('BG', $result['1001'][0]['shipping_address']['country_code']);
        $this->assertSame('BG', $result['1001'][0]['billing_address']['country_code']);
        $this->assertSame('HIGH', $result['1001'][0]['risk_level']);
        $this->assertSame([], $result['1002']);
        Http::assertSent(fn (Request $request): bool => $request['variables'] === [
            'query' => '(name:1001 OR name:1002)',
            'after' => null,
        ]
            && str_contains((string) $request['query'], 'query FindOrdersByNames')
            && str_contains((string) $request['query'], 'tags')
            && str_contains((string) $request['query'], 'shippingAddress')
            && str_contains((string) $request['query'], 'billingAddress')
            && str_contains((string) $request['query'], 'risk {')
            && ! str_contains((string) $request['query'], 'lineItems'));
    }

    public function test_batch_lookup_preserves_multiple_exact_matches_as_ambiguous(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [
                        ['node' => ['id' => 'gid://shopify/Order/2', 'name' => '#1001']],
                        ['node' => ['id' => 'gid://shopify/Order/1', 'name' => '#1001']],
                    ],
                ]],
            ]),
        ]);

        $result = $this->client()->findByOrderNumbers($this->store(), ['1001']);

        $this->assertSame([2, 1], array_column($result['1001'], 'id'));
        Http::assertSentCount(1);
    }

    public function test_batch_lookup_follows_the_orders_cursor(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'orders-cursor-1'],
                    'edges' => [['node' => ['id' => 'gid://shopify/Order/1', 'name' => '#1001']]],
                ]]])
                ->push(['data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['node' => ['id' => 'gid://shopify/Order/2', 'name' => '#1002']]],
                ]]]),
        ]);

        $result = $this->client()->findByOrderNumbers($this->store(), ['1001', '1002']);

        $this->assertSame('#1001', $result['1001'][0]['name']);
        $this->assertSame('#1002', $result['1002'][0]['name']);
        $this->assertSame([
            ['query' => '(name:1001 OR name:1002)', 'after' => null],
            ['query' => '(name:1001 OR name:1002)', 'after' => 'orders-cursor-1'],
        ], Http::recorded()->map(
            fn (array $record): array => $record[0]->data()['variables'],
        )->all());
        Http::assertSentCount(2);
    }

    public function test_batch_lookup_throws_instead_of_returning_truncated_matches(): void
    {
        Http::preventStrayRequests();
        $requestCount = 0;
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => function () use (&$requestCount): mixed {
                $requestCount++;

                return Http::response(['data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => "cursor-{$requestCount}"],
                    'edges' => [],
                ]]]);
            },
        ]);

        try {
            $this->client()->findByOrderNumbers($this->store(), ['1001']);
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify batch order lookup exceeded its page limit.', $exception->getMessage());
        }

        Http::assertSentCount(20);
    }

    public function test_batch_lookup_rejects_a_malformed_returned_name(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['node' => ['id' => 'gid://shopify/Order/1', 'name' => ['#1001']]]],
                ]],
            ]),
        ]);

        try {
            $this->client()->findByOrderNumbers($this->store(), ['1001']);
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify batch order lookup returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_batch_lookup_rejects_invalid_input_before_an_external_request(): void
    {
        Http::preventStrayRequests();

        try {
            $this->client()->findByOrderNumbers($this->store(), ['1001', '1002 OR status:any']);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A Shopify order number is invalid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_batch_lookup_rejects_a_non_string_member_before_an_external_request(): void
    {
        Http::preventStrayRequests();

        try {
            /** @phpstan-ignore argument.type */
            $this->client()->findByOrderNumbers($this->store(), ['1001', ['1002']]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Every Shopify order number must be a string.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_batch_lookup_rejects_more_than_fifty_unique_numbers_before_an_external_request(): void
    {
        Http::preventStrayRequests();
        $orderNumbers = array_map(
            fn (int $number): string => (string) $number,
            range(1001, 1051),
        );

        try {
            $this->client()->findByOrderNumbers($this->store(), $orderNumbers);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Shopify batch lookup accepts at most 50 unique order numbers.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_fetches_every_line_item_page_before_normalizing_an_order(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['orders' => ['edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/Order/123',
                        'name' => '#65075',
                        'lineItems' => [
                            'nodes' => [[
                                'id' => 'gid://shopify/LineItem/1',
                                'sku' => 'FIRST-SKU',
                                'quantity' => 1,
                            ]],
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'line-cursor-1'],
                        ],
                    ],
                ]]]]])
                ->push(['data' => ['order' => ['lineItems' => [
                    'nodes' => [[
                        'id' => 'gid://shopify/LineItem/2',
                        'sku' => 'SECOND-SKU',
                        'quantity' => 2,
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'line-cursor-2'],
                ]]]]),
        ]);

        $orders = $this->client()->findByOrderNumber($this->store(), '65075');

        $this->assertSame(['FIRST-SKU', 'SECOND-SKU'], array_column($orders[0]['line_items'], 'sku'));
        $this->assertSame([1, 2], array_column($orders[0]['line_items'], 'quantity'));
        $this->assertSame([
            ['query' => 'name:65075'],
            ['id' => 'gid://shopify/Order/123', 'after' => 'line-cursor-1'],
        ], Http::recorded()->map(
            fn (array $record): array => $record[0]->data()['variables'],
        )->all());
        Http::assertSentCount(2);
    }

    public function test_throws_when_a_followup_line_item_page_is_malformed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['orders' => ['edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/Order/123',
                        'name' => '#65075',
                        'lineItems' => [
                            'nodes' => [],
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'line-cursor-1'],
                        ],
                    ],
                ]]]]])
                ->push(['data' => ['order' => ['lineItems' => ['nodes' => []]]]]),
        ]);

        try {
            $this->client()->findByOrderNumber($this->store(), '65075');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify line items returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_returns_normalized_order_events_with_a_graphql_order_id(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [[
                        'node' => [
                            '__typename' => 'BasicEvent',
                            'id' => 'gid://shopify/OrderEvent/987',
                            'action' => 'CONFIRMED',
                            'appTitle' => 'Shopify',
                            'createdAt' => '2026-09-05T12:30:00Z',
                            'message' => 'Order confirmed',
                            'subjectId' => 'gid://shopify/Order/123',
                            'subjectType' => 'ORDER',
                        ],
                    ]],
                ]]],
            ]),
        ]);

        $events = $this->client()->getOrderEvents($this->store(), '123');

        $this->assertSame('confirmed', $events[0]['action']);
        $this->assertSame(123, $events[0]['subject_id']);
        $this->assertSame('Order confirmed', $events[0]['message']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://acme.myshopify.com/admin/api/2026-07/graphql.json'
            && $request['variables'] === ['id' => 'gid://shopify/Order/123', 'after' => null]
            && str_contains((string) $request['query'], 'events(first: 250, sortKey: CREATED_AT, reverse: true, after: $after)')
            && str_contains((string) $request['query'], '... on BasicEvent'));
    }

    public function test_paginates_order_events_and_preserves_newest_first_api_order(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'event-cursor-1'],
                    'edges' => [['node' => [
                        'id' => 'gid://shopify/OrderEvent/2',
                        'action' => 'PAID',
                        'createdAt' => '2026-09-05T12:30:00Z',
                        'message' => 'Payment processed',
                    ]]],
                ]]]])
                ->push(['data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['node' => [
                        'id' => 'gid://shopify/OrderEvent/1',
                        'action' => 'CONFIRMED',
                        'createdAt' => '2026-09-05T12:00:00Z',
                        'message' => 'Order confirmed',
                    ]]],
                ]]]]),
        ]);

        $events = $this->client()->getOrderEvents($this->store(), 'gid://shopify/Order/123');

        $this->assertSame(['Payment processed', 'Order confirmed'], array_column($events, 'message'));
        $this->assertSame([
            ['id' => 'gid://shopify/Order/123', 'after' => null],
            ['id' => 'gid://shopify/Order/123', 'after' => 'event-cursor-1'],
        ], Http::recorded()->map(
            fn (array $record): array => $record[0]->data()['variables'],
        )->all());
        Http::assertSentCount(2);
    }

    public function test_returns_no_events_when_the_shopify_order_does_not_exist(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => null],
            ]),
        ]);

        $events = $this->client()->getOrderEvents($this->store(), '999999');

        $this->assertSame([], $events);
        Http::assertSentCount(1);
    }

    public function test_throws_when_the_order_events_connection_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => []],
            ]),
        ]);

        try {
            $this->client()->getOrderEvents($this->store(), '123');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order events returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_an_order_event_edge_is_malformed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [['unexpected' => []]],
                ]]],
            ]),
        ]);

        try {
            $this->client()->getOrderEvents($this->store(), '123');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order events returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_order_events_claim_another_page_without_a_cursor(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => null],
                    'edges' => [],
                ]]],
            ]),
        ]);

        try {
            $this->client()->getOrderEvents($this->store(), '123');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order events returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_order_event_pagination_repeats_a_cursor(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'stuck-cursor'],
                    'edges' => [],
                ]]]])
                ->push(['data' => ['order' => ['events' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'stuck-cursor'],
                    'edges' => [],
                ]]]]),
        ]);

        try {
            $this->client()->getOrderEvents($this->store(), '123');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order events returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_rejects_an_invalid_order_id_before_requesting_events(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);

        $this->client()->getOrderEvents($this->store(), '123 OR id:*');
    }

    public function test_paginates_graphql_connections_with_cursor_variables(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push([
                    'data' => ['orders' => [
                        'edges' => [['node' => ['id' => 'first']]],
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                    ]],
                ])
                ->push([
                    'data' => ['orders' => [
                        'edges' => [['node' => ['id' => 'second']]],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cursor-2'],
                    ]],
                ]),
        ]);

        $result = $this->client()->paginateGraphql(
            $this->store(),
            'query Orders($after: String) { orders(first: 1, after: $after) { edges { node { id } } } }',
            'orders',
            ['status' => 'open'],
        );

        $this->assertSame([
            'edges' => [
                ['node' => ['id' => 'first']],
                ['node' => ['id' => 'second']],
            ],
            'pages' => 2,
            'truncated' => false,
        ], $result);
        $this->assertSame([
            ['status' => 'open', 'after' => null],
            ['status' => 'open', 'after' => 'cursor-1'],
        ], Http::recorded()->map(
            fn (array $record): array => $record[0]->data()['variables'],
        )->all());
        Http::assertSentCount(2);
    }

    public function test_throws_on_an_unexpected_order_lookup_shape(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => ['nodes' => []]],
            ]),
        ]);

        try {
            $this->client()->findByOrderNumber($this->store(), '65075');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order lookup returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_on_a_malformed_order_lookup_edge_without_returning_partial_results(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => ['edges' => [
                    ['node' => ['name' => '#65075']],
                    ['unexpected' => []],
                ]]],
            ]),
        ]);

        try {
            $this->client()->findByOrderNumber($this->store(), '65075');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify order lookup returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_marks_graphql_pagination_as_truncated_at_the_page_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => [
                    'edges' => [['node' => ['id' => 'first']]],
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                ]],
            ]),
        ]);

        $result = $this->client()->paginateGraphql(
            $this->store(),
            'query Orders($after: String) { orders(first: 1, after: $after) { edges { node { id } } } }',
            'orders',
            maxPages: 1,
        );

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['pages']);
        Http::assertSentCount(1);
    }

    public function test_throws_on_an_unexpected_pagination_shape(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => ['nodes' => []]],
            ]),
        ]);

        $this->expectException(ShopifyGraphqlException::class);

        $this->client()->paginateGraphql(
            $this->store(),
            'query Orders($after: String) { orders(first: 1, after: $after) { edges { node { id } } } }',
            'orders',
        );
    }

    public function test_throws_the_graphql_errors_returned_in_a_successful_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'errors' => [['message' => 'Access denied']],
            ]),
        ]);

        try {
            $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame([['message' => 'Access denied']], $exception->errors());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_graphql_returns_neither_data_nor_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['extensions' => []]),
        ]);

        try {
            $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify GraphQL returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_graphql_returns_invalid_json(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(
                'not-json',
                headers: ['Content-Type' => 'application/json'],
            ),
        ]);

        try {
            $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');
            $this->fail('Expected ShopifyGraphqlException was not thrown.');
        } catch (ShopifyGraphqlException $exception) {
            $this->assertSame('Shopify GraphQL returned an unexpected response shape.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_when_get_returns_invalid_json(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/webhooks.json' => Http::response(
                'not-json',
                headers: ['Content-Type' => 'application/json'],
            ),
        ]);

        try {
            $this->client()->get($this->store(), 'webhooks.json');
            $this->fail('Expected ShopifyResponseException was not thrown.');
        } catch (ShopifyResponseException $exception) {
            $this->assertSame('Shopify returned an invalid JSON response.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_on_a_4xx_get_without_retrying(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/webhooks.json?limit=250' => Http::response([], 401),
        ]);

        try {
            $this->client()->get($this->store(), 'webhooks.json', ['limit' => 250]);
            $this->fail('Expected RequestException was not thrown.');
        } catch (RequestException $exception) {
            $this->assertSame(401, $exception->response->status());
        }

        Http::assertSentCount(1);
    }

    public function test_retries_transient_get_responses_and_returns_the_successful_payload(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/webhooks.json' => Http::sequence()
                ->pushStatus(500)
                ->pushStatus(429)
                ->push(['webhooks' => [['id' => 42]]]),
        ]);

        $result = $this->client()->get($this->store(), 'webhooks.json');

        $this->assertSame(['webhooks' => [['id' => 42]]], $result);
        Http::assertSentCount(3);
        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
            Sleep::for(500)->milliseconds(),
        ]);
    }

    public function test_retries_a_failed_get_connection(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/webhooks.json' => Http::sequence()
                ->pushFailedConnection()
                ->push(['webhooks' => []]),
        ]);

        $result = $this->client()->get($this->store(), 'webhooks.json');

        $this->assertSame(['webhooks' => []], $result);
        Http::assertSentCount(2);
        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
        ]);
    }

    public function test_does_not_retry_a_graphql_post_after_a_server_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->pushStatus(500)
                ->push(['data' => ['shop' => ['name' => 'Unexpected retry']]]),
        ]);

        try {
            $this->client()->graphql($this->store(), 'query ShopName { shop { name } }');
            $this->fail('Expected RequestException was not thrown.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        Http::assertSentCount(1);
    }

    #[DataProvider('invalidResourcePaths')]
    public function test_rejects_resource_paths_that_can_escape_the_versioned_api_base(string $resource): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);

        $this->client()->get($this->store(), $resource);
    }

    public function test_rejects_an_order_number_that_can_change_the_search_query(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);

        $this->client()->findByOrderNumber($this->store(), '65075 OR status:any');
    }

    public function test_rejects_a_missing_access_token(): void
    {
        Http::preventStrayRequests();
        $store = Store::factory()->make([
            'shopify_store' => 'acme',
            'shopify_access_token' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->client()->graphql($store, 'query ShopName { shop { name } }');
    }

    private function client(): ShopifyAdminClient
    {
        return app(ShopifyAdminClient::class);
    }

    private function store(): Store
    {
        return Store::factory()->make([
            'shopify_store' => 'acme',
            'shopify_access_token' => 'shpat_test-token',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidResourcePaths(): array
    {
        return [
            'absolute URL' => ['https://attacker.example/credentials'],
            'parent traversal' => ['../credentials'],
            'nested parent traversal' => ['webhooks/../../credentials'],
            'protocol-relative URL' => ['//attacker.example/credentials'],
        ];
    }
}
