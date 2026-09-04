<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Security.php';

use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        putenv('SESSION_IDLE_TIMEOUT');
        putenv('SESSION_ABSOLUTE_TIMEOUT');
    }

    public function testForwardedHeadersAreIgnoredForUntrustedClients(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4'];
        $this->assertFalse(Security::isHttps($server));
        $this->assertSame('203.0.113.5', Security::clientIp($server));
    }

    public function testTrustedProxySupportsExactAddressesAndCidrs(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.1,192.168.0.0/16,2001:db8::/32');
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4, 192.168.2.2'];
        $this->assertTrue(Security::isHttps($server));
        $this->assertSame('198.51.100.4', Security::clientIp($server));
        $this->assertTrue(Security::isTrustedProxy('192.168.3.4'));
        $this->assertTrue(Security::isTrustedProxy('2001:db8::42'));
    }

    public function testSessionEnforcesIdleAndAbsoluteTimeouts(): void
    {
        putenv('SESSION_IDLE_TIMEOUT=300');
        putenv('SESSION_ABSOLUTE_TIMEOUT=900');
        $session = ['authed' => true, '_auth_created_at' => 1000, '_auth_last_seen' => 1500];
        $this->assertFalse(Security::sessionExpired($session, 1700));
        $this->assertSame(1700, $session['_auth_last_seen']);
        $this->assertTrue(Security::sessionExpired($session, 2050));
    }

    public function testRateLimitUsesRollingWindow(): void
    {
        $session = [];
        $this->assertTrue(Security::allowRate($session, 'oauth', 2, 60, 100));
        $this->assertTrue(Security::allowRate($session, 'oauth', 2, 60, 110));
        $this->assertFalse(Security::allowRate($session, 'oauth', 2, 60, 120));
        $this->assertTrue(Security::allowRate($session, 'oauth', 2, 60, 171));
    }

    public function testSecurityHeadersIncludeHstsOnlyForHttps(): void
    {
        $https = implode("\n", Security::headers(true));
        $http = implode("\n", Security::headers(false));
        $this->assertStringContainsString('Content-Security-Policy:', $https);
        $this->assertStringContainsString('Strict-Transport-Security:', $https);
        $this->assertStringNotContainsString('Strict-Transport-Security:', $http);
    }
}
