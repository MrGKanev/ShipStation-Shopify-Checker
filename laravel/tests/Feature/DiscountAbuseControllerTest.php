<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DiscountAbuseControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_defaults_validation_and_configuration_guard(): void
    {
        $this->get('/reports/discount-abuse')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/discount-abuse')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->get('/reports/discount-abuse')->assertOk()->assertSee('value="3"', false);
        $this->actingAs($operator)->post('/reports/discount-abuse', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'minimum_emails' => 1])->assertSessionHasErrors('minimum_emails');
        $this->actingAs($operator)->post('/reports/discount-abuse', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'minimum_emails' => 2])->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_success_truncation_xss_and_safe_failure(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $address = ['address1' => '1 Main St', 'city' => 'Austin', 'zip' => '78701', 'country_code' => 'US'];
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('discountAbuseCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-09-01', '2026-09-07')->andReturn(['orders' => [['id' => '42', 'name' => '#<script>', 'email' => 'a@x.com', 'total_price' => 10, 'discount_codes' => [['code' => 'SAVE']], 'shipping_address' => $address], ['id' => '43', 'name' => '#2', 'email' => '<img>@x.com', 'total_price' => 20, 'discount_codes' => [['code' => 'SAVE']], 'shipping_address' => $address]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/discount-abuse', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'minimum_emails' => 2])->assertOk()->assertSeeText('2 scanned · 1 suspicious clusters')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false);

        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('discountAbuseCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/discount-abuse', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'minimum_emails' => 2])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
