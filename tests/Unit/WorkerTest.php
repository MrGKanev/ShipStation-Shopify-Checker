<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Worker.php';

/**
 * Tests for Worker - the logic behind worker.php, extracted so it's
 * testable without running the CLI script (its top-level code calls
 * JobQueue::claimNext() and exit() immediately on include, same problem
 * audit.php had before Audit::withErrorLogging was extracted).
 */
class WorkerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/worker_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        Stores::init($this->tmpDir);
        IgnoreList::setDataDir($this->tmpDir);
        RunLog::setDataDir($this->tmpDir);
        SlackRules::setDataDir($this->tmpDir);
        JobQueue::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        Stores::init(__DIR__); // reset so other tests don't see a stale stores.json path
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ── resolveStore ─────────────────────────────────────────────────────────

    public function testResolveStoreSingleStoreModeReadsEnv(): void
    {
        putenv('SHOPIFY_STORE=env-store.myshopify.com');
        putenv('SHOPIFY_ACCESS_TOKEN=tok');
        putenv('SS_API_KEY=key');
        putenv('SS_API_SECRET=secret');

        $config = Worker::resolveStore('');

        $this->assertSame('', $config['store_id']);
        $this->assertSame('env-store.myshopify.com', $config['shopify_store']);

        putenv('SHOPIFY_STORE');
        putenv('SHOPIFY_ACCESS_TOKEN');
        putenv('SS_API_KEY');
        putenv('SS_API_SECRET');
    }

    public function testResolveStoreMultiStoreMatchesRequestedId(): void
    {
        file_put_contents($this->tmpDir . '/stores.json', json_encode([
            ['id' => 'a', 'shopify_store' => 'a.myshopify.com', 'shopify_token' => 'ta', 'ss_key' => 'ka', 'ss_secret' => 'sa'],
            ['id' => 'b', 'shopify_store' => 'b.myshopify.com', 'shopify_token' => 'tb', 'ss_key' => 'kb', 'ss_secret' => 'sb'],
        ]));
        Stores::init($this->tmpDir);

        $config = Worker::resolveStore('b');

        $this->assertSame('b', $config['store_id']);
        $this->assertSame('b.myshopify.com', $config['shopify_store']);
    }

    public function testResolveStoreMultiStoreFallsBackToFirstWhenIdNotFound(): void
    {
        file_put_contents($this->tmpDir . '/stores.json', json_encode([
            ['id' => 'a', 'shopify_store' => 'a.myshopify.com'],
        ]));
        Stores::init($this->tmpDir);

        $config = Worker::resolveStore('does-not-exist');

        $this->assertSame('a', $config['store_id']);
    }

    // ── assertCredentialsPresent ─────────────────────────────────────────────

    public function testAssertCredentialsPresentThrowsForEachMissingCredential(): void
    {
        $base = ['shopify_token' => 't', 'ss_key' => 'k', 'ss_secret' => 's'];
        foreach (['shopify_token', 'ss_key', 'ss_secret'] as $missing) {
            try {
                Worker::assertCredentialsPresent([$missing => ''] + $base);
                $this->fail("expected exception for missing {$missing}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($missing, $e->getMessage());
            }
        }
    }

    public function testAssertCredentialsPresentPassesWhenAllSet(): void
    {
        Worker::assertCredentialsPresent(['shopify_token' => 't', 'ss_key' => 'k', 'ss_secret' => 's']);
        $this->addToAssertionCount(1);
    }

    // ── buildAuditComparison ─────────────────────────────────────────────────

    private function shopifyOrder(array $overrides = []): array
    {
        return array_merge([
            'id' => 1, 'order_number' => 1001, 'name' => '#1001',
            'financial_status' => 'paid', 'fulfillment_status' => null,
            'cancelled_at' => null, 'total_price' => '50.00',
            'email' => 'jane@example.com', 'shipping_lines' => [['title' => 'Standard']],
            'created_at' => '2026-06-01T10:00:00Z',
        ], $overrides);
    }

    public function testBuildAuditComparisonSavesReportToProvidedDir(): void
    {
        $reportDir = $this->tmpDir . '/store-reports';

        Worker::buildAuditComparison([$this->shopifyOrder()], [], [], $reportDir, '2026-06-01', '2026-06-20', fn() => false);

        $this->assertFileExists($reportDir . '/missing_' . date('Y-m-d') . '.csv');
    }

    public function testBuildAuditComparisonAppliesOnHoldSkip(): void
    {
        $reportDir = $this->tmpDir . '/reports2';
        $order = $this->shopifyOrder();

        $comparison = Worker::buildAuditComparison([$order], [], [], $reportDir, '2026-06-01', '2026-06-20', fn() => true);

        $this->assertSame([], $comparison['missing']);
        $this->assertCount(1, $comparison['skipped']);
        $this->assertSame('on_hold', $comparison['skipped'][0]['_skip_reason']);
    }

    public function testBuildAuditComparisonClassifiesMissingOrders(): void
    {
        $reportDir = $this->tmpDir . '/reports3';
        $order = $this->shopifyOrder();

        $comparison = Worker::buildAuditComparison([$order], [], [], $reportDir, '2026-06-01', '2026-06-20', fn() => false);

        $this->assertCount(1, $comparison['missing']);
        $this->assertArrayHasKey('_order_type', $comparison['missing'][0]);
    }

    public function testBuildAuditComparisonRespectsIgnoredOrders(): void
    {
        $reportDir = $this->tmpDir . '/reports4';
        $order = $this->shopifyOrder(['order_number' => 1001]);

        $comparison = Worker::buildAuditComparison([$order], [], ['1001' => ['reason' => 'x']], $reportDir, '2026-06-01', '2026-06-20', fn() => false);

        $this->assertCount(1, $comparison['ignored']);
        $this->assertSame([], $comparison['missing']);
    }
}
