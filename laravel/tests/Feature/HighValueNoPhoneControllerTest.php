<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HighValueNoPhoneControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_redirects_viewer_is_forbidden_and_operator_sees_defaults(): void
    {
        $this->get('/reports/high-value-no-phone')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/high-value-no-phone')->assertForbidden();
        [$operator] = $this->userWithStore(operator: true);
        $this->travelTo('2026-09-06');

        $this->actingAs($operator)->get(route('reports.high-value-no-phone'))->assertOk()->assertSee('value="2026-08-07"', false)->assertSee('value="2026-09-06"', false)->assertSee('value="200"', false);
    }

    public function test_invalid_input_is_rejected_before_the_gateway(): void
    {
        [$user] = $this->userWithStore(operator: true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('highValueOrderCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        foreach ([['start_date' => 'bad', 'end_date' => '2026-09-01', 'minimum' => 200, 'currency' => 'USD'], ['start_date' => '2026-09-06', 'end_date' => '2026-09-01', 'minimum' => 200, 'currency' => 'USD'], ['start_date' => '2026-09-01', 'end_date' => '2026-09-06', 'minimum' => -1, 'currency' => 'USD'], ['start_date' => '2026-09-01', 'end_date' => '2026-09-06', 'minimum' => 200, 'currency' => 'US']] as $payload) {
            $this->actingAs($user)->post(route('reports.high-value-no-phone.store'), $payload)->assertSessionHasErrors();
        }
    }

    public function test_selected_store_report_renders_safe_rows_empty_state_and_truncation(): void
    {
        [$user, $store] = $this->userWithStore(operator: true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('highValueOrderCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-09-01', '2026-09-06')->andReturn(['orders' => [['id' => 42, 'name' => '#1001<script>x</script>', 'created_at' => '2026-09-02', 'email' => '<img src=x>', 'total_price' => '500.50', 'currency' => 'USD', 'shipping_address' => ['phone' => '', 'first_name' => '<b>Ada</b>', 'address1' => '1 Main']]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $response = $this->actingAs($user)->post(route('reports.high-value-no-phone.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-09-06', 'minimum' => '200.25', 'currency' => 'usd']);

        $response->assertOk()->assertSeeText('1 scanned · 1 issues')->assertSeeText('500.50 USD')->assertSeeText('truncated after 100 pages')->assertSee('rel="noopener noreferrer"', false)->assertDontSee('<script>', false)->assertDontSee('<img', false)->assertDontSee('<b>Ada</b>', false);
    }

    public function test_upstream_failure_is_atomic_and_does_not_leak_details(): void
    {
        [$user] = $this->userWithStore(operator: true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('highValueOrderCandidates')->once()->andThrow(new RuntimeException('private-token'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($user)->post(route('reports.high-value-no-phone.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-09-06', 'minimum' => 200, 'currency' => 'USD'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('private-token')->assertDontSeeText('Results');
    }

    private function userWithStore(bool $operator = false): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
