<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for PageLoader::load('run', ...) - the web dashboard's "Run Audit"
 * button. This duplicates audit.php's fetch->compare->notify pipeline in a
 * separate, previously-untested implementation (see docs: "PageLoader.php,
 * 462 lines, 0 tests").
 *
 * Covers a real bug found while writing these tests: buildAuditRunResult()
 * (extracted from loadAudit()) previously called Reporter::saveReports()
 * without passing $ctx['reportDir'], so in multi-store setups a web-triggered
 * audit would save its report into the single shared reports/ dir instead of
 * the store-specific one that loadGlobal() reads from - the report would
 * silently never show up in that store's dashboard history. worker.php
 * already does this correctly (passes $reportDir explicitly); PageLoader.php
 * was the one outlier.
 */
class PageLoaderTest extends TestCase
{
    private string $tmpDir;
    private Cache $cache;
    private static \ReflectionMethod $buildAuditRunResult;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(PageLoader::class);
        self::$buildAuditRunResult = $ref->getMethod('buildAuditRunResult');
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/page_loader_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->cache = new Cache($this->tmpDir . '/cache', ttl: 3600);
        RunLog::setDataDir($this->tmpDir);
        PushLog::setDataDir($this->tmpDir);
        JobQueue::setDataDir($this->tmpDir);
        UserActionLog::setDataDir($this->tmpDir);
        AuditSnapshot::setDataDir($this->tmpDir);
        Auth::setDataDir($this->tmpDir);
        EmailRules::setDataDir($this->tmpDir);

        $this->previousSlackWebhook = getenv('SLACK_WEBHOOK_URL');
        putenv('SLACK_WEBHOOK_URL');
        $this->previousSmtpHost = getenv('SMTP_HOST');
        putenv('SMTP_HOST');

        $_GET = [];
        $_POST = [];
    }

    private string|false $previousSlackWebhook;
    private string|false $previousSmtpHost;

    protected function tearDown(): void
    {
        if ($this->previousSlackWebhook === false) {
            putenv('SLACK_WEBHOOK_URL');
        } else {
            putenv('SLACK_WEBHOOK_URL=' . $this->previousSlackWebhook);
        }
        if ($this->previousSmtpHost === false) {
            putenv('SMTP_HOST');
        } else {
            putenv('SMTP_HOST=' . $this->previousSmtpHost);
        }
        $this->removeDir($this->tmpDir);
        $_GET = [];
        $_POST = [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'cacheObj'      => $this->cache,
            'cacheTtl'      => 3600,
            'shopifyStore'  => 'N/A',
            'shopifyToken'  => '',
            'ssKey'         => '',
            'ssSecret'      => '',
            'reportDir'     => $this->tmpDir . '/reports',
            'ignoredOrders' => [],
        ];
    }

    // ── loadAudit: validation / config error paths (no API calls needed) ────

    public function testRunAuditValidationErrorForBadDateRange(): void
    {
        $_POST = ['audit_start' => '2026-06-20', 'audit_end' => '2026-06-01'];

        $data = PageLoader::load('run', 'run_audit', $this->ctx(['ssKey' => 'k', 'ssSecret' => 's', 'shopifyToken' => 't']));

        $this->assertNull($data['auditResult']);
        $this->assertSame('Start date must be before end date.', $data['auditError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testRunAuditConfigErrorForMissingCredentials(): void
    {
        $_POST = ['audit_start' => '2026-06-01', 'audit_end' => '2026-06-20'];

        $data = PageLoader::load('run', 'run_audit', $this->ctx(['ssKey' => '', 'ssSecret' => '', 'shopifyToken' => '']));

        $this->assertNull($data['auditResult']);
        $this->assertSame('API credentials missing in .env.', $data['auditError']);
        $this->assertSame('config_error', RunLog::all()[0]['status']);
    }

    public function testInitialStateNoActionReturnsNullResult(): void
    {
        $data = PageLoader::load('run', '', $this->ctx());

        $this->assertNull($data['auditResult']);
        $this->assertSame('', $data['auditError']);
    }

    // ── buildAuditRunResult: the reportDir bug fix ──────────────────────────

    private function shopifyOrder(array $overrides = []): array
    {
        return array_merge([
            'id' => 1, 'order_number' => 65001, 'name' => '#65001',
            'financial_status' => 'paid', 'fulfillment_status' => null,
            'cancelled_at' => null, 'total_price' => '50.00',
            'email' => 'jane@example.com', 'shipping_lines' => [['title' => 'Standard']],
            'created_at' => '2026-06-01T10:00:00Z',
        ], $overrides);
    }

    public function testBuildAuditRunResultSavesReportToProvidedReportDir(): void
    {
        $reportDir = $this->tmpDir . '/store-specific-reports';
        $order = $this->shopifyOrder();

        self::$buildAuditRunResult->invoke(null, [$order], [], [], $reportDir, '2026-06-01', '2026-06-20');

        $this->assertFileExists($reportDir . '/missing_' . date('Y-m-d') . '.csv', 'report must land in the caller-provided reportDir, not a hardcoded default');
    }

    public function testBuildAuditRunResultReturnsMissingAndSummaryCounts(): void
    {
        $reportDir = $this->tmpDir . '/reports2';
        $missingOrder = $this->shopifyOrder(['id' => 1, 'order_number' => 1001]);
        $foundOrder   = $this->shopifyOrder(['id' => 2, 'order_number' => 1002]);
        $ssOrders     = [['orderNumber' => '1002', 'orderId' => 999]];

        [$comparison, $auditResult] = self::$buildAuditRunResult->invoke(
            null, [$missingOrder, $foundOrder], $ssOrders, [], $reportDir, '2026-06-01', '2026-06-20'
        );

        $this->assertCount(1, $comparison['missing']);
        $this->assertSame(1001, $comparison['missing'][0]['order_number']);
        $this->assertSame(1, $auditResult['found']);
        $this->assertSame(1, $auditResult['total_ss']);
        $this->assertArrayHasKey('duplicates', $auditResult);
    }

    public function testBuildAuditRunResultRespectsIgnoredOrders(): void
    {
        $reportDir = $this->tmpDir . '/reports3';
        $order = $this->shopifyOrder(['order_number' => 1001]);

        [$comparison,] = self::$buildAuditRunResult->invoke(
            null, [$order], [], ['1001' => ['reason' => 'test']], $reportDir, '2026-06-01', '2026-06-20'
        );

        $this->assertCount(1, $comparison['ignored']);
        $this->assertSame([], $comparison['missing']);
    }

    // ── loadGlobal / loadDashboard: CSV report history parsing ─────────────

    private function writeMissingCsv(string $date, array $rows): void
    {
        $dir = $this->tmpDir . '/reports';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fh = fopen("{$dir}/missing_{$date}.csv", 'w');
        fputcsv($fh, ['order_number', 'shopify_name', 'shopify_id', 'created_at', 'total_price', 'financial_status', 'fulfillment_status', 'email', 'order_type'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fh, [$r['order_number'], '#' . $r['order_number'], $r['id'] ?? 1, '2026-06-01', '50.00', 'paid', '', 'jane@example.com', 'Other'], ',', '"', '\\');
        }
        fclose($fh);
    }

    public function testReportsParsedNewestFirstWithCounts(): void
    {
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001']]);
        $this->writeMissingCsv('2026-06-15', [['order_number' => '1002'], ['order_number' => '1003']]);

        $data = PageLoader::load('dashboard', '', $this->ctx());

        $this->assertSame(['2026-06-15', '2026-06-01'], array_column($data['reports'], 'date'));
        $this->assertSame(2, $data['reports'][0]['count']);
        $this->assertSame(1, $data['reports'][1]['count']);
    }

    public function testReportHistorySummaryIsCachedButInvalidatesForNewReportFile(): void
    {
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001']]);
        PageLoader::load('dashboard', '', $this->ctx());
        PageLoader::load('dashboard', '', $this->ctx());
        $this->assertTrue($this->cache->wasHit('report_history'));

        $this->writeMissingCsv('2026-06-02', [['order_number' => '1002']]);
        $data = PageLoader::load('dashboard', '', $this->ctx());

        $this->assertSame(['2026-06-02', '2026-06-01'], array_column($data['reports'], 'date'));
    }

    public function testIgnoredOrdersFilteredOutOfMissingList(): void
    {
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001'], ['order_number' => '1002']]);

        $data = PageLoader::load('dashboard', '', $this->ctx(['ignoredOrders' => ['1001' => ['reason' => 'x']]]));

        $this->assertCount(1, $data['reports'][0]['missing']);
        $this->assertSame('1002', $data['reports'][0]['missing'][0]['order_number']);
    }

    public function testOrderHistoryAggregatesRepeatOrdersAcrossReports(): void
    {
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001']]);
        $this->writeMissingCsv('2026-06-15', [['order_number' => '1001']]);

        $data = PageLoader::load('dashboard', '', $this->ctx());

        $this->assertSame(2, $data['orderHistory']['1001']['count']);
        $this->assertSame('2026-06-01', $data['orderHistory']['1001']['first']);
        $this->assertSame('2026-06-15', $data['orderHistory']['1001']['last']);
    }

    public function testSelectedReportDefaultsToLatestWithoutDateParam(): void
    {
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001']]);
        $this->writeMissingCsv('2026-06-15', [['order_number' => '1002']]);

        $data = PageLoader::load('dashboard', '', $this->ctx());

        $this->assertSame('2026-06-15', $data['selectedDate']);
        $this->assertSame('2026-06-15', $data['selectedReport']['date']);
    }

    public function testSelectedReportHonorsDateParam(): void
    {
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001']]);
        $this->writeMissingCsv('2026-06-15', [['order_number' => '1002']]);
        $_GET['date'] = '2026-06-01';

        $data = PageLoader::load('dashboard', '', $this->ctx());

        $this->assertSame('2026-06-01', $data['selectedReport']['date']);
    }

    public function testLatestReportKeepsMissingKeyWhenDateParamPicksOlderReport(): void
    {
        // views/dashboard.php iterates $latestReport['missing'] unconditionally,
        // regardless of which date the global ?date= param selected.
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001']]);
        $this->writeMissingCsv('2026-06-15', [['order_number' => '1002']]);
        $_GET['date'] = '2026-06-01';

        $data = PageLoader::load('dashboard', '', $this->ctx());

        $this->assertSame('2026-06-15', $data['latestReport']['date']);
        $this->assertArrayHasKey('missing', $data['latestReport']);
        $this->assertSame('1002', $data['latestReport']['missing'][0]['order_number']);
    }

    public function testDashboardTrendWorseWhenLatestCountHigher(): void
    {
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001']]);
        $this->writeMissingCsv('2026-06-15', [['order_number' => '1002'], ['order_number' => '1003']]);

        $data = PageLoader::load('dashboard', '', $this->ctx());

        $this->assertSame(1, $data['dbTrend'], 'latest report has more missing orders than the previous one - trend should read "worse"');
    }

    public function testDashboardTotalsSumAcrossAllReports(): void
    {
        $this->writeMissingCsv('2026-06-01', [['order_number' => '1001']]);
        $this->writeMissingCsv('2026-06-15', [['order_number' => '1002'], ['order_number' => '1003']]);

        $data = PageLoader::load('dashboard', '', $this->ctx());

        $this->assertSame(2, $data['dbTotalReports']);
        $this->assertSame(3, $data['dbTotalMissing']);
    }
}
