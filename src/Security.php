<?php
declare(strict_types=1);

final class Security
{
    /** @param array<string, mixed> $server */
    public static function isHttps(array $server): bool
    {
        if (isset($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off' && (string) $server['HTTPS'] !== '') {
            return true;
        }
        if (!self::isTrustedProxy((string) ($server['REMOTE_ADDR'] ?? ''))) return false;
        $proto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https';
    }

    /** @param array<string, mixed> $server */
    public static function clientIp(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        if (!self::isTrustedProxy($remote)) return $remote;

        foreach (array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '')))) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) && !self::isTrustedProxy($candidate)) return $candidate;
        }
        return $remote;
    }

    public static function isTrustedProxy(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $rules = array_filter(array_map('trim', explode(',', (string) (getenv('TRUSTED_PROXIES') ?: ''))));
        foreach ($rules as $rule) {
            if ($rule === $ip || (str_contains($rule, '/') && self::ipInCidr($ip, $rule))) return true;
        }
        return false;
    }

    /**
     * Updates session timestamps and returns true when an authenticated session expired.
     *
     * @param array<string, mixed> $session
     */
    public static function sessionExpired(array &$session, ?int $now = null): bool
    {
        $now ??= time();
        if (empty($session['authed'])) return false;

        $idle = max(60, (int) (getenv('SESSION_IDLE_TIMEOUT') ?: 1800));
        $absolute = max($idle, (int) (getenv('SESSION_ABSOLUTE_TIMEOUT') ?: 43200));
        $createdAt = (int) ($session['_auth_created_at'] ?? $now);
        $lastSeen = (int) ($session['_auth_last_seen'] ?? $now);
        if (($now - $lastSeen) > $idle || ($now - $createdAt) > $absolute) return true;

        $session['_auth_created_at'] = $createdAt;
        $session['_auth_last_seen'] = $now;
        return false;
    }

    /** @param array<string, mixed> $session */
    public static function markAuthenticated(array &$session, ?int $now = null): void
    {
        $now ??= time();
        $session['_auth_created_at'] = $now;
        $session['_auth_last_seen'] = $now;
    }

    /** @param array<string, mixed> $session */
    public static function allowRate(array &$session, string $key, int $limit, int $window, ?int $now = null): bool
    {
        $now ??= time();
        $bucketKey = '_rate_' . preg_replace('/[^a-z0-9_]/i', '_', $key);
        $timestamps = array_values(array_filter(
            is_array($session[$bucketKey] ?? null) ? $session[$bucketKey] : [],
            static fn(mixed $stamp): bool => is_int($stamp) && $stamp > ($now - $window)
        ));
        if (count($timestamps) >= $limit) {
            $session[$bucketKey] = $timestamps;
            return false;
        }
        $timestamps[] = $now;
        $session[$bucketKey] = $timestamps;
        return true;
    }

    /** @return list<string> */
    public static function headers(bool $https): array
    {
        $headers = [
            "Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self' https://accounts.google.com; frame-ancestors 'none'; base-uri 'self'",
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: same-origin',
            'Permissions-Policy: camera=(), microphone=(), geolocation=()',
        ];
        if ($https) $headers[] = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
        return $headers;
    }

    public static function applyHeaders(bool $https): void
    {
        foreach (self::headers($https) as $header) header($header);
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixText] = array_pad(explode('/', $cidr, 2), 2, '');
        $addressBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) return false;
        if (!ctype_digit($prefixText)) return false;
        $prefix = (int) $prefixText;
        $maxBits = strlen($addressBytes) * 8;
        if ($prefix < 0 || $prefix > $maxBits) return false;

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if (substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) return false;
        if ($remainingBits === 0) return true;
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
