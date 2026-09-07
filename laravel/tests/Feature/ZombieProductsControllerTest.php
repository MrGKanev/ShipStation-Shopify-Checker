<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ZombieProductsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_viewer_cannot_access(): void
    {
        $this->get('/reports/zombie-products')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/zombie-products')->assertForbidden();
    }

    public function test_form_does_not_fetch_shopify(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('zombieProductsCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->get(route('reports.zombie-products'))->assertOk()->assertSeeText('Scan active products');
    }

    public function test_configuration_error_prevents_call(): void
    {
        [$user] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('zombieProductsCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.zombie-products.store'))->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_uses_selected_store_and_renders_truncated_results_safely(): void
    {
        [$user, $store] = $this->userWithStore(true);
        Store::factory()->create();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('zombieProductsCandidates')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)))->andReturn([
            'products' => [['legacyResourceId' => '42', 'title' => '<script>x</script>', 'vendor' => '<img src=x>', 'productType' => '<b>Type</b>', 'variants' => []]],
            'pages' => 100,
            'truncated' => true,
        ]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.zombie-products.store'), ['store_id' => 999])->assertOk()->assertSeeText('1 active products · 1 zombies')->assertSeeText('truncated after 100 product pages')->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<b>Type</b>', false);
    }

    public function test_empty_result_and_upstream_failure_are_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('zombieProductsCandidates')->once()->andReturn(['products' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.zombie-products.store'))->assertOk()->assertSeeText('All scanned active products have at least one purchasable variant.');
    }

    public function test_upstream_error_is_atomic_and_does_not_expose_message(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('zombieProductsCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.zombie-products.store'))->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('active products ·');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
