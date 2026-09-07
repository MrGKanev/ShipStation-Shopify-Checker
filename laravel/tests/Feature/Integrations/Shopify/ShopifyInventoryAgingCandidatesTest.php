<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyInventoryAgingCandidatesTest extends TestCase
{
    public function test_fetches_active_inventory_and_paid_orders_with_complete_line_items(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
            ->push(['data' => ['products' => ['edges' => [['node' => $this->product()]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])
            ->push(['data' => ['orders' => ['edges' => [['node' => $this->order(true, 'more')]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])
            ->push(['data' => ['order' => ['lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/2', 'sku' => 'AGE-1', 'quantity' => 2]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])]);

        $result = $this->client()->inventoryAgingCandidates($this->store(), '2026-08-01', '2026-09-01');

        $this->assertSame('AGE-1', $result['products'][0]['variants'][0]['sku']);
        $this->assertSame(3, array_sum(array_column($result['orders'][0]['line_items'], 'quantity')));
        $this->assertSame(1, $result['product_pages']);
        $this->assertSame(1, $result['order_pages']);
        $this->assertStringContainsString('status:active', Http::recorded()[0][0]['variables']['search']);
        $this->assertStringContainsString('financial_status:paid', Http::recorded()[1][0]['variables']['search']);
        $this->assertStringContainsString('created_at:>=2026-08-01T00:00:00Z', Http::recorded()[1][0]['variables']['search']);
        $this->assertStringContainsString('cancelledAt', (string) Http::recorded()[1][0]['query']);
        Http::assertSentCount(3);
    }

    public function test_rejects_malformed_order_edges(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
            ->push(['data' => ['products' => ['edges' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])
            ->push(['data' => ['orders' => ['edges' => [['node' => null]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);

        $this->expectException(ShopifyGraphqlException::class);
        $this->client()->inventoryAgingCandidates($this->store(), '2026-08-01', '2026-09-01');
    }

    private function client(): ShopifyAdminClient
    {
        return new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
    }

    private function store(): Store
    {
        return new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']);
    }

    private function product(): array
    {
        return ['id' => 'gid://shopify/Product/42', 'legacyResourceId' => '42', 'title' => 'Widget', 'vendor' => 'Acme', 'productType' => 'Gadget', 'status' => 'ACTIVE', 'descriptionHtml' => '', 'images' => ['nodes' => []], 'variants' => ['nodes' => [['sku' => 'AGE-1', 'title' => 'Default', 'inventoryQuantity' => 0, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]];
    }

    private function order(bool $hasNextPage, ?string $cursor): array
    {
        return ['id' => 'gid://shopify/Order/1', 'legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2026-08-10T00:00:00Z', 'displayFinancialStatus' => 'PAID', 'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/1', 'sku' => 'AGE-1', 'quantity' => 1]], 'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $cursor]]];
    }
}
