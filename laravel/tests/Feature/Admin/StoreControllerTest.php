<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StoreControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_administrator_can_render_store_forms_without_exposing_credentials(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create([
            'shopify_access_token' => 'hidden-token',
            'shipstation_api_key' => 'hidden-key',
        ]);
        $admin->stores()->attach($store);

        $this->actingAs($admin)
            ->get(route('admin.stores.create'))
            ->assertOk()
            ->assertSeeText('Add store');

        $this->actingAs($admin)
            ->get(route('admin.stores.edit', $store))
            ->assertOk()
            ->assertSeeText('Edit '.$store->label)
            ->assertDontSee('hidden-token')
            ->assertDontSee('hidden-key');
    }

    public function test_administrator_can_render_the_store_list_without_exposing_credentials(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create([
            'label' => '<script>alert("store")</script>',
            'shopify_access_token' => 'hidden-token',
        ]);
        $admin->stores()->attach($store);

        $response = $this->actingAs($admin)->get(route('admin.stores.index'));

        $response
            ->assertOk()
            ->assertSee($store->label)
            ->assertDontSee($store->label, false)
            ->assertDontSee('hidden-token');
    }

    public function test_administrator_can_create_a_normalized_store_and_receives_access(): void
    {
        $admin = User::factory()->admin()->create();
        $currentStore = Store::factory()->create();
        $admin->stores()->attach($currentStore);

        $response = $this->actingAs($admin)->post(route('admin.stores.store'), [
            'slug' => ' New-Store ',
            'label' => 'New Store',
            'shopify_store' => 'New-Shop.myshopify.com',
            'shopify_access_token' => 'shopify-token',
            'shipstation_api_key' => 'shipstation-key',
            'shipstation_api_secret' => 'shipstation-secret',
            'store_number' => '10001',
            'unexpected' => 'must-not-be-saved',
        ]);

        $store = Store::query()->where('slug', 'new-store')->firstOrFail();
        $response
            ->assertRedirect(route('admin.stores.edit', $store))
            ->assertSessionHas('status', 'Store created.');
        $this->assertSame('new-shop', $store->shopify_store);
        $this->assertSame('shopify-token', $store->shopify_access_token);
        $this->assertTrue($admin->stores()->whereKey($store)->exists());
        $this->assertArrayNotHasKey('unexpected', $store->getAttributes());
    }

    public function test_store_creation_rejects_missing_and_invalid_values(): void
    {
        $admin = User::factory()->admin()->create();
        $currentStore = Store::factory()->create();
        $admin->stores()->attach($currentStore);

        $response = $this->from(route('admin.stores.create'))
            ->actingAs($admin)
            ->post(route('admin.stores.store'), [
                'slug' => 'invalid slug',
                'shopify_store' => 'invalid domain.example.com',
            ]);

        $response
            ->assertRedirect(route('admin.stores.create'))
            ->assertSessionHasErrors([
                'slug' => 'The slug field must only contain letters, numbers, dashes, and underscores.',
                'label' => 'The label field is required.',
                'shopify_store' => 'The shopify store field must only contain letters, numbers, dashes, and underscores.',
                'shopify_access_token' => 'The shopify access token field is required.',
            ]);
        $this->assertSame(1, Store::query()->count());
    }

    public function test_store_creation_rejects_duplicate_identifiers(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create([
            'slug' => 'existing-store',
            'shopify_store' => 'existing-shop',
        ]);
        $admin->stores()->attach($store);

        $response = $this->actingAs($admin)->post(route('admin.stores.store'), [
            'slug' => 'existing-store',
            'label' => 'Duplicate Store',
            'shopify_store' => 'existing-shop.myshopify.com',
            'shopify_access_token' => 'shopify-token',
        ]);

        $response->assertSessionHasErrors([
            'slug' => 'The slug has already been taken.',
            'shopify_store' => 'The shopify store has already been taken.',
        ]);
        $this->assertSame(1, Store::query()->count());
    }

    public function test_store_update_keeps_blank_credentials_and_can_replace_selected_credentials(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create([
            'shopify_access_token' => 'original-shopify-token',
            'shipstation_api_key' => 'original-key',
            'shipstation_api_secret' => 'original-secret',
        ]);
        $admin->stores()->attach($store);

        $response = $this->actingAs($admin)->put(route('admin.stores.update', $store), [
            'slug' => $store->slug,
            'label' => 'Renamed Store',
            'shopify_store' => $store->shopify_store,
            'shopify_access_token' => '',
            'shipstation_api_key' => 'replacement-key',
            'shipstation_api_secret' => '',
            'store_number' => '',
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('status', 'Store updated.');
        $store->refresh();
        $this->assertSame('Renamed Store', $store->label);
        $this->assertSame('original-shopify-token', $store->shopify_access_token);
        $this->assertSame('replacement-key', $store->shipstation_api_key);
        $this->assertSame('original-secret', $store->shipstation_api_secret);
        $this->assertNull($store->store_number);
    }
}
