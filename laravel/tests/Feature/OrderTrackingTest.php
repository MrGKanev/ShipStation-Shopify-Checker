<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_initial_prefill_and_validation_boundaries(): void
    {
        $this->get('/orders/tracking')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/orders/tracking')->assertForbidden();
        [$user] = $this->userWithStore();
        $this->actingAs($user)->get(route('orders.tracking', ['prefill' => '<script>x</script>']))->assertOk()->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>', false);
        $this->from(route('orders.tracking'))->actingAs($user)->post(route('orders.tracking.store'), ['orders' => implode(' ', range(1, 31))])->assertSessionHasErrors(['orders' => 'Maximum 30 order numbers at once.']);
        foreach ([['orders' => '###'], ['orders' => ['1001']], ['orders' => '1001 OR status:any']] as $payload) {
            $this->from(route('orders.tracking'))->actingAs($user)->post(route('orders.tracking.store'), $payload)->assertSessionHasErrors('orders');
        }
    }

    public function test_tracks_unique_orders_with_real_shipments_and_escapes_upstream_text(): void
    {
        [$user, $store] = $this->userWithStore();
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->once()->with('1002')->andReturn([['orderId' => 2, 'orderStatus' => '<script>x</script>']]);
        $client->shouldReceive('getOrderShipments')->once()->with('1002')->andReturn([['orderId' => 2, 'carrierCode' => 'UPS', 'trackingNumber' => 'ABC 1', 'shipDate' => '2026-09-01T10:00:00Z'], ['orderId' => 2, 'carrierCode' => 'unknown', 'trackingNumber' => 'T2']]);
        $client->shouldReceive('findByOrderNumber')->once()->with('1001')->andReturn([]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->with(Mockery::on(fn (Store $received): bool => $received->is($store)))->andReturn($client);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => "#1002,1002\n1001"])->assertOk()
            ->assertSeeTextInOrder(['#1002', '#1001'])->assertSeeText('2 shipments')->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>', false)
            ->assertSee('https://www.ups.com/track?tracknum=ABC+1', false)->assertSee('rel="noopener noreferrer"', false)->assertSeeText('Not found in ShipStation.');
    }

    public function test_missing_configuration_and_upstream_failure_are_safe_and_atomic(): void
    {
        [$user] = $this->userWithStore();
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andReturn(null);
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => '1001'])->assertOk()->assertSeeText('not configured completely');

        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->andThrow(new RuntimeException('private-secret'));
        $this->app->instance(ShipStationClientFactory::class, $factory);
        $this->actingAs($user)->post(route('orders.tracking.store'), ['orders' => '1001'])->assertOk()->assertSeeText('Tracking could not be loaded')->assertDontSeeText('private-secret');
    }

    private function userWithStore(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
