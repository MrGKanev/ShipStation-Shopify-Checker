<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyAdminClient;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use App\Integrations\Shopify\ShopifyOrderNormalizer;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyCountryMismatchCandidatesTest extends TestCase
{
    public function test_uses_paid_date_query_and_only_required_address_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => []]]])]);
        $result = (new ShopifyAdminClient(new ShopifyOrderNormalizer, new ShopifyOrderEventNormalizer))->countryMismatchCandidates(new Store(['shopify_store' => 'acme', 'shopify_access_token' => 'token']), '2026-09-01', '2026-09-06');
        $this->assertSame(['orders' => [], 'pages' => 1, 'truncated' => false], $result);
        Http::assertSent(fn (Request $request): bool => str_contains($request['variables']['search'], 'financial_status:partially_paid') && str_contains((string) $request['query'], 'billingAddress') && str_contains((string) $request['query'], 'shippingAddress') && ! str_contains((string) $request['query'], 'address1'));
    }
}
