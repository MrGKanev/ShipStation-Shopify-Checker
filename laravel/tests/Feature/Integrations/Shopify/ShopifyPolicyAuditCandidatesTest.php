<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyPolicyAuditCandidatesTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_tax_candidates_use_paid_range_and_normalize_tax_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $this->node(['totalTaxSet' => ['shopMoney' => ['amount' => '0.00']], 'customer' => ['taxExempt' => false]])]]]]])]);
        $result = $this->client()->taxAuditCandidates($this->store(), '2026-09-01', '2026-09-07');
        $this->assertSame('0.00', $result['orders'][0]['total_tax']);
        $this->assertFalse($result['orders'][0]['customer_tax_exempt']);
        Http::assertSent(fn (Request $request): bool => $request['variables']['search'] === 'status:any financial_status:paid created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z' && str_contains((string) $request['query'], 'totalTaxSet'));
    }

    public function test_consent_candidates_normalize_marketing_states(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $this->node(['customer' => ['emailMarketingConsent' => ['marketingState' => 'SUBSCRIBED'], 'smsMarketingConsent' => ['marketingState' => 'NOT_SUBSCRIBED']]])]]]]])]);
        $result = $this->client()->consentAuditCandidates($this->store(), '2026-09-01', '2026-09-07');
        $this->assertSame('subscribed', $result['orders'][0]['customer_email_consent']);
        $this->assertSame('not_subscribed', $result['orders'][0]['customer_sms_consent']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'emailMarketingConsent') && str_contains((string) $request['query'], 'smsMarketingConsent'));
    }

    private function node(array $extra): array
    {
        return [...['legacyResourceId' => '42', 'name' => '#1001', 'createdAt' => '2026-09-02', 'email' => 'a@example.com', 'displayFinancialStatus' => 'PAID', 'totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']]], ...$extra];
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
