<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AddressCheckControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_and_configuration_guard(): void
    {
        $this->get('/reports/address-check')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/address-check')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/address-check', ['start_date' => 'bad', 'end_date' => '2026-09-01'])->assertSessionHasErrors('start_date');
        $this->actingAs($operator)->post('/reports/address-check', ['start_date' => '2026-09-01', 'end_date' => '2026-09-01'])->assertOk()->assertSeeText('credentials are incomplete');
    }

    public function test_filters_success_truncation_xss_and_safe_failure(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('addressCheckCandidates')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '2026-09-01', '2026-09-07', true)->andReturn(['orders' => [['id' => '42', 'name' => '#1<script>', 'email' => '<img src=x>', 'shipping_address' => ['first_name' => 'A', 'last_name' => 'B', 'address1' => 'PO Box 2', 'city' => 'X', 'zip' => '12345', 'country_code' => 'US', 'province_code' => 'CA']]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/address-check', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'po_box_only' => '1', 'unfulfilled_only' => '1'])->assertOk()->assertSeeText('1 scanned · 0 critical · 1 warnings')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img', false);

        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('addressCheckCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $shopify);
        $this->actingAs($operator)->post('/reports/address-check', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
