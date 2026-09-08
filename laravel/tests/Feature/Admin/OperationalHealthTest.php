<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Tests\TestCase;

class OperationalHealthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_health_checks_persist_database_cache_disk_scheduler_and_queue_results(): void
    {
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->timestamp);
        Cache::put('health:checks:queue:latestHeartbeatAt.default', now()->timestamp);
        $this->seedRecentBackup();

        $this->artisan('health:check')->assertSuccessful();

        $this->assertSame(['Backups', 'Cache', 'Database', 'Queue', 'Schedule', 'Used Disk Space'], HealthCheckResultHistoryItem::query()->orderBy('check_label')->pluck('check_label')->all());
        $this->assertSame(
            [],
            HealthCheckResultHistoryItem::query()
                ->where('check_label', '!=', 'Used Disk Space')
                ->where('status', '!=', 'ok')
                ->pluck('notification_message', 'check_label')
                ->all(),
        );
    }

    public function test_only_administrators_can_view_stored_health_results(): void
    {
        $this->get(route('admin.health'))->assertRedirect(route('login'));
        $viewer = User::factory()->create();
        $store = Store::factory()->create();
        $viewer->stores()->attach($store);
        $this->actingAs($viewer)->get(route('admin.health'))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $admin->stores()->attach($store);
        Cache::put('health:checks:schedule:latestHeartbeatAt', now()->timestamp);
        Cache::put('health:checks:queue:latestHeartbeatAt.default', now()->timestamp);
        $this->seedRecentBackup();
        $this->artisan('health:check')->assertSuccessful();

        $this->actingAs($admin)->get(route('admin.health'))->assertOk()->assertSeeText('Laravel Health')->assertSeeText('Database')->assertSeeText('Queue');
    }

    private function seedRecentBackup(): void
    {
        $disk = Storage::build(config('filesystems.disks.backups'));
        $path = config('backup.backup.name').'/test-health-'.Str::uuid().'.zip';

        $disk->put($path, 'backup');
        $this->beforeApplicationDestroyed(fn () => $disk->delete($path));
    }
}
