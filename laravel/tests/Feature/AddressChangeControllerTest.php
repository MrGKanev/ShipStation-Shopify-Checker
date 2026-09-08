<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AddressChangeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_defaults_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/address-changes')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/address-changes')->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->travelTo('2026-09-08 12:00:00');
        $this->actingAs($operator)->get('/reports/address-changes')->assertOk()->assertSee('value="2026-08-09"', false)->assertSee('value="2026-09-08"', false);
        $this->actingAs($operator)->post('/reports/address-changes', ['start_date' => 'bad', 'end_date' => '2026-01-01'])->assertSessionHasErrors(['start_date']);
        $this->actingAs($operator)->post('/reports/address-changes', ['start_date' => '2026-01-02', 'end_date' => '2026-01-01'])->assertSessionHasErrors(['end_date']);
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/address-changes', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('credentials are incomplete');

        [$operator, $store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('addressChangeCandidates')->once()->with(Mockery::on(fn (Store $value): bool => $value->is($store)), '2026-01-01', '2026-01-31')->andReturn(['events' => [['subject_id' => 1, 'message' => 'Shipping address was updated', 'created_at' => '2026-01-02T11:30:00Z']], 'orders' => ['1' => ['name' => '#<script>', 'created_at' => '2026-01-02T10:00:00Z', 'email' => '<img>@x.com', 'shipping_address' => ['first_name' => '<b>Jane</b>', 'city' => '<svg>']]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/address-changes', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('1 orders with address changes')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false)->assertDontSee('<svg>', false);

        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('addressChangeCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/address-changes', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    public function test_operator_can_download_formula_safe_csv_for_the_active_store(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('addressChangeCandidates')->once()->with(Mockery::on(fn (Store $value): bool => $value->is($store)), '2026-01-01', '2026-01-31')->andReturn(['events' => [['subject_id' => 1, 'message' => 'Shipping address was updated', 'created_at' => '2026-01-02T11:30:00Z']], 'orders' => ['1' => ['name' => '=HYPERLINK("bad")', 'created_at' => '2026-01-02T10:00:00Z', 'email' => '+cmd@example.com', 'shipping_address' => ['first_name' => 'Jane', 'city' => 'Boston']]], 'pages' => 1, 'truncated' => false]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);

        $response = $this->actingAs($operator)->post(route('reports.address-changes.export'), ['start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
        $content = $response->streamedContent();

        $response->assertOk()->assertDownload('address-changes-2026-01-01-to-2026-01-31.csv');
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringContainsString("'+cmd@example.com", $content);
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
