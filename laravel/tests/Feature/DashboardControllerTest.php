<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_selects_and_renders_the_users_first_store(): void
    {
        $user = User::factory()->operator()->create(['name' => 'Operations User']);
        $secondStore = Store::factory()->create(['label' => 'Zulu Store']);
        $firstStore = Store::factory()->create([
            'label' => 'Alpha Store',
            'shopify_store' => 'alpha-shop',
        ]);
        $user->stores()->attach([$secondStore->getKey(), $firstStore->getKey()]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSeeText('Alpha Store')
            ->assertSeeText('alpha-shop.myshopify.com')
            ->assertSessionHas('active_store_id', $firstStore->getKey());
        $this->assertSame(UserRole::Operator, $user->fresh()->role);
    }

    public function test_dashboard_forbids_a_user_without_store_access(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertForbidden();
    }

    public function test_dashboard_escapes_store_labels(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'label' => '<script>alert("store")</script>',
        ]);
        $user->stores()->attach($store);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee($store->label)
            ->assertDontSee($store->label, false);
    }
}
