<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RepeatRefundControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/repeat-refunds')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/repeat-refunds')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/repeat-refunds', ['start_date' => 'bad', 'end_date' => '2026-01-01', 'minimum' => 1])->assertSessionHasErrors(['start_date', 'minimum']);
        $this->actingAs($operator)->post('/reports/repeat-refunds', ['start_date' => '2026-01-01', 'end_date' => '2026-01-02', 'minimum' => 2])->assertOk()->assertSeeText('credentials are incomplete');
        [$operator, $store] = $this->userWithStore(true);
        $orders = [['email' => '<img>@x.com', 'name' => '#<script>', 'refunds' => []], ['email' => '<img>@x.com', 'name' => '#2', 'refunds' => []]];
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('repeatRefundCandidates')->once()->with(Mockery::on(fn (Store $value): bool => $value->is($store)), '2026-01-01', '2026-01-31')->andReturn(['orders' => $orders, 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/repeat-refunds', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'minimum' => 2])->assertOk()->assertSeeText('2 scanned · 1 repeat customers')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('repeatRefundCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/repeat-refunds', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'minimum' => 2])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
