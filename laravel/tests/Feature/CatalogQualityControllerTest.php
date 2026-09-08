<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CatalogQualityControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_viewer_cannot_access(): void
    {
        $this->get('/reports/catalog-quality')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/catalog-quality')->assertForbidden();
    }

    public function test_form_does_not_fetch_shopify(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('catalogQualityCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->get(route('reports.catalog-quality'))->assertOk()->assertSeeText('Scan active products');
    }

    public function test_configuration_error_prevents_call(): void
    {
        [$user] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('catalogQualityCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.catalog-quality.store'))->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_uses_selected_store_and_renders_truncated_results_safely(): void
    {
        [$user, $store] = $this->userWithStore(true);
        Store::factory()->create();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('catalogQualityCandidates')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)))->andReturn([
            'products' => [['legacyResourceId' => '42', 'title' => '<script>x</script>', 'vendor' => '<img src=x>', 'productType' => '<b>Type</b>', 'onlineStoreUrl' => null, 'seo' => ['title' => '', 'description' => ''], 'collections' => ['nodes' => []]]],
            'pages' => 100,
            'truncated' => true,
        ]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.catalog-quality.store'), ['store_id' => 999])->assertOk()->assertSeeText('1 active products · 1 with quality issues')->assertSeeText('Not published to Online Store')->assertSeeText('Missing SEO title')->assertSeeText('Missing SEO description')->assertSeeText('Not in any collection')->assertSeeText('truncated after 100 product pages')->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<b>Type</b>', false);
    }

    public function test_empty_result_renders_success_state(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('catalogQualityCandidates')->once()->andReturn(['products' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.catalog-quality.store'))->assertOk()->assertSeeText('All scanned active products are published, have SEO fields, and belong to a collection.');
    }

    public function test_upstream_error_is_atomic_and_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('catalogQualityCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.catalog-quality.store'))->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('active products ·');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
