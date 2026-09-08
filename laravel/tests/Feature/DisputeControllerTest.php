<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class DisputeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_configuration_and_success(): void
    {
        $this->get('/reports/disputes')->assertRedirect(route('login'));
        $viewer = User::factory()->create();
        $store = Store::factory()->create();
        $viewer->stores()->attach($store);
        $this->actingAs($viewer)->get('/reports/disputes')->assertForbidden();
        $operator = User::factory()->operator()->create();
        $store = Store::factory()->create(['shopify_access_token' => '']);
        $operator->stores()->attach($store);
        $this->actingAs($operator)->post('/reports/disputes')->assertOk()->assertSeeText('credentials are incomplete');
        $operator = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $operator->stores()->attach($store);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('openDisputes')->once()->with(Mockery::on(fn (Store $value): bool => $value->is($store)))->andReturn(['disputes' => [['order_id' => '42', 'order_name' => '#<script>', 'status' => 'needs_response', 'reason' => 'fraudulent', 'amount' => 50, 'currency' => 'USD', 'initiated_at' => '2026-09-01', 'evidence_due_by' => null]], 'pages' => 20, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/disputes')->assertOk()->assertSeeText('1 open disputes')->assertSeeText('truncated after 20 pages')->assertDontSee('<script>', false);
    }
}
