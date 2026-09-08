<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReturnedItemsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/returned-items')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/returned-items')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post('/reports/returned-items', ['start_date' => 'bad', 'end_date' => '2026-07-01'])->assertSessionHasErrors('start_date');
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/returned-items', ['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])->assertOk()->assertSeeText('credentials are incomplete');

        [$operator, $store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('returnedItemCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-07-01')->andReturn(['orders' => [['refunds' => [['created_at' => '2026-07-10T00:00:00Z', 'refund_line_items' => [['quantity' => 2, 'line_item' => ['name' => '<script>']]]]]]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/returned-items', ['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])->assertOk()->assertSeeText('1 orders scanned · 1 products')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false);

        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('returnedItemCandidates')->andThrow(new RuntimeException('secret-token'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/returned-items', ['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret-token');
    }

    public function test_operator_can_download_formula_safe_csv(): void
    {
        [$operator] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('returnedItemCandidates')->once()->andReturn(['orders' => [['refunds' => [['created_at' => '2026-07-10T00:00:00Z', 'refund_line_items' => [['quantity' => 2, 'line_item' => ['name' => '=HYPERLINK("bad")']]]]]]], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $response = $this->actingAs($operator)->post(route('reports.returned-items.export'), ['start_date' => '2026-07-01', 'end_date' => '2026-07-31']);
        $content = $response->streamedContent();

        $response->assertOk()->assertDownload('returned-items-2026-07-01-to-2026-07-31.csv');
        $this->assertStringContainsString("'=HYPERLINK", $content);
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
