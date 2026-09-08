<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CountryMismatchControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_and_date_validation(): void
    {
        $this->get('/reports/country-mismatch')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/country-mismatch')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('reports.country-mismatch.store'), ['start_date' => '2026-09-06', 'end_date' => '2026-09-01'])->assertSessionHasErrors('end_date');
    }

    public function test_selected_store_rows_missing_count_and_xss_are_rendered_safely(): void
    {
        [$user,$store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('countryMismatchCandidates')->once()->with(Mockery::on(fn (Store $s): bool => $s->is($store)), '2026-09-01', '2026-09-06')->andReturn(['orders' => [['id' => 42, 'name' => '#1<script>x</script>', 'created_at' => '2026-09-02', 'email' => '<img src=x>', 'total_price' => '10.25', 'currency' => 'EUR', 'financial_status' => 'paid', 'billing_address' => ['country_code' => 'BG', 'first_name' => '<b>Ada</b>'], 'shipping_address' => ['country_code' => 'US']], ['id' => 43, 'name' => '#2', 'billing_address' => null, 'shipping_address' => ['country_code' => 'US']]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($user)->post(route('reports.country-mismatch.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-09-06'])->assertOk()->assertSeeText('2 scanned · 1 mismatches')->assertSeeText('1 skipped because')->assertSeeText('10.25 EUR')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<b>Ada</b>', false);
    }

    public function test_upstream_error_is_atomic_and_safe(): void
    {
        [$user] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('countryMismatchCandidates')->once()->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($user)->post(route('reports.country-mismatch.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-09-06'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret')->assertDontSeeText('Results');
    }

    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
