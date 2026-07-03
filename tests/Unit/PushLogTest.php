<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/JsonFileLock.php';
require_once __DIR__ . '/../../src/PushLog.php';

use PHPUnit\Framework\TestCase;

class PushLogTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pushlog_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        PushLog::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testEmptyLogReturnsEmptyArray(): void
    {
        $this->assertSame([], PushLog::all());
    }

    public function testAppendOneEntryIsReturned(): void
    {
        PushLog::append(['order_id' => '123', 'status' => 'pushed']);
        $all = PushLog::all();

        $this->assertCount(1, $all);
        $this->assertSame('123', $all[0]['order_id']);
        $this->assertSame('pushed', $all[0]['status']);
    }

    public function testMultipleAppendsReturnNewestFirst(): void
    {
        PushLog::append(['order_id' => 'first']);
        PushLog::append(['order_id' => 'second']);
        PushLog::append(['order_id' => 'third']);

        $all = PushLog::all();

        $this->assertCount(3, $all);
        $this->assertSame('third', $all[0]['order_id']);
        $this->assertSame('second', $all[1]['order_id']);
        $this->assertSame('first', $all[2]['order_id']);
    }
}
