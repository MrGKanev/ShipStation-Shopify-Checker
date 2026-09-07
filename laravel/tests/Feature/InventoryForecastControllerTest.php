<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InventoryForecastControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_viewer_cannot_access(): void
    {
        $this->get('/reports/inventory-forecast')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/inventory-forecast')->assertForbidden();
    }

    public function test_form_does_not_fetch_shopify(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('inventoryForecastCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->get(route('reports.inventory-forecast'))->assertOk()->assertSeeText('Run forecast');
    }

    public function test_configuration_error_prevents_call(): void
    {
        [$user] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('inventoryForecastCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.inventory-forecast.store'))->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_uses_fixed_window_selected_store_and_renders_results_safely(): void
    {
        $this->travelTo('2026-09-07');
        [$user, $store] = $this->userWithStore(true);
        Store::factory()->create();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('inventoryForecastCandidates')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)), '2026-08-08', '2026-09-07')->andReturn([
            'products' => [[
                'legacyResourceId' => '42',
                'title' => '<script>x</script>',
                'variants' => [[
                    'sku' => '<img src=x>',
                    'title' => '<b>V</b>',
                    'inventoryQuantity' => 5,
                    'inventoryPolicy' => 'DENY',
                    'inventoryItem' => ['tracked' => true],
                ]],
            ]],
            'orders' => [['cancelled_at' => null, 'line_items' => [['sku' => '<img src=x>', 'quantity' => 30]]]],
            'product_pages' => 100,
            'order_pages' => 100,
            'products_truncated' => true,
            'orders_truncated' => true,
        ]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.inventory-forecast.store'), ['store_id' => 999])->assertOk()->assertSeeText('1 products · 1 variants · 1 orders')->assertSeeText('1 critical')->assertSeeText('Product catalogue stopped after 100 pages')->assertSeeText('Orders stopped after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<b>V</b>', false);
    }

    public function test_upstream_error_is_atomic_and_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('inventoryForecastCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.inventory-forecast.store'))->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('products ·');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
