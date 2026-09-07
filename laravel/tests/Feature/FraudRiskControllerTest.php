<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FraudRiskControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_defaults_validation_and_configuration_guard(): void
    {
        $this->get('/reports/fraud-risk')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/fraud-risk')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->travelTo('2026-09-07');
        $this->actingAs($operator)->get('/reports/fraud-risk')->assertOk()->assertSee('2026-08-08')->assertSee('2026-09-07');
        $this->actingAs($operator)->post('/reports/fraud-risk', ['start_date' => 'bad', 'end_date' => '2026-09-01'])->assertSessionHasErrors('start_date');
        $this->actingAs($operator)->post('/reports/fraud-risk', ['start_date' => '2026-09-02', 'end_date' => '2026-09-01'])->assertSessionHasErrors('end_date');
        $this->actingAs($operator)->post('/reports/fraud-risk', ['start_date' => '2026-09-01', 'end_date' => '2026-09-01'])->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_success_truncation_xss_and_safe_failure(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('fraudRiskCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-09-01', '2026-09-07')->andReturn(['orders' => [['id' => '42', 'name' => '#1<script>', 'created_at' => '2026-09-02', 'email' => '<img src=x>', 'total_price' => 50, 'currency' => 'USD', 'financial_status' => 'paid', 'risk_level' => 'HIGH']], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/fraud-risk', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('1 scanned · 1 flagged')->assertSeeText('truncated after 100 pages')->assertSeeText('Shopify HIGH risk level')->assertDontSee('<script>', false)->assertDontSee('<img', false);

        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('fraudRiskCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/fraud-risk', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
