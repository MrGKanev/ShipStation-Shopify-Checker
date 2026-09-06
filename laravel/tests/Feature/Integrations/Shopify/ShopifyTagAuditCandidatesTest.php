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

class ShopifyTagAuditCandidatesTest extends TestCase
{
    public function test_uses_date_variables_minimal_fields_and_returns_pagination_metadata(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->response([
            ['node' => ['name' => '#1001', 'createdAt' => '2026-09-01T10:00:00Z', 'tags' => ['VIP']]],
        ], false, null))]);

        $result = $this->client()->tagAuditCandidates($this->store(), '2026-09-01', '2026-09-06');

        $this->assertSame('#1001', $result['orders'][0]['name']);
        $this->assertSame(1, $result['pages']);
        $this->assertFalse($result['truncated']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-06T23:59:59Z'
            && str_contains((string) $request['query'], 'name createdAt tags')
            && ! str_contains((string) $request['query'], 'email'));
    }

    public function test_reports_truncation_at_the_hundredth_page(): void
    {
        Http::preventStrayRequests();
        $count = 0;
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => function () use (&$count): mixed {
            $count++;

            return Http::response($this->response([], true, 'cursor-'.$count));
        }]);

        $result = $this->client()->tagAuditCandidates($this->store(), '2026-09-01', '2026-09-06');

        $this->assertSame(100, $result['pages']);
        $this->assertTrue($result['truncated']);
        Http::assertSentCount(100);
    }

    public function test_rejects_a_malformed_order_node(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->response([['node' => 'bad']], false, null))]);
        $this->expectException(ShopifyGraphqlException::class);

        $this->client()->tagAuditCandidates($this->store(), '2026-09-01', '2026-09-06');
    }

    private function client(): ShopifyAdminClient
    {
        return new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
    }

    private function store(): Store
    {
        return new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'shpat_test-token']);
    }

    /** @param list<array<string, mixed>> $edges */
    private function response(array $edges, bool $hasNextPage, ?string $cursor): array
    {
        return ['data' => ['orders' => ['edges' => $edges, 'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $cursor]]]];
    }
}
