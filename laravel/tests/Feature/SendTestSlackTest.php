<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Notifications\SlackTestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendTestSlackTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_send_a_test_notification_to_a_configured_webhook(): void
    {
        [$admin] = $this->adminWithStore();
        $this->configureSlack();
        Notification::fake();

        $this->actingAs($admin)
            ->post(route('admin.api-health.test-slack'))
            ->assertOk()
            ->assertSeeText('Test Slack notification sent successfully')
            ->assertSeeText('hooks.slack.com')
            ->assertDontSee('test-secret', false);

        Notification::assertSentOnDemand(
            SlackTestNotification::class,
            fn (SlackTestNotification $notification, array $channels, object $notifiable): bool => in_array('slack', array_keys($notifiable->routes), true)
                && $notification->applicationName === config('app.name'),
        );
    }

    public function test_unconfigured_or_untrusted_webhook_fails_without_sending(): void
    {
        [$admin] = $this->adminWithStore();
        config()->set('services.slack.notifications.webhook_url', 'https://example.test/services/test-secret');
        Notification::fake();

        $this->actingAs($admin)
            ->post(route('admin.api-health.test-slack'))
            ->assertOk()
            ->assertSeeText('Test Slack notification could not be sent')
            ->assertDontSeeText('Slack delivery is not configured');

        Notification::assertNothingSent();
    }

    public function test_only_administrators_can_send_test_notifications(): void
    {
        $this->post(route('admin.api-health.test-slack'))->assertRedirect(route('login'));

        $viewer = User::factory()->create();
        $store = Store::factory()->create();
        $viewer->stores()->attach($store);

        $this->actingAs($viewer)->post(route('admin.api-health.test-slack'))->assertForbidden();
    }

    public function test_notification_is_queueable_and_contains_no_operational_data(): void
    {
        $notification = new SlackTestNotification('Shopify Ops', '2026-09-08 15:00:00');
        $payload = $notification->toSlack((object) [])->toArray();

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertStringContainsString('successfully connected', $payload['text']);
        $this->assertStringContainsString('No store credentials or order data', $payload['text']);
        $this->assertNull($payload['channel']);
    }

    private function configureSlack(): void
    {
        config()->set('services.slack.notifications.webhook_url', 'https://hooks.slack.com/services/T000/B000/test-secret');
    }

    /** @return array{User, Store} */
    private function adminWithStore(): array
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);

        return [$admin, $store];
    }
}
