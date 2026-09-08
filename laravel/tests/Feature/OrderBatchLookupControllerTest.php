<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrderBatchLookupControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/orders/spot-check')->assertRedirect(route('login'));
        $this->post('/orders/spot-check')->assertRedirect(route('login'));
    }

    public function test_user_without_store_access_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('orders.spot-check'))->assertForbidden();
    }

    public function test_initial_form_preserves_a_safe_prefill_without_calling_integrations(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('findByOrderNumbers');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)
            ->get(route('orders.spot-check', ['prefill' => '<script>alert(1)</script>']))
            ->assertOk()
            ->assertSeeText('Spot-check orders')
            ->assertSeeText('<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false);

        Http::assertNothingSent();
    }

    public function test_empty_too_many_malformed_array_and_invalid_mode_inputs_are_rejected(): void
    {
        [$user] = $this->userWithStore();

        $this->from(route('orders.spot-check'))->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => " \n ",
            'mode' => 'both',
        ])->assertRedirect(route('orders.spot-check'))->assertSessionHasErrors([
            'orders' => 'Enter at least one order number.',
        ]);

        $this->from(route('orders.spot-check'))->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => implode(' ', range(1, 51)),
            'mode' => 'both',
        ])->assertRedirect(route('orders.spot-check'))->assertSessionHasErrors([
            'orders' => 'Maximum 50 order numbers at once.',
        ]);

        foreach ([
            ['orders' => '1001 OR status:any', 'mode' => 'both'],
            ['orders' => ['1001'], 'mode' => 'both'],
            ['orders' => '1001', 'mode' => 'invalid'],
        ] as $payload) {
            $this->from(route('orders.spot-check'))
                ->actingAs($user)
                ->post(route('orders.spot-check.store'), $payload)
                ->assertRedirect(route('orders.spot-check'))
                ->assertSessionHasErrors();
        }
    }

    public function test_exactly_fifty_numbers_are_accepted_and_shopify_uses_one_batch_operation(): void
    {
        [$user, $store] = $this->userWithStore();
        $numbers = array_map(strval(...), range(1001, 1050));
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumbers')
            ->once()
            ->with(Mockery::on(fn (Store $received): bool => $received->is($store)), $numbers)
            ->andReturn(array_fill_keys($numbers, []));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => implode("\n", $numbers),
            'mode' => 'shopify',
        ])->assertOk()->assertSeeText('50 checked');
    }

    public function test_normalized_duplicate_inputs_are_looked_up_and_displayed_once_in_first_seen_order(): void
    {
        [$user, $store] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumbers')
            ->once()
            ->with(Mockery::on(fn (Store $received): bool => $received->is($store)), ['1002', '1001'])
            ->andReturn(['1002' => [], '1001' => []]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $response = $this->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => "#1002, 1002\n#1001",
            'mode' => 'shopify',
        ]);

        $response->assertOk()->assertSeeTextInOrder(['#1002', '#1001'])->assertSeeText('2 checked');
    }

    public function test_both_mode_renders_every_found_state_multiple_matches_risk_zero_totals_and_safe_links(): void
    {
        [$user, $store] = $this->userWithStore();
        $shopifyOrders = [
            '1001' => [$this->shopifyOrder(1, '<script>alert("name")</script>')],
            '1002' => [],
            '1003' => [$this->shopifyOrder(3, '#1003'), $this->shopifyOrder(33, '#1003-copy')],
            '1004' => [],
        ];
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumbers')->once()->andReturn($shopifyOrders);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $shipStation = Mockery::mock(ShipStationClientContract::class);
        $shipStation->shouldReceive('findByOrderNumber')->once()->with('1001')->andReturn([]);
        $shipStation->shouldReceive('findByOrderNumber')->once()->with('1002')->andReturn([[
            'orderId' => 2,
            'orderNumber' => '1002',
            'orderStatus' => '<img src=x onerror=alert(1)>',
            'orderTotal' => 0,
        ]]);
        $shipStation->shouldReceive('findByOrderNumber')->once()->with('1003')->andReturn([[
            'orderId' => 3,
            'orderNumber' => '1003',
            'orderStatus' => 'shipped',
            'orderTotal' => '19.90',
        ]]);
        $shipStation->shouldReceive('findByOrderNumber')->once()->with('1004')->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn($shipStation);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $response = $this->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => '1001, 1002 1003'."\n".'1004',
        ]);

        $response
            ->assertOk()
            ->assertSeeText('Shopify: 2/4 found')
            ->assertSeeText('ShipStation: 2/4 found')
            ->assertSeeText('Shopify only')
            ->assertSeeText('ShipStation only')
            ->assertSeeText('Both found')
            ->assertSeeText('Not found')
            ->assertSeeText('Risk: 40 · Medium')
            ->assertSeeText('<script>alert("name")</script>')
            ->assertDontSee('<script>alert("name")</script>', false)
            ->assertSeeText('<img src=x onerror=alert(1)>')
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee('>0</span>', false)
            ->assertSee('https://acme.myshopify.com/admin/orders/1', false)
            ->assertSee('https://app.shipstation.com/#!/orders/order-details/2', false);
    }

    public function test_shopify_only_does_not_resolve_or_call_shipstation_and_retains_the_mode(): void
    {
        [$user] = $this->userWithStore(['shipstation_api_key' => null, 'shipstation_api_secret' => null]);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumbers')->once()->andReturn(['1001' => []]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldNotReceive('forStore');
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => '1001',
            'mode' => 'shopify',
        ])->assertOk()->assertSee('value="shopify" checked', false)->assertDontSeeText('ShipStation: 0/1 found');
    }

    public function test_shipstation_only_does_not_call_shopify(): void
    {
        [$user] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('findByOrderNumbers');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $shipStation = Mockery::mock(ShipStationClientContract::class);
        $shipStation->shouldReceive('findByOrderNumber')->once()->with('1001')->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn($shipStation);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => '1001',
            'mode' => 'shipstation',
        ])->assertOk()->assertDontSeeText('Shopify: 0/1 found');
    }

    public function test_missing_or_incomplete_shipstation_configuration_is_explicit_and_stops_all_api_calls(): void
    {
        foreach ([
            ['shopify_store' => 'missing-ss', 'shipstation_api_key' => null, 'shipstation_api_secret' => null],
            ['shopify_store' => 'incomplete-ss', 'shipstation_api_key' => 'key', 'shipstation_api_secret' => null],
        ] as $credentials) {
            [$user] = $this->userWithStore($credentials);
            $shopify = Mockery::mock(ShopifyAdminGateway::class);
            $shopify->shouldNotReceive('findByOrderNumbers');
            $this->app->instance(ShopifyAdminGateway::class, $shopify);

            $this->actingAs($user)->post(route('orders.spot-check.store'), [
                'orders' => '1001',
                'mode' => 'both',
            ])->assertOk()->assertSeeText('ShipStation is not configured completely for this store.');
        }
    }

    public function test_selected_store_is_used_for_both_integration_boundaries(): void
    {
        $user = User::factory()->create();
        $otherStore = Store::factory()->create();
        $selectedStore = Store::factory()->create();
        $user->stores()->attach([$otherStore->getKey(), $selectedStore->getKey()]);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumbers')
            ->once()
            ->with(Mockery::on(fn (Store $received): bool => $received->is($selectedStore)), ['1001'])
            ->andReturn(['1001' => []]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $shipStation = Mockery::mock(ShipStationClientContract::class);
        $shipStation->shouldReceive('findByOrderNumber')->once()->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')
            ->once()
            ->with(Mockery::on(fn (Store $received): bool => $received->is($selectedStore)))
            ->andReturn($shipStation);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)
            ->withSession(['active_store_id' => $selectedStore->getKey()])
            ->post(route('orders.spot-check.store'), ['orders' => '1001', 'mode' => 'both'])
            ->assertOk();
    }

    public function test_upstream_failure_returns_an_atomic_safe_error_without_leaking_secrets(): void
    {
        [$user] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('findByOrderNumbers')->once()->andThrow(new RuntimeException('private-token'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => '1001',
            'mode' => 'shopify',
        ])->assertOk()
            ->assertSeeText('The spot-check could not be completed.')
            ->assertDontSee('private-token')
            ->assertDontSeeText('Results');
    }

    public function test_expensive_batch_endpoint_is_rate_limited_per_user_and_ip(): void
    {
        [$user] = $this->userWithStore();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($user)->post(route('orders.spot-check.store'), [
                'orders' => '',
                'mode' => 'both',
            ])->assertSessionHasErrors('orders');
        }

        $this->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => '',
            'mode' => 'both',
        ])->assertTooManyRequests();
    }

    public function test_later_shipstation_failure_does_not_render_partial_rows(): void
    {
        [$user] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('findByOrderNumbers');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $shipStation = Mockery::mock(ShipStationClientContract::class);
        $shipStation->shouldReceive('findByOrderNumber')->once()->with('1001')->andReturn([[
            'orderId' => 1,
            'orderNumber' => '1001',
        ]]);
        $shipStation->shouldReceive('findByOrderNumber')->once()->with('1002')->andThrow(new RuntimeException('private-secret'));
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn($shipStation);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('orders.spot-check.store'), [
            'orders' => '1001 1002',
            'mode' => 'shipstation',
        ])->assertOk()
            ->assertSeeText('The spot-check could not be completed.')
            ->assertDontSee('private-secret')
            ->assertDontSeeText('Results');
    }

    /** @return array{User, Store} */
    private function userWithStore(array $attributes = []): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'shopify_store' => 'acme',
            'shopify_access_token' => 'shopify-token',
            'shipstation_api_key' => 'shipstation-key',
            'shipstation_api_secret' => 'shipstation-secret',
            ...$attributes,
        ]);
        $user->stores()->attach($store);

        return [$user, $store];
    }

    /** @return array<string, mixed> */
    private function shopifyOrder(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'financial_status' => 'paid',
            'total_price' => '10.00',
            'currency' => 'USD',
            'email' => 'buyer@example.com',
            'shipping_address' => ['country_code' => 'BG', 'phone' => '+359888123456'],
            'billing_address' => ['country_code' => 'BG'],
            'tags' => [],
            'risk_level' => 'HIGH',
        ];
    }
}
