<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GiftCardsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_viewer_cannot_access(): void
    {
        $this->get('/reports/gift-cards')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/gift-cards')->assertForbidden();
    }

    public function test_form_defaults_to_thirty_days_without_fetching(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('giftCardCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->get(route('reports.gift-cards'))->assertOk()->assertSee('value="30"', false);
    }

    public function test_rejects_invalid_expiry_window(): void
    {
        [$user] = $this->userWithStore(true);

        $this->actingAs($user)->post(route('reports.gift-cards.store'), ['days' => 0])->assertSessionHasErrors('days');
    }

    public function test_configuration_error_prevents_call_and_preserves_days(): void
    {
        [$user] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('giftCardCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.gift-cards.store'), ['days' => 14])->assertOk()->assertSeeText('credentials are incomplete')->assertSee('value="14"', false);
    }

    public function test_uses_selected_store_time_and_renders_truncated_results_safely(): void
    {
        $this->travelTo('2026-05-28 12:00:00');
        [$user, $store] = $this->userWithStore(true);
        Store::factory()->create();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('giftCardCandidates')->once()->with(Mockery::on(fn (Store $selected): bool => $selected->is($store)))->andReturn(['gift_cards' => [['id' => 'gid://shopify/GiftCard/1', 'masked_code' => '<script>x</script>', 'balance' => 50.0, 'initial_value' => 50.0, 'currency' => 'USD', 'expires_on' => null, 'enabled' => true, 'created_at' => '2026-01-01', 'customer_email' => '<img src=x>']], 'pages' => 1000, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.gift-cards.store'), ['days' => 14, 'store_id' => 999])->assertOk()->assertSeeText('1 gift cards · 1 flagged')->assertSeeText('Never redeemed')->assertSeeText('truncated after 1000 gift card pages')->assertDontSee('<script>', false)->assertDontSee('<img', false);
    }

    public function test_upstream_error_is_atomic_and_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('giftCardCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.gift-cards.store'), ['days' => 30])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('gift cards ·');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
