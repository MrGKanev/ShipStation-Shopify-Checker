<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Domain\Reports\SkuDuplicatesAnalyzer;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifySkuDuplicatesCandidatesTest extends TestCase
{
    public function test_fetches_true_images_and_all_variant_pages(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()->push(['data' => ['products' => ['edges' => [['node' => $this->product(true, 'next')]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])->push(['data' => ['product' => ['variants' => ['nodes' => [['sku' => 'SECOND']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])]);
        $result = $this->client()->skuDuplicatesCandidates($this->store());
        $this->assertSame([['sku' => 'FIRST'], ['sku' => 'SECOND']], $result['products'][0]['variants']);
        $this->assertNull(Http::recorded()[0][0]['variables']['search']);
        $this->assertStringContainsString('sku title', (string) Http::recorded()[1][0]['query']);
        Http::assertSentCount(2);
    }

    public function test_rejects_stalled_variant_pagination(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()->push(['data' => ['products' => ['edges' => [['node' => $this->product(true, 'same')]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])->push(['data' => ['product' => ['variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'same']]]]])]);
        $this->expectException(ShopifyGraphqlException::class);
        $this->client()->skuDuplicatesCandidates($this->store());
    }

    public function test_finds_duplicates_across_product_pages_including_drafts_and_archived(): void
    {
        Http::preventStrayRequests();
        $first = array_replace($this->product(false, null), ['status' => 'DRAFT']);
        $second = array_replace($first, ['id' => 'gid://shopify/Product/43', 'legacyResourceId' => '43', 'status' => 'ARCHIVED']);
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
            ->push(['data' => ['products' => ['edges' => [['node' => $first]], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'page2']]]])
            ->push(['data' => ['products' => ['edges' => [['node' => $second]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);

        $catalogue = $this->client()->skuDuplicatesCandidates($this->store());
        $result = (new SkuDuplicatesAnalyzer)->analyze($catalogue['products']);

        $this->assertSame(2, $catalogue['pages']);
        $this->assertFalse($catalogue['truncated']);
        $this->assertSame(2, $result['rows'][0]['count']);
        $this->assertSame(['draft', 'archived'], array_column($result['rows'][0]['variants'], 'product_status'));
        $this->assertSame('page2', Http::recorded()[1][0]['variables']['after']);
        Http::assertSentCount(2);
    }

    public function test_rejects_missing_variant_connection(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['products' => ['edges' => [['node' => ['id' => 'gid://shopify/Product/42']]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);

        $this->expectException(ShopifyGraphqlException::class);
        $this->client()->skuDuplicatesCandidates($this->store());
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
