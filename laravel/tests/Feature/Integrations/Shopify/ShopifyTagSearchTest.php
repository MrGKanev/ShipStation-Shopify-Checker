<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyTagSearchTest extends TestCase
{
    public function test_uses_a_search_variable_date_bounds_and_normalizes_multiple_pages(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push($this->response([['node' => $this->node(2, '#1002', ['VIP "Plus"'])]], true, 'next'))
                ->push($this->response([['node' => $this->node(1, '#1001', ['VIP "Plus"'])]], false, null)),
        ]);

        $result = $this->client()->searchOrdersByTag($this->store(), 'VIP "Plus"', '2026-09-01', '2026-09-06');

        $this->assertSame(['#1002', '#1001'], array_column($result['orders'], 'name'));
        $this->assertSame(2, $result['pages']);
        $this->assertFalse($result['truncated']);
        $this->assertSame([
            ['search' => 'tag:"VIP \\"Plus\\"" created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-06T23:59:59Z', 'after' => null],
            ['search' => 'tag:"VIP \\"Plus\\"" created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-06T23:59:59Z', 'after' => 'next'],
        ], Http::recorded()->map(fn (array $record): array => $record[0]->data()['variables'])->all());
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'query SearchOrdersByTag($search: String!') && ! str_contains((string) $request['query'], 'VIP'));
    }

    public function test_reports_truncation_at_the_twentieth_page(): void
    {
        Http::preventStrayRequests();
        $count = 0;
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => function () use (&$count): mixed {
            $count++;

            return Http::response($this->response([], true, 'cursor-'.$count));
        }]);

        $result = $this->client()->searchOrdersByTag($this->store(), 'vip');

        $this->assertSame(20, $result['pages']);
        $this->assertTrue($result['truncated']);
        Http::assertSentCount(20);
    }

    public function test_rejects_a_malformed_order_node(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->response([['node' => 'bad']], false, null))]);

        $this->expectException(ShopifyGraphqlException::class);

        $this->client()->searchOrdersByTag($this->store(), 'vip');
    }

    private function client(): ShopifyAdminClient
    {
        return new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
    }

    private function store(): Store
    {
        return new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'shpat_test-token']);
    }

    private function response(array $edges, bool $hasNextPage, ?string $cursor): array
    {
        return ['data' => ['orders' => ['edges' => $edges, 'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $cursor]]]];
    }

    private function node(int $id, string $name, array $tags): array
    {
        return ['id' => 'gid://shopify/Order/'.$id, 'legacyResourceId' => (string) $id, 'name' => $name, 'createdAt' => '2026-09-01T10:00:00Z', 'email' => 'buyer@example.com', 'tags' => $tags, 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'EUR']]];
    }
}
