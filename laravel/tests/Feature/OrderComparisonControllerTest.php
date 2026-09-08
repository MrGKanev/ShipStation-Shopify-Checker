<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderComparisonControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('orders.compare'))->assertRedirect(route('login'));
    }

    public function test_initial_form_does_not_make_external_requests(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->actingAs($user)->get(route('orders.compare'));

        $response->assertOk()->assertSeeText('Order compare');
        Http::assertNothingSent();
    }

    public function test_both_order_numbers_are_required_together_before_external_requests(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->from(route('orders.compare'))->actingAs($user)->get(route('orders.compare', [
            'order_a' => '#1001',
            'order_b' => '   ',
        ]));

        $response
            ->assertRedirect(route('orders.compare'))
            ->assertSessionHasErrors(['order_b' => 'Enter two order numbers to compare.']);
        Http::assertNothingSent();
    }

    public function test_injection_like_order_number_is_rejected_before_external_requests(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->from(route('orders.compare'))->actingAs($user)->get(route('orders.compare', [
            'order_a' => '1001 OR status:any',
            'order_b' => '1002',
        ]));

        $response->assertRedirect(route('orders.compare'))->assertSessionHasErrors('order_a');
        Http::assertNothingSent();
    }

    public function test_order_number_longer_than_the_supported_limit_is_rejected(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->from(route('orders.compare'))->actingAs($user)->get(route('orders.compare', [
            'order_a' => str_repeat('1', 65),
            'order_b' => '1002',
        ]));

        $response->assertRedirect(route('orders.compare'))->assertSessionHasErrors('order_a');
        Http::assertNothingSent();
    }

    public function test_array_order_number_is_rejected_without_causing_a_server_error(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();

        $response = $this->from(route('orders.compare'))->actingAs($user)->get(route('orders.compare', [
            'order_a' => ['1001'],
            'order_b' => '1002',
        ]));

        $response->assertRedirect(route('orders.compare'))->assertSessionHasErrors('order_a');
        Http::assertNothingSent();
    }

    public function test_viewer_compares_two_orders_with_active_store_credentials_and_escaped_output(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        Http::fake([
            'https://active.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push($this->shopifyResponse($this->orderNode('#1001', [
                    'note' => '<script>alert("note")</script>',
                ])))
                ->push($this->shopifyResponse($this->orderNode('#1002', [
                    'email' => 'other@example.com',
                    'totalPriceSet' => ['shopMoney' => ['amount' => '95.00', 'currencyCode' => 'EUR']],
                    'displayFulfillmentStatus' => 'UNFULFILLED',
                ]))),
            'https://ssapi.shipstation.com/orders*' => Http::sequence()
                ->push(['orders' => [['orderStatus' => 'shipped']], 'pages' => 1])
                ->push(['orders' => [['orderStatus' => 'on_hold']], 'pages' => 1]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.compare', ['order_a' => '#1001', 'order_b' => '1002']));

        $response
            ->assertOk()
            ->assertSeeText('Comparison')
            ->assertSeeText('other@example.com')
            ->assertSeeText('$95.00')
            ->assertSeeText('on_hold')
            ->assertSee('<script>alert("note")</script>')
            ->assertDontSee('<script>alert("note")</script>', false);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://active.myshopify.com/admin/api/2026-07/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'active-shopify-token'));
        $this->assertSame(
            [['query' => 'name:1001'], ['query' => 'name:1002']],
            Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'myshopify.com'))
                ->map(fn (array $record): array => $record[0]->data()['variables'])
                ->all(),
        );
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('active-ss-key:active-ss-secret'),
        ));
    }

    public function test_comparison_works_without_shipstation_credentials(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore([
            'shipstation_api_key' => null,
            'shipstation_api_secret' => null,
        ]);
        Http::fake([
            'https://active.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push($this->shopifyResponse($this->orderNode('#1001')))
                ->push($this->shopifyResponse($this->orderNode('#1002'))),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.compare', ['order_a' => '1001', 'order_b' => '1002']));

        $response
            ->assertOk()
            ->assertSeeText('ShipStation is not configured for this store.');
        Http::assertSentCount(2);
    }

    public function test_shipstation_missing_and_multiple_matches_are_reported_without_arbitrary_selection(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        Http::fake([
            'https://active.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push($this->shopifyResponse($this->orderNode('#1001')))
                ->push($this->shopifyResponse($this->orderNode('#1002'))),
            'https://ssapi.shipstation.com/orders*' => Http::sequence()
                ->push(['orders' => [
                    ['orderStatus' => 'shipped'],
                    ['orderStatus' => 'on_hold'],
                ], 'pages' => 1])
                ->push(['orders' => [], 'pages' => 1]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.compare', ['order_a' => '1001', 'order_b' => '1002']));

        $response
            ->assertOk()
            ->assertSeeText('Multiple matches (2)')
            ->assertSeeText('Not found');
        Http::assertSentCount(4);
    }

    public function test_missing_and_ambiguous_shopify_results_are_not_selected_automatically(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore([
            'shipstation_api_key' => null,
            'shipstation_api_secret' => null,
        ]);
        Http::fake([
            'https://active.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push($this->shopifyResponse())
                ->push($this->shopifyResponse([
                    $this->orderNode('#1002'),
                    $this->orderNode('#1002', ['legacyResourceId' => '2002']),
                ])),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.compare', ['order_a' => '9999', 'order_b' => '1002']));

        $response
            ->assertOk()
            ->assertSeeText('(not found)')
            ->assertSeeText('(2 matches)')
            ->assertSeeText('No ambiguous record was selected automatically.');
        Http::assertSentCount(2);
    }

    public function test_upstream_failure_returns_a_safe_error_without_exposing_details(): void
    {
        Http::preventStrayRequests();
        [$user, $store] = $this->userWithStore();
        Http::fake([
            'https://active.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'message' => 'private comparison failure',
            ], 500),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.compare', ['order_a' => '1001', 'order_b' => '1002']));

        $response
            ->assertOk()
            ->assertSeeText('The order comparison could not be completed.')
            ->assertDontSee('private comparison failure');
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
            'label' => 'Active store',
            'shopify_store' => 'active',
            'shopify_access_token' => 'active-shopify-token',
            'shipstation_api_key' => 'active-ss-key',
            'shipstation_api_secret' => 'active-ss-secret',
            ...$storeAttributes,
        ]);
        $user->stores()->attach($store);

        return [$user, $store];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>|null  $nodes
     * @return array<string, mixed>
     */
    private function shopifyResponse(?array $nodes = null): array
    {
        if ($nodes === null) {
            $nodes = [];
        } elseif (! array_is_list($nodes)) {
            $nodes = [$nodes];
        }

        return ['data' => ['orders' => [
            'edges' => array_map(fn (array $node): array => ['node' => $node], $nodes),
        ]]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderNode(string $name, array $overrides = []): array
    {
        return [
            'id' => 'gid://shopify/Order/'.ltrim($name, '#'),
            'legacyResourceId' => ltrim($name, '#'),
            'name' => $name,
            'createdAt' => '2026-09-01T10:00:00Z',
            'email' => 'buyer@example.com',
            'note' => 'Handle carefully',
            'tags' => ['vip'],
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'EUR']],
            'shippingAddress' => [
                'firstName' => 'Jane', 'lastName' => 'Doe', 'address1' => '1 Main Street',
                'city' => 'Sofia', 'provinceCode' => 'SOF', 'zip' => '1000', 'countryCodeV2' => 'BG',
            ],
            'lineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/1', 'title' => 'Widget', 'sku' => 'WIDGET',
                    'quantity' => 1, 'variantTitle' => 'Blue',
                ]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ],
            ...$overrides,
        ];
    }
}
