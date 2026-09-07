<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyDisputeCandidatesTest extends TestCase
{
    public function test_open_status_query_and_normalization(): void
    {
        Http::fake(['*' => Http::response(['data' => ['disputes' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '1', 'status' => 'NEEDS_RESPONSE', 'initiatedAt' => '2026-06-01', 'evidenceDueBy' => '2026-06-04', 'amount' => ['amount' => '50', 'currencyCode' => 'USD'], 'reasonDetails' => ['reason' => 'FRAUDULENT'], 'order' => ['legacyResourceId' => '1001', 'name' => '#1001']]]]]]])]);
        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->openDisputes(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']));
        $this->assertSame('needs_response', $result['disputes'][0]['status']);
        $this->assertSame('1001', $result['disputes'][0]['order_id']);
        Http::assertSent(fn ($request): bool => $request['variables']['search'] === 'status:NEEDS_RESPONSE OR status:UNDER_REVIEW');
    }
}
