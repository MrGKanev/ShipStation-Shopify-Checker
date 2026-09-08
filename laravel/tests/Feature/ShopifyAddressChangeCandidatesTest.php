<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyAddressChangeCandidatesTest extends TestCase
{
    public function test_paginates_filters_and_hydrates_unique_address_changed_orders(): void
    {
        $event = fn (string $id, string $message): array => ['id' => 'gid://shopify/Event/'.$id, 'action' => 'EDIT_COMPLETE', 'createdAt' => '2026-01-02', 'message' => $message, 'subjectId' => "gid://shopify/Order/{$id}", 'subjectType' => 'ORDER'];
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['events' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next'], 'edges' => [['node' => $event('1', 'Shipping address was updated')], ['node' => $event('2', 'Line item was edited')]]]]])
            ->push(['data' => ['events' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => $event('1', 'The shipping_address changed')]]]]])
            ->push(['data' => ['nodes' => [['legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2026-01-01', 'shippingAddress' => ['firstName' => 'Jane', 'city' => 'Boston']]]]])]);
        $result = $this->client()->addressChangeCandidates($this->store(), '2026-01-01', '2026-01-31');

        $this->assertCount(2, $result['events']);
        $this->assertSame([1], array_keys($result['orders']));
        $this->assertSame('Jane', $result['orders'][1]['shipping_address']['first_name']);
        $this->assertSame(2, $result['pages']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'AddressChangeEvents') && $request['variables']['search'] === 'created_at:>=2026-01-01T00:00:00Z created_at:<=2026-01-31T23:59:59Z');
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'AddressChangedOrders') && $request['variables']['ids'] === ['gid://shopify/Order/1']);
    }

    public function test_rejects_malformed_event_payload(): void
    {
        Http::fake(['*' => Http::response(['data' => ['events' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => 'bad']]]]])]);
        $this->expectException(ShopifyGraphqlException::class);
        $this->client()->addressChangeCandidates($this->store(), '2026-01-01', '2026-01-31');
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
