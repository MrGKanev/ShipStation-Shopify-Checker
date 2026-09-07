<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyFraudRiskCandidatesTest extends TestCase
{
    public function test_candidates_use_paid_range_and_normalize_every_risk_input(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => [
            'legacyResourceId' => '42', 'name' => '#1001', 'createdAt' => '2026-09-02', 'email' => 'buyer@example.com', 'tags' => ['high-risk'], 'displayFinancialStatus' => 'PAID', 'totalPriceSet' => ['shopMoney' => ['amount' => '250.00', 'currencyCode' => 'USD']],
            'billingAddress' => ['country' => 'United States', 'countryCodeV2' => 'US'], 'shippingAddress' => ['address1' => 'PO Box 3', 'country' => 'Canada', 'countryCodeV2' => 'CA', 'phone' => ''], 'risk' => ['recommendation' => 'CANCEL', 'assessments' => [['riskLevel' => 'HIGH']]],
        ]]]]]])]);

        $result = $this->client()->fraudRiskCandidates($this->store(), '2026-09-01', '2026-09-07');

        $order = $result['orders'][0];
        $this->assertSame(['high-risk'], $order['tags']);
        $this->assertSame('US', $order['billing_address']['country_code']);
        $this->assertSame('CA', $order['shipping_address']['country_code']);
        $this->assertSame('HIGH', $order['risk_level']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any financial_status:paid created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z'
            && str_contains((string) $request['query'], 'tags')
            && str_contains((string) $request['query'], 'billingAddress')
            && str_contains((string) $request['query'], 'shippingAddress')
            && str_contains((string) $request['query'], 'assessments { riskLevel }'));
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
