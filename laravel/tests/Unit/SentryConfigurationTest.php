<?php

namespace Tests\Unit;

use App\Support\SentryEventSanitizer;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\UserDataBag;
use Tests\TestCase;

class SentryConfigurationTest extends TestCase
{
    public function test_sentry_is_private_and_disabled_without_a_dsn(): void
    {
        $this->assertNull(config('sentry.dsn'));
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertSame('none', config('sentry.max_request_body_size'));
        $this->assertSame(0.0, config('sentry.traces_sample_rate'));
        $this->assertSame([SentryEventSanitizer::class, 'sanitizeEvent'], config('sentry.before_send'));
    }

    public function test_event_sanitizer_removes_request_and_customer_secrets(): void
    {
        $event = Event::createEvent()
            ->setRequest([
                'method' => 'POST',
                'url' => 'https://example.test/orders?email=customer@example.test',
                'data' => ['access_token' => 'secret-token'],
                'cookies' => ['session' => 'secret-session'],
            ])
            ->setUser(new UserDataBag(42, 'customer@example.test', '127.0.0.1'))
            ->setExtra([
                'store_id' => 7,
                'credentials' => ['api-key' => 'secret-key'],
                'callback_url' => 'https://example.test/callback?token=secret-token',
            ]);

        $sanitized = SentryEventSanitizer::sanitizeEvent($event);

        $this->assertSame([
            'method' => 'POST',
            'url' => 'https://example.test/orders',
        ], $sanitized->getRequest());
        $this->assertNull($sanitized->getUser());
        $this->assertSame(7, $sanitized->getExtra()['store_id']);
        $this->assertSame('[Filtered]', $sanitized->getExtra()['credentials']);
        $this->assertSame('https://example.test/callback', $sanitized->getExtra()['callback_url']);
    }

    public function test_breadcrumb_sanitizer_filters_nested_values(): void
    {
        $breadcrumb = new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            metadata: [
                'url' => 'https://example.test/orders?query=customer@example.test',
                'headers' => ['Authorization' => 'Bearer secret-token'],
            ],
        );

        $sanitized = SentryEventSanitizer::sanitizeBreadcrumb($breadcrumb);

        $this->assertSame('https://example.test/orders', $sanitized->getMetadata()['url']);
        $this->assertSame('[Filtered]', $sanitized->getMetadata()['headers']['Authorization']);
    }
}
