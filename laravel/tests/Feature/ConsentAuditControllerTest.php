<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ConsentAuditControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_defaults_validation_and_configuration_guard(): void
    {
        $this->get('/reports/consent-audit')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/consent-audit')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->travelTo('2026-09-07');
        $this->actingAs($operator)->get('/reports/consent-audit')->assertOk()->assertSee('2026-08-08')->assertSee('2026-09-07');
        $this->actingAs($operator)->post('/reports/consent-audit', ['start_date' => 'bad', 'end_date' => '2026-09-01'])->assertSessionHasErrors('start_date');
        $this->actingAs($operator)->post('/reports/consent-audit', ['start_date' => '2026-09-01', 'end_date' => '2026-09-01'])->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_success_truncation_xss_and_safe_failure(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('consentAuditCandidates')->once()->with(Mockery::on(fn (Store $s): bool => $s->is($store)), '2026-09-01', '2026-09-07')->andReturn(['orders' => [['id' => 42, 'name' => '#1<script>', 'created_at' => '2026-09-02', 'email' => '<img src=x>', 'customer_email_consent' => 'not_subscribed', 'customer_sms_consent' => 'pending', 'total_price' => 20, 'currency' => 'USD']], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/consent-audit', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('1 scanned · 1 without active email consent')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img', false);

        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('consentAuditCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/consent-audit', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
