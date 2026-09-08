<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SkuDuplicatesControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_viewer_cannot_access(): void
    {
        $this->get('/reports/sku-duplicates')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/sku-duplicates')->assertForbidden();
    }

    public function test_configuration_error_prevents_call(): void
    {
        [$user] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldNotReceive('skuDuplicatesCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($user)->post(route('reports.sku-duplicates.store'))->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_results_truncation_and_xss_render_safely(): void
    {
        [$user,$store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('skuDuplicatesCandidates')->once()->with(Mockery::on(fn (Store $s): bool => $s->is($store)))->andReturn(['products' => [['legacyResourceId' => '42', 'title' => '<script>x</script>', 'vendor' => '<img src=x>', 'descriptionHtml' => '', 'images' => ['nodes' => []], 'variants' => [['sku' => '<img src=x>', 'title' => '<script>variant</script>'], ['sku' => '<img src=x>']]]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($user)->post(route('reports.sku-duplicates.store'))->assertOk()->assertSeeText('1 scanned · 1 duplicate SKUs')->assertSeeText('not a complete store inventory')->assertDontSee('<script>', false)->assertDontSee('<img', false);
    }

    public function test_error_is_atomic_and_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('skuDuplicatesCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($user)->post(route('reports.sku-duplicates.store'))->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('duplicate SKUs');
    }

    public function test_viewer_cannot_run_report(): void
    {
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->post(route('reports.sku-duplicates.store'))->assertForbidden();
    }

    public function test_form_does_not_fetch_catalogue(): void
    {
        [$user] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldNotReceive('skuDuplicatesCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($user)->get(route('reports.sku-duplicates'))->assertOk()->assertSeeText('Scan all products');
    }

    public function test_foreign_store_input_cannot_override_active_store_and_empty_result_renders(): void
    {
        [$user, $store] = $this->userWithStore(true);
        $foreign = Store::factory()->create();
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('skuDuplicatesCandidates')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)))->andReturn(['products' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($user)->withSession(['active_store_id' => $foreign->id])->post(route('reports.sku-duplicates.store'), ['store_id' => $foreign->id])->assertOk()->assertSeeText('No duplicate SKUs found in the scanned products.');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
