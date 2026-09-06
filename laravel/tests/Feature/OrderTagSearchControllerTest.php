<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrderTagSearchControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_redirects_and_user_without_a_store_is_forbidden(): void
    {
        $this->get('/orders/tag-search')->assertRedirect(route('login'));
        $this->post('/orders/tag-search')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get('/orders/tag-search')->assertForbidden();
    }

    public function test_initial_form_supports_an_escaped_prefill_without_calling_shopify(): void
    {
        [$user] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('searchOrdersByTag');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->get(route('orders.tag-search', ['tag' => '<script>x</script>']))
            ->assertOk()->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>', false);
    }

    public function test_invalid_tags_and_dates_are_rejected_before_shopify_is_called(): void
    {
        [$user] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('searchOrdersByTag');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        foreach ([
            ['tag' => ''], ['tag' => ['vip']], ['tag' => str_repeat('a', 256)], ['tag' => "vip\nadmin"],
            ['tag' => 'vip', 'start_date' => '2026-02-30'], ['tag' => 'vip', 'end_date' => '09/06/2026'],
            ['tag' => 'vip', 'start_date' => '2026-09-06', 'end_date' => '2026-09-01'],
        ] as $payload) {
            $this->from(route('orders.tag-search'))->actingAs($user)->post(route('orders.tag-search.store'), $payload)
                ->assertRedirect(route('orders.tag-search'))->assertSessionHasErrors();
        }
    }

    public function test_selected_store_results_empty_state_highlighting_links_and_truncation_are_rendered_safely(): void
    {
        $user = User::factory()->create();
        $otherStore = Store::factory()->create();
        $selectedStore = Store::factory()->create(['shopify_store' => 'selected']);
        $user->stores()->attach([$otherStore->getKey(), $selectedStore->getKey()]);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('searchOrdersByTag')->once()->with(Mockery::on(fn (Store $store): bool => $store->is($selectedStore)), 'VIP', '2026-09-01', null)->andReturn([
            'orders' => [[
                'id' => 42, 'order_number' => 1001, 'name' => '#1001<script>x</script>', 'created_at' => '2026-09-05T10:00:00Z',
                'email' => '<img src=x>', 'tags' => ['vip', '<script>tag</script>'], 'financial_status' => 'paid',
                'fulfillment_status' => 'partial', 'total_price' => '10.00', 'currency' => '<b>EUR</b>',
            ]], 'pages' => 20, 'truncated' => true,
        ]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $response = $this->actingAs($user)->withSession(['active_store_id' => $selectedStore->getKey()])->post(route('orders.tag-search.store'), ['tag' => 'VIP', 'start_date' => '2026-09-01']);

        $response->assertOk()->assertSeeText('1 found')->assertSeeText('Results were truncated after 20 pages')->assertSee('https://selected.myshopify.com/admin/orders/42', false)
            ->assertSee(route('orders.spot-check', ['prefill' => 1001]), false)->assertSeeText('vip')->assertSee('&lt;script&gt;tag&lt;/script&gt;', false)
            ->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<b>EUR</b>', false)->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_zero_results_and_upstream_failure_have_clear_atomic_states(): void
    {
        [$user] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('searchOrdersByTag')->once()->andReturn(['orders' => [], 'pages' => 1, 'truncated' => false]);
        $shopify->shouldReceive('searchOrdersByTag')->once()->andThrow(new RuntimeException('private-token'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('orders.tag-search.store'), ['tag' => 'missing'])->assertOk()->assertSeeText('No orders found with this tag.');
        $this->actingAs($user)->post(route('orders.tag-search.store'), ['tag' => 'broken'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('private-token')->assertDontSeeText('Results');
    }

    public function test_missing_shopify_configuration_stops_before_the_gateway(): void
    {
        [$user] = $this->userWithStore(['shopify_access_token' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('searchOrdersByTag');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('orders.tag-search.store'), ['tag' => 'vip'])
            ->assertOk()->assertSeeText('Shopify is not configured completely for this store.');
    }

    public function test_search_is_rate_limited_per_user_and_ip(): void
    {
        [$user] = $this->userWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('searchOrdersByTag')->times(10)->andReturn(['orders' => [], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        foreach (range(1, 10) as $attempt) {
            $this->actingAs($user)->post(route('orders.tag-search.store'), ['tag' => 'vip'])->assertOk();
        }

        $this->actingAs($user)->post(route('orders.tag-search.store'), ['tag' => 'vip'])->assertTooManyRequests();
    }

    private function userWithStore(array $storeAttributes = []): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create($storeAttributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
