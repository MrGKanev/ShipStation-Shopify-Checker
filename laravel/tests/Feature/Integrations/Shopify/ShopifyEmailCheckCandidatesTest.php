<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyEmailCheckCandidatesTest extends TestCase
{
    public function test_candidates_use_paid_range_and_normalize_email_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '42', 'name' => '#1001', 'createdAt' => '2026-09-02', 'email' => 'Buyer@Example.com', 'displayFinancialStatus' => 'PAID']]]]]])]);

        $result = $this->client()->emailCheckCandidates($this->store(), '2026-09-01', '2026-09-07');

        $this->assertSame('Buyer@Example.com', $result['orders'][0]['email']);
        $this->assertSame('paid', $result['orders'][0]['financial_status']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any financial_status:paid created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z'
            && str_contains((string) $request['query'], 'EmailCheckCandidates')
            && str_contains((string) $request['query'], 'email'));
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
