<?php

namespace Tests\Feature;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DuplicateAddressControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_access_validation_configuration_success_and_safe_failure(): void
    {
        $this->get('/reports/duplicate-addresses')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/reports/duplicate-addresses')->assertForbidden();
        [$operator] = $this->userWithStore(true, ['shopify_access_token' => '']);
        $this->actingAs($operator)->post('/reports/duplicate-addresses', ['start_date' => 'bad', 'end_date' => '2026-09-01'])->assertSessionHasErrors('start_date');
        $this->actingAs($operator)->post('/reports/duplicate-addresses', ['start_date' => '2026-09-01', 'end_date' => '2026-09-01'])->assertOk()->assertSeeText('credentials are incomplete');
        [$operator, $store] = $this->userWithStore(true);
        $address = ['address1' => '1 Main', 'city' => 'X', 'zip' => '1', 'country_code' => 'US'];
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('addressCheckCandidates')->once()->with(Mockery::on(fn (Store $value): bool => $value->is($store)), '2026-09-01', '2026-09-07', false)->andReturn(['orders' => [['id' => '42', 'name' => '#<script>', 'email' => 'a@x.com', 'shipping_address' => $address], ['id' => '43', 'name' => '#2', 'email' => '<img>@x.com', 'shipping_address' => $address]], 'pages' => 100, 'truncated' => true]);
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/duplicate-addresses', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('2 scanned · 1 shared addresses')->assertSeeText('truncated after 100 pages')->assertDontSee('<script>', false)->assertDontSee('<img>', false);
        $gateway = Mockery::mock(ShopifyAdminGateway::class);
        $gateway->shouldReceive('addressCheckCandidates')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyAdminGateway::class, $gateway);
        $this->actingAs($operator)->post('/reports/duplicate-addresses', ['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->assertOk()->assertSeeText('could not be completed')->assertDontSeeText('secret');
    }

    private function userWithStore(bool $operator = false, array $attributes = []): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create($attributes);
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
