<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TagPolicyControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_defaults_validation_and_unconfigured_short_circuit(): void
    {
        $this->get('/reports/tag-policy')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/tag-policy')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->travelTo('2026-09-07');
        $this->actingAs($operator)->get('/reports/tag-policy')->assertOk()->assertSee('2026-08-08')->assertSeeText('No tag policy is configured');
        $this->actingAs($operator)->post('/reports/tag-policy', ['start_date' => 'bad', 'end_date' => '2026-09-01'])->assertSessionHasErrors('start_date');
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('tagPolicyCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/tag-policy', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('No tag policy is configured')->assertDontSeeText('credentials are incomplete');
    }

    public function test_configured_credentials_success_truncation_xss_and_safe_failure(): void
    {
        config()->set('tag-policy.required', [['name' => 'VIP policy', 'when' => ['vip'], 'must_have' => ['priority']]]);
        [$missing] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($missing)->post('/reports/tag-policy', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('credentials are incomplete');
        [$operator, $store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('tagPolicyCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-09-01', '2026-09-07')->andReturn(['orders' => [['id' => '42', 'name' => '#<script>', 'created_at' => '2026-09-02', 'email' => '<img>@x.com', 'tags' => ['vip']]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/tag-policy', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('1 scanned · 1 policy violations')->assertSeeText('VIP policy')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('tagPolicyCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/tag-policy', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
