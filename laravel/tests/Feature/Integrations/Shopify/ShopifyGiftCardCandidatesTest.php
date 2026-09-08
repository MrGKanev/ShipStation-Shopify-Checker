<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyGiftCardCandidatesTest extends TestCase
{
    public function test_paginates_and_normalizes_gift_cards(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
            ->push(['data' => ['giftCards' => ['edges' => [['node' => $this->card('****1111')]], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next']]]])
            ->push(['data' => ['giftCards' => ['edges' => [['node' => $this->card('****2222')]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);

        $result = $this->client()->giftCardCandidates($this->store());

        $this->assertSame(['****1111', '****2222'], array_column($result['gift_cards'], 'masked_code'));
        $this->assertSame(25.5, $result['gift_cards'][0]['balance']);
        $this->assertSame('USD', $result['gift_cards'][0]['currency']);
        $this->assertSame(2, $result['pages']);
        $this->assertFalse($result['truncated']);
        $this->assertSame('next', Http::recorded()[1][0]['variables']['after']);
        Http::assertSentCount(2);
    }

    public function test_rejects_malformed_card_edge(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['giftCards' => ['edges' => [['node' => null]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]])]);

        $this->expectException(ShopifyGraphqlException::class);
        $this->client()->giftCardCandidates($this->store());
    }

    private function client(): ShopifyAdminClient
    {
        return new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer);
    }

    private function store(): Store
    {
        return new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']);
    }

    private function card(string $code): array
    {
        return ['id' => 'gid://shopify/GiftCard/1', 'maskedCode' => $code, 'balance' => ['amount' => '25.50', 'currencyCode' => 'USD'], 'initialValue' => ['amount' => '50.00', 'currencyCode' => 'USD'], 'expiresOn' => '2026-12-31', 'enabled' => true, 'createdAt' => '2026-01-01T00:00:00Z', 'customer' => ['email' => 'a@example.com']];
    }
}
