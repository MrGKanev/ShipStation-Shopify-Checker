<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TaxAuditControllerTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use LazilyRefreshDatabase;

    public function test_access_and_validation(): void
    {
        $this->get('/reports/tax-audit')->assertRedirect(route('login'));
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);
        $this->actingAs($user)->get('/reports/tax-audit')->assertOk();
        $this->actingAs($user)->post('/reports/tax-audit', ['start_date' => '2026-09-02', 'end_date' => '2026-09-01', 'minimum' => -1])->assertSessionHasErrors(['end_date', 'minimum']);
    }

    public function test_configuration_guard_success_truncation_xss_and_safe_failure(): void
    {
        [$user] = $this->operatorWithStore(['shopify_access_token' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('taxAuditCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $payload = ['start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'minimum' => 5];
        $this->actingAs($user)->post('/reports/tax-audit', $payload)->assertOk()->assertSeeText('credentials are incomplete');

        [$user, $store] = $this->operatorWithStore();
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('taxAuditCandidates')->once()->with(Mockery::on(fn (Store $s): bool => $s->is($store)), '2026-09-01', '2026-09-07')->andReturn(['orders' => [['id' => 42, 'name' => '#1<script>', 'created_at' => '2026-09-02', 'email' => '<img src=x>', 'total_price' => 50, 'total_tax' => 0, 'customer_tax_exempt' => false, 'currency' => 'USD']], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($user)->post('/reports/tax-audit', $payload)->assertOk()->assertSeeText('1 scanned · 1 zero-tax orders')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img', false);

        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('taxAuditCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($user)->post('/reports/tax-audit', $payload)->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function operatorWithStore(array $attributes = []): array
    {
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
