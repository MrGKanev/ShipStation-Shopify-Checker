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
    public function test_returned_items_query_uses_refund_update_window_without_order_creation_limit(): void
    {
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => []]]])]);

        $client = new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
        $client->returnedItemCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-07-01');

        Http::assertSent(fn (Request $request): bool => str_contains($request['variables']['search'], 'updated_at:>=2026-07-01T00:00:00Z') && ! str_contains($request['variables']['search'], 'created_at:'));
    }

    public function test_query_normalizes_successful_refund_transactions(): void
    {
        Http::fake(['*' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => [['node' => ['legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2026-01-01', 'email' => 'a@x.com', 'displayFinancialStatus' => 'PARTIALLY_REFUNDED', 'totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']], 'refunds' => [['createdAt' => '2026-01-05T10:00:00Z', 'note' => 'Damaged', 'totalRefundedSet' => ['shopMoney' => ['amount' => '12.50', 'currencyCode' => 'USD']], 'refundLineItems' => ['nodes' => [['quantity' => 2, 'subtotalSet' => ['shopMoney' => ['amount' => '12.50', 'currencyCode' => 'USD']], 'lineItem' => ['name' => 'Widget', 'sku' => 'SKU-1']]]], 'transactions' => ['nodes' => [['kind' => 'REFUND', 'status' => 'SUCCESS', 'amountSet' => ['shopMoney' => ['amount' => '12.50', 'currencyCode' => 'USD']]]]]]]]]]]]])]);
        $client = new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
        $result = $client->repeatRefundCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-01-01', '2026-01-31');
        $this->assertSame(12.5, $result['orders'][0]['refunds'][0]['transactions'][0]['amount']);
        $this->assertSame(12.5, $result['orders'][0]['refunds'][0]['refund_line_items'][0]['subtotal']);
        $this->assertSame(2, $result['orders'][0]['refunds'][0]['refund_line_items'][0]['quantity']);
        $this->assertSame('SKU-1', $result['orders'][0]['refunds'][0]['refund_line_items'][0]['line_item']['sku']);
        $this->assertSame('Damaged', $result['orders'][0]['refunds'][0]['note']);
        $this->assertSame(12.5, $result['orders'][0]['refunds'][0]['total_refunded']);
        $this->assertSame('50.00', $result['orders'][0]['total_price']);
        Http::assertSent(fn (Request $request): bool => str_contains($request['variables']['search'], 'financial_status:partially_refunded') && str_contains((string) $request['query'], 'refundLineItems'));
    }
}
