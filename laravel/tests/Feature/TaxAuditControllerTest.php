<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TaxAuditControllerTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use LazilyRefreshDatabase;

    public function test_access_and_validation(): void
    {
        $this->get('/reports/tax-audit')->assertRedirect(route('login'));
        $user = User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);
        $this->actingAs($user)->get('/reports/tax-audit')->assertOk();
        $this->actingAs($user)->post('/reports/tax-audit', ['start_date' => '2026-09-02', 'end_date' => '2026-09-01', 'minimum' => -1])->assertSessionHasErrors(['end_date', 'minimum']);
    }
}
