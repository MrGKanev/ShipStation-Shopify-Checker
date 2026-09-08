<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActionLogControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_administrators_can_view_the_action_log(): void
    {
        $this->get(route('admin.action-log'))->assertRedirect(route('login'));
        $viewer = User::factory()->create();
        $store = Store::factory()->create();
        $viewer->stores()->attach($store);

        $this->actingAs($viewer)->get(route('admin.action-log'))->assertForbidden();
    }

    public function test_store_changes_are_logged_without_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create(['label' => 'Original', 'shopify_access_token' => 'old-secret']);
        $admin->stores()->attach($store);

        $this->actingAs($admin)->put(route('admin.stores.update', $store), ['slug' => $store->slug, 'label' => 'Updated', 'shopify_store' => $store->shopify_store, 'shopify_access_token' => 'new-secret', 'shipstation_api_key' => '', 'shipstation_api_secret' => '', 'store_number' => '']);

        $activities = Activity::query()->inLog('administration')->latest('id')->get();
        $this->assertTrue($activities->contains(fn (Activity $activity): bool => $activity->event === 'updated' && $activity->subject_id === $store->id));
        $rotation = $activities->firstWhere('event', 'credentials_rotated');
        $this->assertNotNull($rotation);
        $this->assertSame(['shopify_access_token'], $rotation->properties->get('credential_fields'));
        $payload = $activities->toJson();
        $this->assertStringNotContainsString('old-secret', $payload);
        $this->assertStringNotContainsString('new-secret', $payload);
    }

    public function test_action_log_is_newest_first_and_escapes_content(): void
    {
        $admin = User::factory()->admin()->create(['email' => '<script>@example.com']);
        $store = Store::factory()->create();
        $admin->stores()->attach($store);
        activity('administration')->causedBy($admin)->performedOn($store)->event('first')->log('First action');
        activity('administration')->causedBy($admin)->performedOn($store)->event('second')->log('<script>Newest</script>');

        $this->actingAs($admin)->get(route('admin.action-log'))->assertOk()->assertSeeText('Action Log')->assertSeeInOrder(['&lt;script&gt;Newest&lt;/script&gt;', 'First action'], false)->assertDontSee('<script>', false);
    }
}
