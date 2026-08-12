<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/RunLog.php';

use PHPUnit\Framework\TestCase;

class RunLogTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/runlog_' . uniqid();
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

    public function testAppendAndAllReturnsNewestFirst(): void
    {
        RunLog::append(['tool' => 'first', 'created_at' => '2026-06-18 10:00:00']);
        RunLog::append(['tool' => 'second', 'created_at' => '2026-06-19 10:00:00']);

        $rows = RunLog::all();

        $this->assertCount(2, $rows);
        $this->assertSame('second', $rows[0]['tool']);
        $this->assertSame('first', $rows[1]['tool']);
    }

    public function testAppendSetsDefaults(): void
    {
        RunLog::append(['tool' => 'scan_test']);

        $row = RunLog::all()[0];

        $this->assertSame('scan_test', $row['tool']);
        $this->assertSame('ok', $row['status']);
        $this->assertArrayHasKey('id', $row);
    }

    public function testAppendPrunesOldestEntriesBeyondMaxEntries(): void
    {
        $ref = new \ReflectionClass(RunLog::class);
        $max = $ref->getConstant('MAX_ENTRIES');

        for ($i = 0; $i < $max + 10; $i++) {
            RunLog::append(['tool' => "run-{$i}"]);
        }

        $rows = RunLog::all();

        $this->assertCount($max, $rows);
        $this->assertSame('run-' . ($max + 9), $rows[0]['tool']);
        $this->assertSame('run-10', $rows[$max - 1]['tool']);
    }
}
