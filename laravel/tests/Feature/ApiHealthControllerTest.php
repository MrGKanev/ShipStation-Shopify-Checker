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

class ApiHealthControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_administrators_can_access_api_health(): void
    {
        $this->get('/admin/api-health')->assertRedirect(route('login'));
        [$operator] = $this->userWithStore(false);
        $this->actingAs($operator)->get('/admin/api-health')->assertForbidden();
    }

    public function test_initial_page_does_not_call_external_services(): void
    {
        [$admin] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('healthCheck');
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldNotReceive('forStore');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($admin)->get(route('admin.api-health'))->assertOk()->assertSeeText('Run health check')->assertDontSeeText('Checked at');
    }

    public function test_health_check_reports_scopes_and_selected_store_results(): void
    {
        [$admin, $store] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('healthCheck')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)))->andReturn([
            'shop_name' => '<script>Shop</script>',
            'scopes' => ['read_orders'],
            'requested_version' => '2026-07',
        ]);
        $shipStation = Mockery::mock(ShipStationClientContract::class);
        $shipStation->shouldReceive('healthCheck')->once();
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)))->andReturn($shipStation);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($admin)->post(route('admin.api-health.check'))->assertOk()
            ->assertSeeText('Required Shopify scopes are missing')->assertSeeText('read_fulfillments')
            ->assertSeeText('Healthy')->assertDontSee('<script>', false);
    }

    public function test_missing_credentials_make_no_external_requests(): void
    {
        [$admin] = $this->userWithStore(true, ['shopify_access_token' => '', 'shipstation_api_key' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('healthCheck');
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldNotReceive('forStore');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($admin)->post(route('admin.api-health.check'))->assertOk()->assertSeeText('Shopify credentials are incomplete')->assertSeeText('ShipStation credentials are incomplete');
    }

    public function test_provider_errors_are_safe_and_do_not_prevent_the_other_check(): void
    {
        [$admin] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('healthCheck')->andThrow(new RuntimeException('shopify-secret'));
        $shipStation = Mockery::mock(ShipStationClientContract::class);
        $shipStation->shouldReceive('healthCheck')->andThrow(new RuntimeException('shipstation-secret'));
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($shipStation);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($admin)->post(route('admin.api-health.check'))->assertOk()->assertSeeText('Shopify could not be reached')->assertSeeText('ShipStation could not be reached')->assertDontSeeText('shopify-secret')->assertDontSeeText('shipstation-secret');
    }

    private function userWithStore(bool $admin = true, array $attributes = []): array
    {
        $user = $admin ? User::factory()->admin()->create() : User::factory()->operator()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
