<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyRepeatRefundCandidatesTest extends TestCase
{
    public function test_query_normalizes_successful_refund_transactions(): void
    {
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2026-01-01', 'email' => 'a@x.com', 'displayFinancialStatus' => 'PARTIALLY_REFUNDED', 'refunds' => [['transactions' => ['nodes' => [['kind' => 'REFUND', 'status' => 'SUCCESS', 'amountSet' => ['shopMoney' => ['amount' => '12.50', 'currencyCode' => 'USD']]]]]]]]]]]]])]);
        $client = new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
        $result = $client->repeatRefundCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-01-01', '2026-01-31');
        $this->assertSame(12.5, $result['orders'][0]['refunds'][0]['transactions'][0]['amount']);
        Http::assertSent(fn (Request $request): bool => str_contains($request['variables']['search'], 'financial_status:partially_refunded') && str_contains((string) $request['query'], 'refunds'));
    }
}
