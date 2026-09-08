<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifySameIpCandidatesTest extends TestCase
{
    public function test_paid_range_query_normalizes_client_ip_and_order_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '42', 'name' => '#1', 'createdAt' => '2026-09-02', 'email' => 'a@x.com', 'clientIp' => ' 203.0.113.5 ', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'totalPriceSet' => ['shopMoney' => ['amount' => '50', 'currencyCode' => 'USD']]]]]]]])]);
        $result = $this->client()->sameIpCandidates($this->store(), '2026-09-01', '2026-09-07');

        $this->assertSame('203.0.113.5', $result['orders'][0]['client_ip']);
        $this->assertSame('USD', $result['orders'][0]['currency']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any financial_status:paid created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z' && str_contains((string) $request['query'], 'clientIp'));
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
