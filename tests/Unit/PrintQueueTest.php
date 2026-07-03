<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/JsonFileLock.php';
require_once __DIR__ . '/../../src/PrintQueue.php';

use PHPUnit\Framework\TestCase;

class PrintQueueTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/printqueue_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        PrintQueue::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testEmptyQueueReturnsEmptyArray(): void
    {
        $this->assertSame([], PrintQueue::all());
    }

    public function testAddOneItemHasExpectedKeys(): void
    {
        PrintQueue::add('ORD-001', 'test note');
        $all = PrintQueue::all();

        $this->assertCount(1, $all);
        $this->assertArrayHasKey('order_number', $all[0]);
        $this->assertArrayHasKey('note', $all[0]);
        $this->assertArrayHasKey('queued_at', $all[0]);
        $this->assertSame('ORD-001', $all[0]['order_number']);
        $this->assertSame('test note', $all[0]['note']);
    }

    public function testAddDuplicateKeepsOnlyOneEntry(): void
    {
        PrintQueue::add('ORD-002');
        PrintQueue::add('ORD-002');

        $this->assertCount(1, PrintQueue::all());
    }

    public function testAddEmptyStringIsIgnored(): void
    {
        PrintQueue::add('');
        PrintQueue::add('   ');

        $this->assertSame([], PrintQueue::all());
    }

    public function testRemoveExistingEntryIsGone(): void
    {
        PrintQueue::add('ORD-003');
        PrintQueue::add('ORD-004');
        PrintQueue::remove('ORD-003');

        $all = PrintQueue::all();
        $this->assertCount(1, $all);
        $this->assertSame('ORD-004', $all[0]['order_number']);
    }

    public function testRemoveNonExistentCausesNoError(): void
    {
        PrintQueue::add('ORD-005');
        PrintQueue::remove('ORD-DOES-NOT-EXIST');

        $this->assertCount(1, PrintQueue::all());
    }

    public function testClearEmptiesQueue(): void
    {
        PrintQueue::add('ORD-006');
        PrintQueue::add('ORD-007');
        PrintQueue::clear();

        $this->assertSame([], PrintQueue::all());
    }
}
