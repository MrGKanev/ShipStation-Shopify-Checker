<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyTagPolicyTest extends TestCase
{
    public function test_paid_range_query_normalizes_tags_and_order_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '42', 'name' => '#1', 'createdAt' => '2026-09-02', 'email' => 'a@x.com', 'tags' => ['vip'], 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'totalPriceSet' => ['shopMoney' => ['amount' => '50', 'currencyCode' => 'USD']]]]]]]])]);
        $client = new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
        $result = $client->tagPolicyCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-09-01', '2026-09-07');

        $this->assertSame(['vip'], $result['orders'][0]['tags']);
        $this->assertSame('USD', $result['orders'][0]['currency']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any financial_status:paid created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z' && str_contains((string) $request['query'], 'tags'));
    }
}
