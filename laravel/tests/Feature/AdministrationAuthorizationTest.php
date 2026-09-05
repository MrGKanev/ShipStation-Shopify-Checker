<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrationAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, array{UserRole, bool}>
     */
    public static function roles(): array
    {
        return [
            'viewer' => [UserRole::Viewer, false],
            'operator' => [UserRole::Operator, false],
            'administrator' => [UserRole::Admin, true],
        ];
    }

    #[DataProvider('roles')]
    public function test_only_administrators_receive_administration_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);

        $result = Gate::forUser($user)->allows('manage-administration');

        $this->assertSame($allowed, $result);
    }

    public function test_operator_is_forbidden_from_administration_routes(): void
    {
        $operator = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $operator->stores()->attach($store);

        $response = $this->actingAs($operator)->get(route('admin.stores.index'));

        $response->assertForbidden();
    }
}
