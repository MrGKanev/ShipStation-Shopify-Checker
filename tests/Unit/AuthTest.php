<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/auth_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        Auth::setDataDir($this->tmpDir);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->tmpDir . '/login_attempts.json',
            $this->tmpDir . '/login_attempts.json.lock',
        ] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        $_SESSION = [];
    }

    // ── verifyPassword ────────────────────────────────────────────────────────

    public function testVerifyPasswordSupportsPlainTextCompatibility(): void
    {
        $this->assertTrue(Auth::verifyPassword('secret', 'secret'));
        $this->assertFalse(Auth::verifyPassword('wrong', 'secret'));
    }

    public function testVerifyPasswordSupportsPasswordHash(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);

        $this->assertTrue(Auth::verifyPassword('secret', $hash));
        $this->assertFalse(Auth::verifyPassword('wrong', $hash));
    }

    // ── attempt ───────────────────────────────────────────────────────────────

    public function testAttemptReturnsEmptyStringOnSuccess(): void
    {
        $result = Auth::attempt('admin', 'secret', 'admin', 'secret', '127.0.0.1');
        $this->assertSame('', $result);
    }

    public function testAttemptReturnsErrorMessageOnWrongPassword(): void
    {
        $result = Auth::attempt('admin', 'wrong', 'admin', 'secret', '127.0.0.1');
        $this->assertNotSame('', $result);
        $this->assertStringContainsString('Incorrect', $result);
    }

    public function testAttemptReturnsErrorMessageOnWrongUsername(): void
    {
        $result = Auth::attempt('baduser', 'secret', 'admin', 'secret', '127.0.0.1');
        $this->assertStringContainsString('Incorrect', $result);
    }

    public function testAttemptShowsDecreasingRemainingCountOnEachFailure(): void
    {
        $ip = '10.0.0.1';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        $result = Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        $this->assertStringContainsString('1 attempt remaining', $result);
    }

    public function testAttemptLocksOutIpAfterMaxFailedAttempts(): void
    {
        $ip = '10.0.0.2';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $result = Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        $this->assertStringContainsString('Too many', $result);
    }

    public function testAttemptLockedOutMessageMentionsTimeRemaining(): void
    {
        $ip = '10.0.0.3';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $result = Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        $this->assertStringContainsString('day', $result);
    }

    public function testAttemptSuccessClearsFailedAttemptsForIp(): void
    {
        $ip = '10.0.0.4';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'secret', 'admin', 'secret', $ip);

        $this->assertArrayNotHasKey($ip, Auth::bannedIps());
    }

    public function testLockedOutAttemptStillPersistsPrunedExpiredEntries(): void
    {
        $ip = '10.0.0.9';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip); // locks $ip out

        // Seed a long-expired entry for an unrelated IP directly on disk -
        // this should be pruned the next time the attempts file is written.
        $file = $this->tmpDir . '/login_attempts.json';
        $raw  = json_decode((string) file_get_contents($file), true);
        $raw['203.0.113.1'] = ['count' => 1, 'first' => time() - 999999, 'until' => 0];
        file_put_contents($file, json_encode($raw));

        // $ip is locked out, so this attempt takes the early-return path -
        // it must still persist the pruned attempts list, not just read it.
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $after = json_decode((string) file_get_contents($file), true);
        $this->assertArrayNotHasKey('203.0.113.1', $after);
    }

    // ── bannedIps ─────────────────────────────────────────────────────────────

    public function testBannedIpsReturnsEmptyArrayWhenNoFileExists(): void
    {
        $this->assertSame([], Auth::bannedIps());
    }

    public function testBannedIpsReturnsIpAfterLockout(): void
    {
        $ip = '192.168.1.1';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $banned = Auth::bannedIps();
        $this->assertArrayHasKey($ip, $banned);
    }

    public function testBannedIpsDoesNotIncludeNonLockedIps(): void
    {
        $ip = '192.168.1.3';
        // Only one failed attempt — not yet locked
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        $this->assertArrayNotHasKey($ip, Auth::bannedIps());
    }

    // ── unban ─────────────────────────────────────────────────────────────────

    public function testUnbanRemovesBannedIpFromList(): void
    {
        $ip = '192.168.1.2';
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);
        Auth::attempt('admin', 'wrong', 'admin', 'secret', $ip);

        Auth::unban($ip);

        $this->assertArrayNotHasKey($ip, Auth::bannedIps());
    }

    public function testUnbanDoesNothingWhenNoFileExists(): void
    {
        Auth::unban('1.2.3.4');
        $this->assertSame([], Auth::bannedIps());
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    public function testCsrfTokenCreatesNonEmptyTokenWhenSessionIsEmpty(): void
    {
        $token = Auth::csrfToken();
        $this->assertNotEmpty($token);
    }

    public function testCsrfTokenReturnsSameTokenOnRepeatedCalls(): void
    {
        $first  = Auth::csrfToken();
        $second = Auth::csrfToken();
        $this->assertSame($first, $second);
    }

    public function testCsrfTokenUsesExistingSessionValue(): void
    {
        $_SESSION['_csrf'] = 'preset-token';
        $this->assertSame('preset-token', Auth::csrfToken());
    }

    public function testRotateCsrfTokenReturnsNewToken(): void
    {
        $original = Auth::csrfToken();
        $rotated  = Auth::rotateCsrfToken();
        $this->assertNotSame($original, $rotated);
    }

    public function testRotateCsrfTokenUpdatesSessionValue(): void
    {
        $rotated = Auth::rotateCsrfToken();
        $this->assertSame($rotated, $_SESSION['_csrf']);
    }

    public function testValidateCsrfReturnsTrueForMatchingToken(): void
    {
        $token = Auth::csrfToken();
        $this->assertTrue(Auth::validateCsrf($token));
    }

    public function testValidateCsrfReturnsFalseForWrongToken(): void
    {
        Auth::csrfToken();
        $this->assertFalse(Auth::validateCsrf('wrong-token'));
    }

    public function testValidateCsrfReturnsFalseWhenNoSessionTokenSet(): void
    {
        $this->assertFalse(Auth::validateCsrf('any-token'));
    }

    // ── Action permissions ───────────────────────────────────────────────────

    public function testViewerCanPerformReadOnlyLookupAction(): void
    {
        $_SESSION['user_role'] = 'viewer';

        $this->assertTrue(Auth::canPerformAction('spotcheck'));
        $this->assertTrue(Auth::canPerformAction('order_detail'));
    }

    public function testViewerCannotPerformOperatorAction(): void
    {
        $_SESSION['user_role'] = 'viewer';

        $this->assertFalse(Auth::canPerformAction('push_to_shipstation'));
        $this->assertFalse(Auth::canPerformAction('scan_addresses'));
        $this->assertFalse(Auth::canPerformAction('save_order_note'));
    }

    public function testOperatorCanPerformOperationalActions(): void
    {
        $_SESSION['user_role'] = 'operator';

        $this->assertTrue(Auth::canPerformAction('queue_audit'));
        $this->assertTrue(Auth::canPerformAction('pq_add'));
        $this->assertFalse(Auth::canPerformAction('add_user'));
    }

    public function testAdminCanPerformAdminActions(): void
    {
        $_SESSION['user_role'] = 'admin';

        $this->assertTrue(Auth::canPerformAction('add_user'));
        $this->assertTrue(Auth::canPerformAction('refresh_api_health'));
    }

    public function testUnknownPostActionIsDeniedByDefault(): void
    {
        $_SESSION['user_role'] = 'admin';

        $this->assertFalse(Auth::canPerformAction('new_unclassified_action'));
        $this->assertFalse(Auth::permissionForAction('new_unclassified_action'));
    }

    public function testPostActionsRenderedByViewsAndJavascriptAreClassified(): void
    {
        $actions = $this->discoverRenderedPostActions();
        $unknown = array_values(array_filter(
            $actions,
            fn(string $action): bool => Auth::permissionForAction($action) === false
        ));

        $this->assertGreaterThan(40, count($actions), 'Action discovery did not cover the expected form surface.');
        $this->assertSame([], $unknown, 'Unclassified POST actions: ' . implode(', ', $unknown));
    }

    public function testUnsafeLegacyPasswordDetectsMissingAndPlaceholders(): void
    {
        $this->assertTrue(Auth::isUnsafeLegacyPassword(''));
        $this->assertTrue(Auth::isUnsafeLegacyPassword('changeme'));
        $this->assertTrue(Auth::isUnsafeLegacyPassword('change_me_now'));
        $this->assertFalse(Auth::isUnsafeLegacyPassword('real-password'));
    }

    /**
     * @return list<string>
     */
    private function discoverRenderedPostActions(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['views', 'assets'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'js'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $actions = [];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match_all('/<input\b[^>]*>/i', $contents, $tags)) {
                foreach ($tags[0] as $tag) {
                    if (preg_match('/\bname\s*=\s*["\']action["\']/i', $tag)
                        && preg_match('/\bvalue\s*=\s*["\']([^"\']+)["\']/i', $tag, $m)
                    ) {
                        $actions[$m[1]] = true;
                    }
                }
            }

            if (preg_match_all('/\bfd\.append\(\s*["\']action["\']\s*,\s*["\']([^"\']+)["\']\s*\)/', $contents, $matches)) {
                foreach ($matches[1] as $action) {
                    $actions[$action] = true;
                }
            }

            if (preg_match_all('/\.value\s*=\s*["\']([a-z0-9_]+)["\']/', $contents, $matches)) {
                foreach ($matches[1] as $action) {
                    if (str_contains($action, '_')) {
                        $actions[$action] = true;
                    }
                }
            }
        }

        $actions = array_keys($actions);
        sort($actions);

        return $actions;
    }
}
