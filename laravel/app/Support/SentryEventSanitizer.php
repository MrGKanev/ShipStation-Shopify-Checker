<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;

class SentryEventSanitizer
{
    private const REDACTED = '[Filtered]';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'access_token',
        'address',
        'api_key',
        'authorization',
        'cookie',
        'credential',
        'customer',
        'email',
        'first_name',
        'last_name',
        'password',
        'phone',
        'secret',
        'token',
    ];

    public static function sanitizeEvent(Event $event): Event
    {
        $request = $event->getRequest();
        $event->setRequest(array_filter([
            'method' => $request['method'] ?? null,
            'url' => self::withoutQueryString($request['url'] ?? null),
        ]));
        $event->setUser(null);
        $event->setExtra(self::sanitizeArray($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, self::sanitizeArray($context));
        }

        return $event;
    }

    public static function sanitizeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        foreach (self::sanitizeArray($breadcrumb->getMetadata()) as $key => $value) {
            $breadcrumb = $breadcrumb->withMetadata($key, $value);
        }

        return $breadcrumb;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function sanitizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = self::sanitizeArray($value);
            }

            if (is_string($value) && self::isUrlKey((string) $key)) {
                $values[$key] = self::withoutQueryString($value);
            }
        }

        return $values;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower(str_replace(['-', '.'], '_', $key));

        return collect(self::SENSITIVE_KEYS)->contains(
            fn (string $sensitiveKey): bool => str_contains($normalizedKey, $sensitiveKey),
        );
    }

    private static function isUrlKey(string $key): bool
    {
        $normalizedKey = strtolower(str_replace(['-', '.'], '_', $key));

        return $normalizedKey === 'url'
            || $normalizedKey === 'uri'
            || str_ends_with($normalizedKey, '_url')
            || str_ends_with($normalizedKey, '_uri');
    }

    private static function withoutQueryString(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        return strtok($url, '?') ?: $url;
    }
}
