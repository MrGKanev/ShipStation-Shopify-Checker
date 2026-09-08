<?php

namespace Tests\Feature;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOrderEditCandidatesTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_paginates_events_and_batch_hydrates_unique_edited_orders(): void
    {
        $event = fn (string $id): array => ['id' => 'gid://shopify/Event/1', 'action' => 'EDIT_COMPLETE', 'createdAt' => '2026-01-02', 'message' => 'item changed', 'subjectId' => "gid://shopify/Order/{$id}", 'subjectType' => 'ORDER'];
        Http::fake(['*' => Http::sequence()->push(['data' => ['events' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next'], 'edges' => [['node' => $event('1')], ['node' => $event('1')]]]]])->push(['data' => ['events' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $event('2')]]]]])->push(['data' => ['nodes' => [['legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2026-01-01'], ['legacyResourceId' => '2', 'name' => '#2', 'createdAt' => '2026-01-01']]]])]);
        $client = new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
        $result = $client->orderEditCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-01-01', '2026-01-31');
        $this->assertCount(3, $result['events']);
        $this->assertSame([1, 2], array_keys($result['orders']));
        $this->assertSame(2, $result['pages']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'nodes(ids: $ids)') && $request['variables']['ids'] === ['gid://shopify/Order/1', 'gid://shopify/Order/2']);
    }
}
