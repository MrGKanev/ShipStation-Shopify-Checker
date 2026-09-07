<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyAddressCheckCandidatesTest extends TestCase
{
    public function test_query_filters_unfulfilled_and_normalizes_address_and_shipping_lines(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '42', 'name' => '#1', 'createdAt' => '2026-09-02', 'email' => 'a@example.com', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'shippingAddress' => ['firstName' => 'Jane', 'lastName' => 'Doe', 'address1' => 'PO Box 2', 'city' => 'Boston', 'provinceCode' => 'MA', 'zip' => '02101', 'countryCodeV2' => 'US', 'phone' => ''], 'shippingLines' => ['nodes' => [['title' => 'FedEx Ground']]]]]]]]])]);
        $result = $this->client()->addressCheckCandidates($this->store(), '2026-09-01', '2026-09-07', true);

        $this->assertSame('PO Box 2', $result['orders'][0]['shipping_address']['address1']);
        $this->assertSame('FedEx Ground', $result['orders'][0]['shipping_lines'][0]['title']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any financial_status:paid created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z fulfillment_status:unfulfilled' && str_contains((string) $request['query'], 'shippingLines'));
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
