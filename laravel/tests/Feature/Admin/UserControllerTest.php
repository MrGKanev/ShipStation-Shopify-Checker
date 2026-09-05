<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_administrator_can_render_the_user_list_with_escaped_content(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        $user = User::factory()->create(['name' => '<script>alert("user")</script>']);
        $user->stores()->attach($store);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response
            ->assertOk()
            ->assertSee($user->name)
            ->assertDontSee($user->name, false);
    }

    public function test_administrator_can_create_a_user_with_store_access(): void
    {
        $admin = User::factory()->admin()->create();
        $firstStore = Store::factory()->create();
        $secondStore = Store::factory()->create();
        $admin->stores()->attach($firstStore);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Operations User',
            'email' => ' OPERATOR@EXAMPLE.COM ',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
            'role' => UserRole::Operator->value,
            'store_ids' => [$firstStore->getKey(), $secondStore->getKey()],
            'unexpected' => 'must-not-be-saved',
        ]);

        $user = User::query()->where('email', 'operator@example.com')->firstOrFail();
        $response
            ->assertRedirect(route('admin.users.edit', $user))
            ->assertSessionHas('status', 'User created.');
        $this->assertSame(UserRole::Operator, $user->role);
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertSame(
            [$firstStore->getKey(), $secondStore->getKey()],
            $user->stores()->orderBy('stores.id')->pluck('stores.id')->all(),
        );
        $this->assertArrayNotHasKey('unexpected', $user->getAttributes());
    }

    public function test_user_creation_rejects_invalid_role_password_and_store_access(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);

        $response = $this->from(route('admin.users.create'))
            ->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Invalid User',
                'email' => 'invalid@example.com',
                'password' => 'short',
                'password_confirmation' => 'different',
                'role' => 'owner',
                'store_ids' => [999999],
            ]);

        $response
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors(['password', 'role', 'store_ids.0']);
        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.com']);
    }

    public function test_user_update_keeps_blank_password_and_replaces_store_access(): void
    {
        $admin = User::factory()->admin()->create();
        $firstStore = Store::factory()->create();
        $secondStore = Store::factory()->create();
        $admin->stores()->attach($firstStore);
        $user = User::factory()->create(['password' => 'original-password']);
        $user->stores()->attach($firstStore);
        $originalPassword = $user->password;

        $response = $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => 'Updated User',
            'email' => 'updated@example.com',
            'password' => '',
            'password_confirmation' => '',
            'role' => UserRole::Viewer->value,
            'store_ids' => [$secondStore->getKey()],
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('status', 'User updated.');
        $user->refresh();
        $this->assertSame('Updated User', $user->name);
        $this->assertSame($originalPassword, $user->password);
        $this->assertSame([$secondStore->getKey()], $user->stores()->pluck('stores.id')->all());
    }

    public function test_final_administrator_cannot_be_demoted(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);

        $response = $this->from(route('admin.users.edit', $admin))
            ->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => UserRole::Operator->value,
                'store_ids' => [$store->getKey()],
            ]);

        $response
            ->assertRedirect(route('admin.users.edit', $admin))
            ->assertSessionHasErrors([
                'role' => 'The final administrator cannot be demoted.',
            ]);
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_administrator_can_be_demoted_when_another_administrator_exists(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::Operator->value,
            'store_ids' => [$store->getKey()],
        ]);

        $response->assertRedirect();
        $this->assertSame(UserRole::Operator, $admin->fresh()->role);
    }
}
