<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyNoteFlagCandidatesTest extends TestCase
{
    public function test_query_is_paid_unfulfilled_and_normalizes_note(): void
    {
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2026-01-01', 'note' => 'hold', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED']]]]]])]);
        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->noteFlagCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-01-01', '2026-01-02');
        $this->assertSame('hold', $result['orders'][0]['note']);
        Http::assertSent(fn ($request): bool => str_contains($request['variables']['search'], 'financial_status:paid fulfillment_status:unfulfilled'));
    }
}
