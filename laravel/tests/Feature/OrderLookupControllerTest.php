<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderLookupControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('orders.lookup'));

        $response->assertRedirect(route('login'));
    }

    public function test_initial_form_does_not_make_external_requests(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->actingAs($user)->get(route('orders.lookup'));

        $response
            ->assertOk()
            ->assertSeeText('Order lookup');
        Http::assertNothingSent();
    }

    public function test_blank_order_number_is_treated_as_an_empty_search(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->actingAs($user)->get(route('orders.lookup', [
            'order_number' => '   ',
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Order lookup')
            ->assertSessionDoesntHaveErrors();
        Http::assertNothingSent();
    }

    public function test_invalid_order_number_is_rejected_before_external_requests(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->from(route('orders.lookup'))
            ->actingAs($user)
            ->get(route('orders.lookup', ['order_number' => '65075 OR status:any']));

        $response
            ->assertRedirect(route('orders.lookup'))
            ->assertSessionHasErrors([
                'order_number' => 'The order number field format is invalid.',
            ]);
        Http::assertNothingSent();
    }

    public function test_array_order_number_is_rejected_without_causing_a_server_error(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->from(route('orders.lookup'))
            ->actingAs($user)
            ->get(route('orders.lookup', ['order_number' => ['65075']]));

        $response->assertRedirect(route('orders.lookup'))->assertSessionHasErrors('order_number');
        Http::assertNothingSent();
    }

    public function test_viewer_can_lookup_an_order_in_both_integrations_with_active_store_credentials(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->shopifyResponse()),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [[
                    'orderId' => 42,
                    'orderNumber' => '65075',
                    'orderStatus' => 'awaiting_shipment',
                    'customerEmail' => '<script>alert("ss")</script>',
                    'orderTotal' => 129.90,
                ]],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response([
                'shipments' => [[
                    'shipmentId' => 91,
                    'carrierCode' => 'ups',
                    'trackingNumber' => '<script>alert("tracking")</script>',
                ]],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '#65075']));

        $response
            ->assertOk()
            ->assertSeeText('#65075')
            ->assertSeeText('awaiting_shipment')
            ->assertSee('<script>alert("shopify")</script>')
            ->assertDontSee('<script>alert("shopify")</script>', false)
            ->assertSee('<script>alert("ss")</script>')
            ->assertDontSee('<script>alert("ss")</script>', false)
            ->assertSee('<script>alert("tracking")</script>')
            ->assertDontSee('<script>alert("tracking")</script>', false);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://acme.myshopify.com/admin/api/2026-07/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'shopify-active-token'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('shipstation-active-key:shipstation-active-secret'),
        ));
    }

    public function test_lookup_uses_the_selected_store_instead_of_another_accessible_store(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $otherStore = Store::factory()->create([
            'shopify_store' => 'other',
            'shopify_access_token' => 'other-token',
        ]);
        $activeStore = Store::factory()->create([
            'shopify_store' => 'selected',
            'shopify_access_token' => 'selected-token',
            'shipstation_api_key' => null,
            'shipstation_api_secret' => null,
        ]);
        $user->stores()->attach([$otherStore->getKey(), $activeStore->getKey()]);
        Http::fake([
            'https://selected.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->shopifyResponse()),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $activeStore->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://selected.myshopify.com/admin/api/2026-07/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'selected-token'));
    }

    public function test_lookup_explains_when_shipstation_is_not_configured(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore([
            'shipstation_api_key' => null,
            'shipstation_api_secret' => null,
        ]);
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->shopifyResponse()),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('ShipStation credentials are not configured for this store.');
        $response->assertSeeText('Configure ShipStation to enable detailed comparison.');
        Http::assertSentCount(1);
    }

    public function test_matching_order_details_render_a_successful_cross_platform_comparison(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->comparisonShopifyResponse()),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [$this->matchingShipStationOrder()],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response([
                'shipments' => [],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('Detailed comparison')
            ->assertSeeText('SKU quantities match.')
            ->assertSeeText('Shipping address line 1')
            ->assertDontSee('ShipStation is shipped while Shopify is not fully fulfilled.');
        Http::assertSentCount(3);
    }

    public function test_item_and_established_status_differences_are_highlighted(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        $shopifyResponse = $this->comparisonShopifyResponse();
        $shopifyResponse['data']['orders']['edges'][0]['node']['displayFulfillmentStatus'] = 'UNFULFILLED';
        $shipStationOrder = $this->matchingShipStationOrder();
        $shipStationOrder['items'] = [
            ['sku' => 'widget', 'quantity' => 1, 'name' => 'Widget'],
            ['sku' => 'unexpected', 'quantity' => 2, 'name' => 'Unexpected'],
        ];
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($shopifyResponse),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [$shipStationOrder],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => []]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('ShipStation is shipped while Shopify is not fully fulfilled.')
            ->assertSeeText('Missing from ShipStation')
            ->assertSeeText('Extra in ShipStation')
            ->assertSeeText('cable')
            ->assertSeeText('unexpected');
        Http::assertSentCount(3);
    }

    public function test_multiple_shipstation_matches_are_reported_without_selecting_one(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        $firstOrder = $this->matchingShipStationOrder();
        $secondOrder = [...$firstOrder, 'orderId' => 43];
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->comparisonShopifyResponse()),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [$firstOrder, $secondOrder],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => []]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('Multiple matching records were found. No record was selected automatically.')
            ->assertDontSee('SKU quantities match.');
        Http::assertSentCount(3);
    }

    public function test_empty_results_are_shown_without_being_treated_as_an_error(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['orders' => ['edges' => []]],
            ]),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response([
                'shipments' => [],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('No Shopify order found.')
            ->assertSeeText('No ShipStation order found.')
            ->assertSeeText('Comparison unavailable because the order was not found in Shopify.')
            ->assertDontSee('The order lookup could not be completed.');
        Http::assertSentCount(3);
    }

    public function test_missing_shipstation_order_is_a_valid_comparison_state(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->comparisonShopifyResponse()),
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [],
                'pages' => 1,
            ]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => []]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('Comparison unavailable because the order was not found in ShipStation.')
            ->assertDontSee('The order lookup could not be completed.');
        Http::assertSentCount(3);
    }

    public function test_incomplete_shipstation_credentials_return_a_safe_error(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore([
            'shipstation_api_secret' => null,
        ]);
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response($this->shopifyResponse()),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('The order lookup could not be completed.')
            ->assertDontSee('The ShipStation credentials are incomplete.');
        Http::assertSentCount(1);
    }

    public function test_upstream_failure_returns_a_safe_error_without_retrying_graphql(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        Http::fake([
            'https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'message' => 'private upstream details',
            ], 500),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.lookup', ['order_number' => '65075']));

        $response
            ->assertOk()
            ->assertSeeText('The order lookup could not be completed.')
            ->assertDontSee('private upstream details');
        Http::assertSentCount(1);
    }

    /**
     * @param  array<string, mixed>  $storeAttributes
     * @return array{User, Store}
     */
    private function userWithStore(array $storeAttributes = []): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'shopify_store' => 'acme',
            'shopify_access_token' => 'shopify-active-token',
            'shipstation_api_key' => 'shipstation-active-key',
            'shipstation_api_secret' => 'shipstation-active-secret',
            ...$storeAttributes,
        ]);
        $user->stores()->attach($store);

        return [$user, $store];
    }

    /**
     * @return array<string, mixed>
     */
    private function shopifyResponse(): array
    {
        return [
            'data' => [
                'orders' => [
                    'edges' => [[
                        'node' => [
                            'id' => 'gid://shopify/Order/123456789',
                            'legacyResourceId' => '123456789',
                            'name' => '#65075',
                            'createdAt' => '2026-08-30T10:15:00Z',
                            'cancelledAt' => null,
                            'email' => '<script>alert("shopify")</script>',
                            'displayFinancialStatus' => 'PAID',
                            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
                            'totalPriceSet' => ['shopMoney' => ['amount' => '129.90', 'currencyCode' => 'EUR']],
                        ],
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function comparisonShopifyResponse(): array
    {
        $response = $this->shopifyResponse();
        $node = &$response['data']['orders']['edges'][0]['node'];
        $node['email'] = 'buyer@example.com';
        $node['displayFulfillmentStatus'] = 'FULFILLED';
        $node['shippingAddress'] = [
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'address1' => '1 Main Street',
            'city' => 'Sofia',
            'provinceCode' => 'SOF',
            'zip' => '1000',
            'countryCodeV2' => 'BG',
        ];
        $node['lineItems'] = [
            'nodes' => [
                ['id' => 'gid://shopify/LineItem/1', 'sku' => 'Widget', 'quantity' => 2, 'title' => 'Widget'],
                ['id' => 'gid://shopify/LineItem/2', 'sku' => 'Cable', 'quantity' => 1, 'title' => 'Cable'],
            ],
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ];

        return $response;
    }

    /** @return array<string, mixed> */
    private function matchingShipStationOrder(): array
    {
        return [
            'orderId' => 42,
            'orderNumber' => '65075',
            'orderStatus' => 'shipped',
            'customerEmail' => 'buyer@example.com',
            'orderTotal' => 129.90,
            'shipTo' => [
                'name' => 'jane doe',
                'street1' => '1 main street',
                'city' => 'SOFIA',
                'state' => 'sof',
                'postalCode' => '1000',
                'country' => 'bg',
            ],
            'items' => [
                ['sku' => 'widget', 'quantity' => 2, 'name' => 'Widget'],
                ['sku' => 'cable', 'quantity' => 1, 'name' => 'Cable'],
            ],
        ];
    }
}
