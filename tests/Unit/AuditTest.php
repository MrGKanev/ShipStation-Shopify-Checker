<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/Audit.php';

/**
 * Tests for Audit::withErrorLogging() - extracted from audit.php's
 * top-level try/catch (see "Still open" item in
 * docs/audit-test-coverage-gaps.md: "no test for audit.php's top-level
 * try/catch that logs RunLog with status: 'error' on any Throwable").
 */
class AuditTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/audit_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        RunLog::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    public function testReturnsWorkResultOnSuccessWithoutLogging(): void
    {
        $result = Audit::withErrorLogging(fn() => 'ok', 'cli_audit', '2026-06-01', '2026-06-20');

        $this->assertSame('ok', $result);
        $this->assertSame([], RunLog::all(), 'success path logs nothing itself - callers log their own richer entry');
    }

    public function testThrownExceptionLogsErrorStatusAndRethrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify API down');

        try {
            Audit::withErrorLogging(function () {
                throw new RuntimeException('Shopify API down');
            }, 'cli_audit', '2026-06-01', '2026-06-20');
        } finally {
            $rows = RunLog::all();
            $this->assertCount(1, $rows);
            $this->assertSame('error', $rows[0]['status']);
            $this->assertSame('cli_audit', $rows[0]['tool']);
            $this->assertSame('Shopify API down', $rows[0]['error']);
            $this->assertSame('2026-06-01', $rows[0]['start_date']);
            $this->assertSame('2026-06-20', $rows[0]['end_date']);
        }
    }

    public function testErrorFromAnyThrowableTypeIsLogged(): void
    {
        try {
            Audit::withErrorLogging(function () {
                throw new TypeError('unexpected null');
            }, 'cli_audit', '2026-06-01', '2026-06-20');
        } catch (TypeError) {
            // expected
        }

        $this->assertSame('error', RunLog::all()[0]['status']);
    }
}
