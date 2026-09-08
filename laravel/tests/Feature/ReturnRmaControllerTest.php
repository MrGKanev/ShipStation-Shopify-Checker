<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReturnRmaControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_and_configuration_are_enforced(): void
    {
        $this->get('/reports/return-rma')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/return-rma')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/return-rma', ['start_date' => 'bad', 'end_date' => '2026-01-01'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/return-rma', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('Shopify credentials are incomplete');
    }

    public function test_success_is_store_scoped_escaped_and_reports_truncation(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('refundTrackerCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-01-01', '2026-01-31')->andReturn([
            'orders' => [['id' => '1', 'name' => '#<script>', 'email' => 'a@example.com', 'financial_status' => 'refunded', 'refunds' => [['created_at' => '2026-01-02', 'note' => '<img>', 'total_refunded' => 20, 'refund_line_items' => [['quantity' => 1, 'subtotal' => 20, 'line_item' => ['name' => '<svg>', 'sku' => 'SKU-1']]]]]]],
            'pages' => 100,
            'truncated' => true,
        ]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $this->actingAs($operator)->post('/reports/return-rma', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])
            ->assertOk()->assertSeeText('1 refunds from 1 orders')->assertSeeText('Return Rate by SKU')->assertSeeText('truncated after 100 pages')
            ->assertDontSee('<script>', false)->assertDontSee('<img>', false)->assertDontSee('<svg>', false);
    }

    public function test_upstream_failure_is_safe(): void
    {
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('refundTrackerCandidates')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $this->actingAs($operator)->post('/reports/return-rma', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])
            ->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
