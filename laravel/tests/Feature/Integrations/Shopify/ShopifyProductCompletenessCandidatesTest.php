<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyProductCompletenessCandidatesTest extends TestCase
{
    public function test_fetches_true_images_and_all_variant_pages(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()->push(['data' => ['products' => ['edges' => [['node' => $this->product(true, 'next')]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])->push(['data' => ['product' => ['variants' => ['nodes' => [['sku' => 'SECOND']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])]);
        $result = $this->client()->productCompletenessCandidates($this->store());
        $this->assertSame([['sku' => 'FIRST'], ['sku' => 'SECOND']], $result['products'][0]['variants']);
        $this->assertStringContainsString('images(first: 1)', (string) Http::recorded()[0][0]['query']);
    }

    public function test_rejects_stalled_variant_pagination(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()->push(['data' => ['products' => ['edges' => [['node' => $this->product(true, 'same')]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])->push(['data' => ['product' => ['variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'same']]]]])]);
        $this->expectException(ShopifyGraphqlException::class);
        $this->client()->productCompletenessCandidates($this->store());
    }

    public function test_zombie_candidates_use_active_catalogue_with_inventory_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['products' => ['edges' => [['node' => $this->product(false, null)]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);

        $result = $this->client()->zombieProductsCandidates($this->store());

        $this->assertCount(1, $result['products']);
        $this->assertSame('status:active', Http::recorded()[0][0]['variables']['search']);
        $this->assertStringContainsString('inventoryQuantity inventoryPolicy inventoryItem { tracked }', (string) Http::recorded()[0][0]['query']);
        Http::assertSentCount(1);
    }

    public function test_catalog_quality_candidates_request_discovery_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['products' => ['edges' => [['node' => $this->product(false, null)]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);

        $result = $this->client()->catalogQualityCandidates($this->store());

        $this->assertCount(1, $result['products']);
        $this->assertSame('status:active', Http::recorded()[0][0]['variables']['search']);
        $this->assertStringContainsString('onlineStoreUrl', (string) Http::recorded()[0][0]['query']);
        $this->assertStringContainsString('seo { title description }', (string) Http::recorded()[0][0]['query']);
        $this->assertStringContainsString('collections(first: 1)', (string) Http::recorded()[0][0]['query']);
        Http::assertSentCount(1);
    }

    private function client(): ShopifyAdminClient
    {
        return new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
    }

    private function store(): Store
    {
        return new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']);
    }

    private function product(bool $next, ?string $cursor): array
    {
        return ['id' => 'gid://shopify/Product/42', 'legacyResourceId' => '42', 'title' => 'Widget', 'vendor' => 'Acme', 'productType' => 'Gadget', 'status' => 'ACTIVE', 'descriptionHtml' => '<p>Useful</p>', 'images' => ['nodes' => [['id' => 'image']]], 'variants' => ['nodes' => [['sku' => 'FIRST']], 'pageInfo' => ['hasNextPage' => $next, 'endCursor' => $cursor]]];
    }
}
