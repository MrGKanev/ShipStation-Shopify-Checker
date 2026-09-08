<?php

namespace Tests\Feature;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RefundTrackerControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_and_configuration_states(): void
    {
        $this->get('/reports/refund-tracker')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/refund-tracker')->assertForbidden();

        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => 'bad', 'end_date' => '2026-01-01'])->assertSessionHasErrors('start_date');

        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('Shopify credentials are incomplete');

        [$operator] = $this->userWithStore(true, ['shipstation_api_secret' => '']);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('ShipStation credentials are incomplete');
    }

    public function test_it_cross_checks_refunds_and_escapes_output(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('refundTrackerCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-01-01', '2026-01-31')->andReturn([
            'orders' => [['id' => '1', 'name' => '#<script>', 'order_number' => '1001', 'email' => '<img>@example.com', 'financial_status' => 'refunded', 'total_price' => 20, 'refunds' => []]],
            'pages' => 100,
            'truncated' => true,
        ]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAllOrders')->once()->with('2026-01-01', '2026-02-07')->andReturn([['orderNumber' => '1001', 'orderStatus' => 'awaiting_shipment']]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)))->andReturn($client);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])
            ->assertOk()
            ->assertSeeText('Still active in ShipStation')
            ->assertSeeText('truncated after 100 pages')
            ->assertDontSee('<script>', false)
            ->assertDontSee('<img>', false);
    }

    public function test_it_runs_without_shipstation_and_hides_failures(): void
    {
        [$operator] = $this->userWithStore(true, ['shipstation_api_key' => '', 'shipstation_api_secret' => '']);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('refundTrackerCandidates')->once()->andReturn(['orders' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('shown without a cross-check');

        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('refundTrackerCandidates')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/refund-tracker', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
