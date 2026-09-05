<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ActiveStoreControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_cannot_change_the_active_store(): void
    {
        $store = Store::factory()->create();

        $response = $this->post(route('stores.active', $store));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_change_to_an_accessible_store(): void
    {
        $user = User::factory()->create();
        $currentStore = Store::factory()->create();
        $nextStore = Store::factory()->create();
        $user->stores()->attach([$currentStore->getKey(), $nextStore->getKey()]);

        $response = $this
            ->actingAs($user)
            ->withSession(['active_store_id' => $currentStore->getKey()])
            ->post(route('stores.active', $nextStore));

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('active_store_id', $nextStore->getKey());
    }

    public function test_user_receives_not_found_for_an_inaccessible_store(): void
    {
        $user = User::factory()->create();
        $accessibleStore = Store::factory()->create();
        $inaccessibleStore = Store::factory()->create();
        $user->stores()->attach($accessibleStore);

        $response = $this
            ->actingAs($user)
            ->withSession(['active_store_id' => $accessibleStore->getKey()])
            ->post(route('stores.active', $inaccessibleStore));

        $response
            ->assertNotFound()
            ->assertSessionHas('active_store_id', $accessibleStore->getKey());
    }
}
