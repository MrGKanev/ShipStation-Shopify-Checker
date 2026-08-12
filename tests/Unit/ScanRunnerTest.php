<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/TmpDir.php';

final class ScanRunnerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scanrunner_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        RunLog::setDataDir($this->tmpDir);
        AuditSnapshot::setDataDir($this->tmpDir);
        SlackRules::setDataDir($this->tmpDir);
        EmailRules::setDataDir($this->tmpDir);
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        TmpDir::remove($this->tmpDir);
        $_GET = [];
        $_POST = [];
    }

    public function testInactiveActionReturnsRequestRangeWithoutLogging(): void
    {
        $_GET = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        $result = ScanRunner::run('other_action', 'scan_test', $this->ctx(), 'scan', fn() => []);

        $this->assertSame(null, $result['result']);
        $this->assertSame('', $result['error']);
        $this->assertSame('2026-06-01', $result['start']);
        $this->assertSame('2026-06-10', $result['end']);
        $this->assertSame([], RunLog::all());
    }

    public function testSuccessfulScanSavesAnAuditSnapshotForToday(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', function () {
            return ['rows' => [['id' => 1]], 'scanned' => 5];
        });

        $today    = date('Y-m-d');
        $snapshot = AuditSnapshot::load('scan_test', $today);

        $this->assertNotNull($snapshot);
        $this->assertSame('2026-06-01', $snapshot['start']);
        $this->assertSame('2026-06-10', $snapshot['end']);
        $this->assertSame(1, $snapshot['rows_found']);
        $this->assertSame([['id' => 1]], $snapshot['result']['rows']);
    }

    public function testHistoryParamReturnsSavedSnapshotWithoutRunningTheScan(): void
    {
        AuditSnapshot::save('scan_test', '2026-06-05', ['rows' => [['id' => 42]], 'scanned' => 1], '2026-06-01', '2026-06-05', 1);

        $ran = false;
        $_GET = ['scan_history' => '2026-06-05'];

        $result = ScanRunner::run('other_action', 'scan_test', $this->ctx(), 'scan', function () use (&$ran) {
            $ran = true;
            return ['rows' => []];
        });

        $this->assertFalse($ran);
        $this->assertSame([['id' => 42]], $result['result']['rows']);
        $this->assertSame('2026-06-01', $result['start']);
        $this->assertSame('2026-06-05', $result['end']);
        $this->assertSame('', $result['error']);
        $this->assertSame([], RunLog::all());
    }

    public function testHistoryParamWithNoSavedSnapshotReturnsAnError(): void
    {
        $_GET = ['scan_history' => '2026-06-05'];

        $result = ScanRunner::run('other_action', 'scan_test', $this->ctx(), 'scan', fn() => ['rows' => []]);

        $this->assertNull($result['result']);
        $this->assertStringContainsString('No saved run', $result['error']);
    }

    public function testSuccessfulScanLogsRowsFound(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        $result = ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', function () {
            return ['rows' => [['id' => 1]], 'scanned' => 5];
        });

        $this->assertSame(5, $result['result']['scanned']);

        $row = RunLog::all()[0];
        $this->assertSame('scan_test', $row['tool']);
        $this->assertSame('issues_found', $row['status']);
        $this->assertSame(5, $row['scanned']);
        $this->assertSame(1, $row['rows_found']);
        $this->assertSame('2026-06-01', $row['start_date']);
        $this->assertSame('2026-06-10', $row['end_date']);
    }

    public function testValidationErrorIsLogged(): void
    {
        $_POST = ['scan_start' => 'bad-date', 'scan_end' => '2026-06-10'];

        $result = ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', fn() => []);

        $this->assertSame('Invalid date format. Use YYYY-MM-DD.', $result['error']);

        $row = RunLog::all()[0];
        $this->assertSame('validation_error', $row['status']);
        $this->assertSame('Invalid date format. Use YYYY-MM-DD.', $row['error']);
    }

    public function testMissingShipStationCredentialsAreLoggedWhenRequired(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];
        $ctx = $this->ctx(['ssKey' => '', 'ssSecret' => '']);

        $result = ScanRunner::run('scan_test', 'scan_test', $ctx, 'scan', fn() => [], 30, true);

        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $result['error']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testMissingShopifyCredentialsAreLoggedWhenSsNotRequired(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];
        $ctx = $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']);

        $result = ScanRunner::run('scan_test', 'scan_test', $ctx, 'scan', fn() => []);

        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $result['error']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testShipStationCredentialsCheckedBeforeShopifyWhenBothMissing(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];
        $ctx = $this->ctx(['ssKey' => '', 'ssSecret' => '', 'shopifyToken' => '', 'shopifyStore' => 'N/A']);

        $result = ScanRunner::run('scan_test', 'scan_test', $ctx, 'scan', fn() => [], 30, true);

        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $result['error']);
    }

    public function testResultRowCountReadsMatchesKeyWhenRowsAbsent(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', fn() => ['matches' => [1, 2, 3]]);

        $this->assertSame(3, RunLog::all()[0]['rows_found']);
    }

    public function testResultRowCountReadsPairsKeyWhenRowsAndMatchesAbsent(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', fn() => ['pairs' => [1, 2]]);

        $this->assertSame(2, RunLog::all()[0]['rows_found']);
    }

    public function testResultRowCountIsNullForUnrecognisedResultShape(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', fn() => ['total' => 5]);

        $row = RunLog::all()[0];
        $this->assertNull($row['rows_found']);
        $this->assertSame('ok', $row['status'], 'null rowsFound is not > 0, so status falls back to ok');
    }

    public function testNotifyScanSkippedWithoutCrashingWhenSlackNotConfigured(): void
    {
        $previous = getenv('SLACK_WEBHOOK_URL');
        putenv('SLACK_WEBHOOK_URL');
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        try {
            $result = ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', fn() => ['rows' => [['id' => 1]]]);
        } finally {
            if ($previous !== false) putenv("SLACK_WEBHOOK_URL={$previous}");
        }

        $this->assertSame('', $result['error']);
        $this->assertSame(1, $result['result']['rows'][0]['id']);
    }

    public function testEmailNotifyAttemptedForImmediateModeSkippedWithoutCrashingWhenSmtpNotConfigured(): void
    {
        // 'scan_addresses' is a real ToolRegistry::triggerCatalog() key, unlike
        // this file's usual 'scan_test' trigger - EmailRules::shouldNotify()
        // drops unknown tool keys, so the gate needs a real one to exercise
        // the true branch (reaching EmailNotifier::fromEnvironment()).
        EmailRules::save(['scan_addresses' => ['mode' => 'immediate', 'threshold' => 1]]);
        $previous = getenv('SMTP_HOST');
        putenv('SMTP_HOST');
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        try {
            $result = ScanRunner::run('scan_addresses', 'scan_addresses', $this->ctx(), 'scan', fn() => ['rows' => [['id' => 1]]]);
        } finally {
            if ($previous !== false) putenv("SMTP_HOST={$previous}");
        }

        $this->assertSame('', $result['error']);
    }

    public function testEmailNotSentWhenModeIsOffEvenIfThresholdMet(): void
    {
        // EmailRules defaults every tool to 'off', so this should behave
        // identically to a tool with no rule saved at all - no crash either way.
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        $result = ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', fn() => ['rows' => [['id' => 1]]]);

        $this->assertSame('', $result['error']);
    }

    public function testThrownExceptionIsLoggedAsError(): void
    {
        $_POST = ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'];

        $result = ScanRunner::run('scan_test', 'scan_test', $this->ctx(), 'scan', function () {
            throw new RuntimeException('boom');
        });

        $this->assertSame('boom', $result['error']);

        $row = RunLog::all()[0];
        $this->assertSame('error', $row['status']);
        $this->assertSame('boom', $row['error']);
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyToken' => 'tok_test',
            'shopifyStore' => 'test.myshopify.com',
            'ssKey'        => 'ss_key',
            'ssSecret'     => 'ss_secret',
        ];
    }
}
