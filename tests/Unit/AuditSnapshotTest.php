<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/AuditSnapshot.php';
require_once __DIR__ . '/support/TmpDir.php';

use PHPUnit\Framework\TestCase;

final class AuditSnapshotTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/audit_snapshot_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        AuditSnapshot::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        TmpDir::remove($this->tmpDir);
    }

    public function testSaveThenLoadRoundTripsTheResult(): void
    {
        AuditSnapshot::save('scan_fulfilleditems', '2026-07-15', ['rows' => [['product' => 'Widget', 'quantity' => 5]]], '2026-07-01', '2026-07-31', 1);

        $snapshot = AuditSnapshot::load('scan_fulfilleditems', '2026-07-15');

        $this->assertNotNull($snapshot);
        $this->assertSame('scan_fulfilleditems', $snapshot['tool']);
        $this->assertSame('2026-07-01', $snapshot['start']);
        $this->assertSame('2026-07-31', $snapshot['end']);
        $this->assertSame(1, $snapshot['rows_found']);
        $this->assertSame([['product' => 'Widget', 'quantity' => 5]], $snapshot['result']['rows']);
    }

    public function testLoadReturnsNullWhenNoSnapshotExists(): void
    {
        $this->assertNull(AuditSnapshot::load('scan_fulfilleditems', '2026-07-15'));
    }

    public function testSavingTwiceOnTheSameDayOverwritesThePreviousRun(): void
    {
        AuditSnapshot::save('scan_notracking', '2026-07-15', ['rows' => [['id' => 1]]], '2026-07-01', '2026-07-31', 1);
        AuditSnapshot::save('scan_notracking', '2026-07-15', ['rows' => [['id' => 1], ['id' => 2]]], '2026-07-01', '2026-07-31', 2);

        $snapshot = AuditSnapshot::load('scan_notracking', '2026-07-15');

        $this->assertSame(2, $snapshot['rows_found']);
        $this->assertCount(2, $snapshot['result']['rows']);
    }

    public function testForToolReturnsMostRecentRunsFirst(): void
    {
        AuditSnapshot::save('scan_itemmismatch', '2026-07-01', ['rows' => []], '2026-07-01', '2026-07-01', 0);
        AuditSnapshot::save('scan_itemmismatch', '2026-07-15', ['rows' => [['id' => 1]]], '2026-07-15', '2026-07-15', 1);
        AuditSnapshot::save('scan_itemmismatch', '2026-07-10', ['rows' => []], '2026-07-10', '2026-07-10', 0);

        $runs = AuditSnapshot::forTool('scan_itemmismatch');

        $this->assertSame(['2026-07-15', '2026-07-10', '2026-07-01'], array_column($runs, 'date'));
    }

    public function testForToolRespectsLimit(): void
    {
        foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $date) {
            AuditSnapshot::save('scan_sla', $date, ['rows' => []], $date, $date, 0);
        }

        $runs = AuditSnapshot::forTool('scan_sla', 2);

        $this->assertCount(2, $runs);
        $this->assertSame(['2026-07-03', '2026-07-02'], array_column($runs, 'date'));
    }

    public function testForToolReturnsEmptyArrayWhenToolHasNoHistory(): void
    {
        $this->assertSame([], AuditSnapshot::forTool('scan_never_run'));
    }

    public function testRecentAcrossToolsReturnsNewestFirstAcrossDifferentTools(): void
    {
        AuditSnapshot::save('scan_fulfilleditems', '2026-07-15', ['rows' => []], '2026-07-15', '2026-07-15', 0);
        AuditSnapshot::save('scan_shipmargin', '2026-07-16', ['rows' => [['id' => 1]]], '2026-07-16', '2026-07-16', 1);

        $recent = AuditSnapshot::recentAcrossTools();

        $this->assertSame(
            [['scan_shipmargin', '2026-07-16'], ['scan_fulfilleditems', '2026-07-15']],
            array_map(fn($r) => [$r['tool'], $r['date']], $recent)
        );
    }

    public function testRecentAcrossToolsRespectsLimit(): void
    {
        AuditSnapshot::save('scan_fulfilleditems', '2026-07-14', ['rows' => []], '2026-07-14', '2026-07-14', 0);
        AuditSnapshot::save('scan_fulfilleditems', '2026-07-15', ['rows' => []], '2026-07-15', '2026-07-15', 0);
        AuditSnapshot::save('scan_shipmargin', '2026-07-16', ['rows' => []], '2026-07-16', '2026-07-16', 0);

        $recent = AuditSnapshot::recentAcrossTools(2);

        $this->assertCount(2, $recent);
        $this->assertSame(['scan_shipmargin', 'scan_fulfilleditems'], array_column($recent, 'tool'));
    }

    public function testRecentAcrossToolsReturnsEmptyArrayWhenNothingSaved(): void
    {
        $this->assertSame([], AuditSnapshot::recentAcrossTools());
    }
}
