<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyHighValueCandidatesTest extends TestCase
{
    public function test_queries_required_candidate_statuses_and_minimal_fields_with_variables(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => []]]])]);

        $result = $this->client()->highValueOrderCandidates($this->store(), '2026-09-01', '2026-09-06');

        $this->assertSame(['orders' => [], 'pages' => 1, 'truncated' => false], $result);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any (financial_status:paid OR financial_status:partially_paid) (fulfillment_status:unfulfilled OR fulfillment_status:partial) created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-06T23:59:59Z'
            && str_contains((string) $request['query'], 'shippingAddress') && str_contains((string) $request['query'], 'cancelledAt') && ! str_contains((string) $request['query'], 'shippingLines'));
    }

    private function client(): ShopifyAdminClient
    {
        return new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
    }

    private function store(): Store
    {
        return new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']);
    }
}
