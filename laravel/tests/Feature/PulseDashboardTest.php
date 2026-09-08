<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Recorders;
use Tests\TestCase;

class PulseDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pulse_storage_is_installed_but_recording_is_opt_in(): void
    {
        $this->assertFalse(config('pulse.enabled'));
        $this->assertSame('admin/pulse', config('pulse.path'));
        $this->assertFalse(config('pulse.recorders.'.Recorders\UserRequests::class.'.enabled'));
        $this->assertFalse(config('pulse.recorders.'.Recorders\UserJobs::class.'.enabled'));
        $this->assertFalse(config('pulse.recorders.'.Recorders\SlowOutgoingRequests::class.'.enabled'));
        $this->assertTrue(Schema::hasTable('pulse_values'));
        $this->assertTrue(Schema::hasTable('pulse_entries'));
        $this->assertTrue(Schema::hasTable('pulse_aggregates'));
    }

    public function test_only_administrators_can_view_pulse(): void
    {
        $viewer = User::factory()->create();
        $store = Store::factory()->create();
        $viewer->stores()->attach($store);
        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        $path = '/'.config('pulse.path');

        $this->get($path)->assertForbidden();
        $this->actingAs($viewer)->get($path)->assertForbidden();
        $this->actingAs($admin)->get($path)->assertOk()->assertSeeText('Laravel Pulse');
    }
}
