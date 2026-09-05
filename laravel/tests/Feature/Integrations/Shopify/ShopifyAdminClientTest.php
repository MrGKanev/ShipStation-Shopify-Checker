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
        ]], $orders);
        Http::assertSent(fn (Request $request): bool => $request['variables'] === ['query' => 'name:65075']
            && str_contains((string) $request['query'], 'query FindOrderByName'));
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
