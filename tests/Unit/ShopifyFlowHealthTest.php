<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/ManageSettingsPageLoader.php';

/**
 * Tests for ManageSettingsPageLoader::shopifyFlowHealth() via reflection
 * (private method). The wrapper test (ManageSettingsPageLoaderTest) only
 * covered a fresh error and a fresh success; this fills in the "recovered"
 * case (a tool that failed before but is currently healthy) and the
 * runs/errors counters, which drive the operator-facing health dashboard.
 */
class ShopifyFlowHealthTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(ManageSettingsPageLoader::class);
        self::$method = $ref->getMethod('shopifyFlowHealth');
    }

    private function entry(array $overrides = []): array
    {
        return array_merge([
            'tool'       => 'scan_addresses',
            'status'     => 'ok',
            'created_at' => '2026-06-20 09:00:00',
            'error'      => '',
        ], $overrides);
    }

    private function health(array $runLog): array
    {
        return self::$method->invoke(null, $runLog);
    }

    public function testUnknownToolInRunLogIsIgnored(): void
    {
        $result = $this->health([$this->entry(['tool' => 'not_a_real_flow'])]);

        $this->assertSame(0, $result['summary']['healthy']);
        $this->assertSame(0, $result['summary']['attention']);
    }

    public function testRecoveredToolShowsHealthyStatusButKeepsLastError(): void
    {
        // Newest first: the most recent run succeeded, an earlier one failed.
        $runLog = [
            $this->entry(['status' => 'ok', 'created_at' => '2026-06-20 10:00:00']),
            $this->entry(['status' => 'error', 'created_at' => '2026-06-20 09:00:00', 'error' => 'timeout']),
        ];

        $flow = $this->flowFor($this->health($runLog), 'scan_addresses');

        $this->assertSame('ok', $flow['status']);
        $this->assertSame('timeout', $flow['error_message']);
        $this->assertSame('2026-06-20 09:00:00', $flow['last_error_at']);
    }

    public function testRecoveredToolIsCountedHealthyNotAttention(): void
    {
        $runLog = [
            $this->entry(['status' => 'ok', 'created_at' => '2026-06-20 10:00:00']),
            $this->entry(['status' => 'error', 'created_at' => '2026-06-20 09:00:00', 'error' => 'timeout']),
        ];

        $summary = $this->health($runLog)['summary'];

        $this->assertSame(1, $summary['healthy']);
        $this->assertSame(0, $summary['attention']);
    }

    public function testRunsAndErrorsCountersAccumulateAcrossMultipleEntries(): void
    {
        $runLog = [
            $this->entry(['status' => 'ok']),
            $this->entry(['status' => 'error', 'error' => 'x']),
            $this->entry(['status' => 'ok']),
        ];

        $flow = $this->flowFor($this->health($runLog), 'scan_addresses');

        $this->assertSame(3, $flow['runs']);
        $this->assertSame(1, $flow['errors']);
    }

    public function testEmptyErrorMessageWithStatusOkDoesNotCountAsFailure(): void
    {
        $flow = $this->flowFor($this->health([$this->entry(['status' => 'ok', 'error' => ''])]), 'scan_addresses');

        $this->assertSame(0, $flow['errors']);
    }

    public function testNonEmptyErrorFieldCountsAsFailureEvenWithOkStatus(): void
    {
        // hasFailure is OR'd with a non-empty error string, independent of status.
        $flow = $this->flowFor($this->health([$this->entry(['status' => 'ok', 'error' => 'partial failure noted'])]), 'scan_addresses');

        $this->assertSame(1, $flow['errors']);
    }

    public function testSummaryTotalsMatchFullCatalogSize(): void
    {
        $summary = $this->health([])['summary'];

        $this->assertGreaterThan(20, $summary['total']);
        $this->assertSame($summary['total'], $summary['never_run']);
    }

    private function flowFor(array $health, string $tool): array
    {
        foreach ($health['flows'] as $flow) {
            if ($flow['tool'] === $tool) return $flow;
        }
        $this->fail("flow not found: {$tool}");
    }
}
