<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InventoryAgingControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_viewer_cannot_access_report(): void
    {
        $this->get('/reports/inventory-aging')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/inventory-aging')->assertForbidden();
    }

    public function test_form_uses_default_range_without_fetching_shopify(): void
    {
        $this->travelTo('2026-09-07');
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('inventoryAgingCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->get(route('reports.inventory-aging'))->assertOk()->assertSee('2026-08-08')->assertSee('2026-09-07');
    }

    public function test_rejects_invalid_and_reversed_dates(): void
    {
        [$user] = $this->userWithStore(true);

        $this->actingAs($user)->post(route('reports.inventory-aging.store'), ['start_date' => '2026-09-08', 'end_date' => '2026-09-07'])->assertSessionHasErrors('end_date');
    }

    public function test_rejects_malformed_date(): void
    {
        [$user] = $this->userWithStore(true);

        $this->actingAs($user)->post(route('reports.inventory-aging.store'), ['start_date' => '07-09-2026', 'end_date' => '2026-09-07'])->assertSessionHasErrors('start_date');
    }

    public function test_configuration_error_prevents_call(): void
    {
        [$user] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('inventoryAgingCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.inventory-aging.store'), ['start_date' => '2026-08-01', 'end_date' => '2026-09-01'])->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_selected_store_results_truncation_and_xss_render_safely(): void
    {
        [$user, $store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('inventoryAgingCandidates')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)), '2026-08-01', '2026-09-01')->andReturn([
            'products' => [[
                'legacyResourceId' => '42',
                'title' => '<script>x</script>',
                'variants' => [[
                    'sku' => '<img src=x>',
                    'title' => '<b>V</b>',
                    'inventoryQuantity' => 0,
                    'inventoryPolicy' => 'DENY',
                    'inventoryItem' => ['tracked' => true],
                ]],
            ]],
            'orders' => [['name' => '#1<script>', 'created_at' => '2026-08-10', 'line_items' => [['sku' => '<img src=x>', 'quantity' => 3]]]],
            'product_pages' => 100,
            'order_pages' => 2,
            'products_truncated' => true,
            'orders_truncated' => false,
        ]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.inventory-aging.store'), ['start_date' => '2026-08-01', 'end_date' => '2026-09-01', 'store_id' => 999])->assertOk()->assertSeeText('1 products · 1 variants · 1 orders')->assertSeeText('product catalogue truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<b>V</b>', false);
    }

    public function test_upstream_error_is_atomic_and_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('inventoryAgingCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.inventory-aging.store'), ['start_date' => '2026-08-01', 'end_date' => '2026-09-01'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('zero-stock recent sellers');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
