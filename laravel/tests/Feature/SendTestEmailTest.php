<?php

namespace Tests\Feature;

use App\Mail\TestEmail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendTestEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_send_a_normalized_test_email_through_configured_smtp(): void
    {
        [$admin] = $this->adminWithStore();
        $this->configureSmtp();
        Mail::fake();

        $this->actingAs($admin)->post(route('admin.api-health.test-email'), ['email' => '  OPS@Example.com '])->assertOk()->assertSeeText('Test email sent successfully');

        Mail::assertSent(TestEmail::class, fn (TestEmail $mail): bool => $mail->hasTo('ops@example.com')
            && $mail->applicationName === config('app.name'));
    }

    public function test_invalid_recipient_is_rejected_without_sending(): void
    {
        [$admin] = $this->adminWithStore();
        $this->configureSmtp();
        Mail::fake();

        $this->actingAs($admin)->from(route('admin.api-health'))->post(route('admin.api-health.test-email'), ['email' => 'invalid'])->assertRedirect(route('admin.api-health'))->assertSessionHasErrors('email');
        Mail::assertNothingSent();
    }

    public function test_unconfigured_transport_fails_safely_without_sending(): void
    {
        [$admin] = $this->adminWithStore();
        config()->set('mail.default', 'log');
        Mail::fake();

        $this->actingAs($admin)->post(route('admin.api-health.test-email'), ['email' => 'ops@example.com'])->assertOk()->assertSeeText('Test email could not be sent')->assertDontSeeText('SMTP delivery is not configured');
        Mail::assertNothingSent();
    }

    public function test_test_message_contains_no_store_or_order_data(): void
    {
        $mail = new TestEmail('Shopify Ops', '2026-09-07 12:00:00');
        $rendered = $mail->render();

        $this->assertStringContainsString('successfully connected', $rendered);
        $this->assertStringContainsString('contains no store credentials or order data', $rendered);
    }

    private function configureSmtp(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'user', 'password' => 'password']);
        config()->set('mail.from.address', 'no-reply@example.com');
    }

    private function adminWithStore(): array
    {
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $admin->stores()->attach($store);

        return [$admin, $store];
    }
}
