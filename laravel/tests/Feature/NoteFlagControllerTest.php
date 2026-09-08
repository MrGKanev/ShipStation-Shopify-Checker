<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class NoteFlagControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_and_success(): void
    {
        $this->get('/reports/note-flags')->assertRedirect(route('login'));
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);
        $this->actingAs($user)->post('/reports/note-flags', ['start_date' => '2026-01-01', 'end_date' => '2026-01-02', 'keywords' => ' , '])->assertSessionHasErrors('keywords');
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('noteFlagCandidates')->once()->andReturn(['orders' => [['id' => '1', 'name' => '#<script>', 'created_at' => '2026-01-01', 'email' => '<img>@x.com', 'note' => 'Please HOLD']], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($user)->post('/reports/note-flags', ['start_date' => '2026-01-01', 'end_date' => '2026-01-02', 'keywords' => 'hold'])->assertOk()->assertSeeText('1 scanned · 1 flagged notes')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false);
    }
}
