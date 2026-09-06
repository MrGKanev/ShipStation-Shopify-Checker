<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OrderTimelineControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/orders/timeline')->assertRedirect(route('login'));
    }

    public function test_initial_form_does_not_call_integrations(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();
        $shopify = $this->createMock(ShopifyAdminGateway::class);
        $shopify->expects($this->never())->method('findByOrderNumber');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)
            ->get(route('orders.timeline'))
            ->assertOk()
            ->assertSeeText('Order timeline');

        Http::assertNothingSent();
    }

    public function test_invalid_and_array_order_numbers_are_rejected_before_integrations(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();
        $shopify = $this->createMock(ShopifyAdminGateway::class);
        $shopify->expects($this->never())->method('findByOrderNumber');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->from(route('orders.timeline'))
            ->actingAs($user)
            ->get(route('orders.timeline', ['order_number' => '65075 OR status:any']))
            ->assertRedirect(route('orders.timeline'))
            ->assertSessionHasErrors('order_number');

        $this->from(route('orders.timeline'))
            ->actingAs($user)
            ->get(route('orders.timeline', ['order_number' => ['65075']]))
            ->assertRedirect(route('orders.timeline'))
            ->assertSessionHasErrors('order_number');

        Http::assertNothingSent();
    }

    public function test_blank_order_number_is_treated_as_an_empty_search(): void
    {
        Http::preventStrayRequests();
        [$user] = $this->userWithStore();
        $shopify = $this->createMock(ShopifyAdminGateway::class);
        $shopify->expects($this->never())->method('findByOrderNumber');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)
            ->get(route('orders.timeline', ['order_number' => '  #  ']))
            ->assertOk()
            ->assertSessionDoesntHaveErrors();

        Http::assertNothingSent();
    }

    public function test_missing_and_ambiguous_shopify_orders_do_not_load_events(): void
    {
        [$user, $store] = $this->userWithStore();
        $shopify = $this->createMock(ShopifyAdminGateway::class);
        $shopify->expects($this->exactly(2))
            ->method('findByOrderNumber')
            ->with($this->callback(fn (Store $received): bool => $received->is($store)), '65075')
            ->willReturnOnConsecutiveCalls([], [$this->order(), $this->order()]);
        $shopify->expects($this->never())->method('getOrderEvents');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)
            ->get(route('orders.timeline', ['order_number' => '#65075']))
            ->assertOk()
            ->assertSeeText('No Shopify order was found for #65075.');

        $this->actingAs($user)
            ->get(route('orders.timeline', ['order_number' => '65075']))
            ->assertOk()
            ->assertSeeText('Shopify returned 2 matches');
    }

    public function test_ready_timeline_combines_sources_counts_items_and_escapes_external_content(): void
    {
        [$user, $store] = $this->userWithStore([
            'shipstation_api_key' => 'key',
            'shipstation_api_secret' => 'secret',
        ]);
        $order = $this->order();
        $order['email'] = '<script>alert("email")</script>';
        $order['fulfillments'] = [[
            'created_at' => '2026-06-05T10:00:00Z',
            'tracking_company' => 'UPS',
            'tracking_number' => '<script>alert("tracking")</script>',
            'tracking_url' => 'javascript:alert(1)',
            'line_items' => [['quantity' => 2]],
        ]];
        $shopify = $this->createMock(ShopifyAdminGateway::class);
        $shopify->expects($this->once())
            ->method('findByOrderNumber')
            ->with($this->callback(fn (Store $received): bool => $received->is($store)), '65075')
            ->willReturn([$order]);
        $shopify->expects($this->once())
            ->method('getOrderEvents')
            ->with($this->callback(fn (Store $received): bool => $received->is($store)), 'gid://shopify/Order/123')
            ->willReturn([[
                'verb' => 'note_added',
                'created_at' => '2026-06-04T10:00:00Z',
                'message' => '<img src=x onerror=alert(1)>',
            ]]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $shipStation = $this->createMock(ShipStationClientContract::class);
        $shipStation->expects($this->once())->method('findByOrderNumber')->with('65075')->willReturn([[
            'orderId' => 99,
            'orderStatus' => 'awaiting_shipment',
            'createDate' => '2026-06-02T10:00:00Z',
        ]]);
        $shipStation->expects($this->once())->method('getOrderShipments')->with('65075')->willReturn([[
            'shipDate' => '2026-06-03T10:00:00Z',
            'carrierCode' => 'ups',
            'trackingNumber' => 'SS-TRACK',
        ]]);
        $factory = $this->createMock(ShipStationClientFactory::class);
        $factory->expects($this->once())
            ->method('forStore')
            ->with($this->callback(fn (Store $received): bool => $received->is($store)))
            ->willReturn($shipStation);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $response = $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.timeline', ['order_number' => '#65075']));

        $response
            ->assertOk()
            ->assertSeeText('3 days')
            ->assertSeeText('2 items · UPS')
            ->assertSeeText('Shipped via ShipStation')
            ->assertSeeText('<img src=x onerror=alert(1)>')
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSeeText('<script>alert("tracking")</script>')
            ->assertDontSee('<script>alert("tracking")</script>', false)
            ->assertDontSee('href="javascript:alert(1)"', false);
    }

    public function test_timeline_without_shipstation_still_loads_shopify_activity(): void
    {
        [$user, $store] = $this->userWithStore();
        $shopify = $this->createMock(ShopifyAdminGateway::class);
        $shopify->method('findByOrderNumber')->willReturn([$this->order()]);
        $shopify->expects($this->once())->method('getOrderEvents')->willReturn([]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)
            ->withSession(['active_store_id' => $store->getKey()])
            ->get(route('orders.timeline', ['order_number' => '65075']))
            ->assertOk()
            ->assertSeeText('Not configured')
            ->assertSeeText('Order placed');
    }

    public function test_timeline_uses_the_selected_store_instead_of_another_accessible_store(): void
    {
        $user = User::factory()->create();
        $otherStore = Store::factory()->create();
        $selectedStore = Store::factory()->create([
            'shipstation_api_key' => null,
            'shipstation_api_secret' => null,
        ]);
        $user->stores()->attach([$otherStore->getKey(), $selectedStore->getKey()]);

        $shopify = $this->createMock(ShopifyAdminGateway::class);
        $shopify->expects($this->once())
            ->method('findByOrderNumber')
            ->with($this->callback(fn (Store $received): bool => $received->is($selectedStore)), '65075')
            ->willReturn([$this->order()]);
        $shopify->expects($this->once())
            ->method('getOrderEvents')
            ->with($this->callback(fn (Store $received): bool => $received->is($selectedStore)), 'gid://shopify/Order/123')
            ->willReturn([]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)
            ->withSession(['active_store_id' => $selectedStore->getKey()])
            ->get(route('orders.timeline', ['order_number' => '65075']))
            ->assertOk();
    }

    public function test_upstream_failure_returns_a_safe_error_without_leaking_details(): void
    {
        [$user] = $this->userWithStore();
        $shopify = $this->createMock(ShopifyAdminGateway::class);
        $shopify->method('findByOrderNumber')->willThrowException(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)
            ->get(route('orders.timeline', ['order_number' => '65075']))
            ->assertOk()
            ->assertSeeText('The order timeline could not be completed.')
            ->assertDontSee('secret-token');
    }

    /** @return array{User, Store} */
    private function userWithStore(array $attributes = []): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'shopify_store' => 'acme',
            'shopify_access_token' => 'shopify-token',
            'shipstation_api_key' => null,
            'shipstation_api_secret' => null,
            ...$attributes,
        ]);
        $user->stores()->attach($store);

        return [$user, $store];
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'id' => 123,
            'admin_graphql_api_id' => 'gid://shopify/Order/123',
            'name' => '#65075',
            'created_at' => '2026-06-02T10:00:00Z',
            'processed_at' => '2026-06-02T10:05:00Z',
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'total_price' => '49.99',
            'shipping_address' => ['country_code' => 'BG', 'phone' => '+359888123456', 'address1' => '1 Main St'],
            'billing_address' => ['country_code' => 'BG'],
            'fulfillments' => [],
            'refunds' => [],
            'tags' => [],
        ];
    }
}
