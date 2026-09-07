<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyDiscountAbuseCandidatesTest extends TestCase
{
    public function test_paid_range_query_normalizes_only_code_applications(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '42', 'name' => '#1', 'createdAt' => '2026-09-02', 'email' => 'a@x.com', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'totalPriceSet' => ['shopMoney' => ['amount' => '50', 'currencyCode' => 'USD']], 'shippingAddress' => ['address1' => '1 Main', 'city' => 'Austin', 'zip' => '78701', 'countryCodeV2' => 'US'], 'discountApplications' => ['nodes' => [['__typename' => 'DiscountCodeApplication', 'code' => 'SAVE10'], ['__typename' => 'AutomaticDiscountApplication']]]]]]]]])]);
        $result = $this->client()->discountAbuseCandidates($this->store(), '2026-09-01', '2026-09-07');

        $this->assertSame([['code' => 'SAVE10']], $result['orders'][0]['discount_codes']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any financial_status:paid created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z' && str_contains((string) $request['query'], 'DiscountCodeApplication'));
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
