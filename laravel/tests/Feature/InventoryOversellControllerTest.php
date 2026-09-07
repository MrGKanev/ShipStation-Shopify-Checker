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

class InventoryOversellControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_viewer_cannot_access_report(): void
    {
        $this->get('/reports/inventory-oversell')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/inventory-oversell')->assertForbidden();
    }

    public function test_form_does_not_call_either_service(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('inventoryOversellCandidates');
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldNotReceive('forStore');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->get(route('reports.inventory-oversell'))->assertOk()->assertSeeText('Inventory oversell risk');
    }

    public function test_incomplete_configuration_prevents_calls(): void
    {
        [$user] = $this->userWithStore(true, ['shopify_access_token' => '', 'shipstation_api_secret' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('inventoryOversellCandidates');
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldNotReceive('forStore');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('reports.inventory-oversell.store'))->assertOk()->assertSeeText('Shopify credentials are incomplete')->assertSeeText('ShipStation credentials are incomplete');
    }

    public function test_selected_store_results_truncation_and_xss_render_safely(): void
    {
        [$user, $store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('inventoryOversellCandidates')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)))->andReturn([
            'products' => [['legacyResourceId' => '42', 'title' => '<script>x</script>', 'variants' => [['sku' => '<img src=x>', 'title' => '<b>V</b>', 'inventoryQuantity' => 1, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]]]],
            'pages' => 100,
            'truncated' => true,
        ]);
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('fetchAwaitingOrders')->once()->andReturn([['items' => [['sku' => '<img src=x>', 'quantity' => 3]]]]);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)))->andReturn($client);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('reports.inventory-oversell.store'), ['store_id' => 999])->assertOk()
            ->assertSeeText('1 products · 1 awaiting orders')->assertSeeText('product catalogue truncated after 100 pages')
            ->assertSeeText('2')->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<b>V</b>', false);
    }

    public function test_upstream_error_is_atomic_and_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('inventoryOversellCandidates')->andThrow(new RuntimeException('secret'));
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldNotReceive('forStore');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->app->instance(ShipStationClientFactory::class, $factory);

        $this->actingAs($user)->post(route('reports.inventory-oversell.store'))->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('SKUs at risk');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
