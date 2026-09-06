<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TagAuditControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_and_viewer_is_forbidden(): void
    {
        $this->get('/reports/tag-audit')->assertRedirect(route('login'));

        [$viewer] = $this->userWithStore();

        $this->actingAs($viewer)->get('/reports/tag-audit')->assertForbidden();
    }

    public function test_initial_page_uses_a_ninety_day_range(): void
    {
        $this->travelTo('2026-09-06 12:00:00');
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('reports.tag-audit'))
            ->assertOk()
            ->assertSee('value="2026-06-08"', false)
            ->assertSee('value="2026-09-06"', false);
    }

    public function test_invalid_date_range_returns_the_specific_validation_message(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->post(route('reports.tag-audit.store'), ['start_date' => '2026-09-06', 'end_date' => '2026-09-01'])
            ->assertSessionHasErrors(['end_date' => 'The end date must be on or after the start date.']);
    }

    public function test_incomplete_shopify_configuration_does_not_run_the_report(): void
    {
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldNotReceive('tagAuditCandidates');
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($operator)->post(route('reports.tag-audit.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-09-06'])
            ->assertOk()
            ->assertSeeText('Shopify credentials are incomplete');
    }

    public function test_selected_store_results_orphans_truncation_and_xss_are_rendered_safely(): void
    {
        $this->travelTo('2026-09-06 12:00:00');
        [$operator, $store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('tagAuditCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-05-01', '2026-09-06')->andReturn([
            'orders' => [
                ['name' => '#1<script>x</script>', 'createdAt' => '2026-05-01T10:00:00Z', 'tags' => ['VIP<img src=x>']],
                ['name' => '#2', 'createdAt' => '2026-09-01T10:00:00Z', 'tags' => ['Rush']],
            ],
            'pages' => 100,
            'truncated' => true,
        ]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($operator)->post(route('reports.tag-audit.store'), ['start_date' => '2026-05-01', 'end_date' => '2026-09-06'])
            ->assertOk()
            ->assertSeeText('2 scanned · 2 unique tags')
            ->assertSeeText('orphan')
            ->assertSeeText('truncated after 100 pages')
            ->assertSee(route('orders.tag-search', ['tag' => 'VIP<img src=x>']), false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('<img', false);
    }

    public function test_upstream_error_is_atomic_and_does_not_expose_details(): void
    {
        [$operator] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('tagAuditCandidates')->once()->andThrow(new RuntimeException('private-token'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);

        $this->actingAs($operator)->post(route('reports.tag-audit.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-09-06'])
            ->assertOk()
            ->assertSeeText('could not be completed')
            ->assertDontSeeText('private-token')
            ->assertDontSeeText('unique tags');
    }

    /** @param array<string, mixed> $storeAttributes @return array{User, Store} */
    private function userWithStore(bool $operator = false, array $storeAttributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($storeAttributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
