<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/UserActionLog.php';

use PHPUnit\Framework\TestCase;

class UserActionLogTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/actionlog_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        UserActionLog::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testAppendStoresNewestFirst(): void
    {
        UserActionLog::append('ignore_order', ['order_number' => '1001']);
        UserActionLog::append('unignore_order', ['order_number' => '1001']);

        $rows = UserActionLog::all();

        $this->assertSame('unignore_order', $rows[0]['action']);
        $this->assertSame('ignore_order', $rows[1]['action']);
        $this->assertSame('1001', $rows[0]['details']['order_number']);
    }

    public function testAppendPrunesOldestEntriesBeyondMaxEntries(): void
    {
        $ref = new \ReflectionClass(UserActionLog::class);
        $max = $ref->getConstant('MAX_ENTRIES');

        for ($i = 0; $i < $max + 10; $i++) {
            UserActionLog::append('ignore_order', ['order_number' => (string) $i]);
        }

        $rows = UserActionLog::all();

        $this->assertCount($max, $rows);
        $this->assertSame((string) ($max + 9), $rows[0]['details']['order_number']);
        $this->assertSame('10', $rows[$max - 1]['details']['order_number']);
    }

    public function testImportsLegacyJsonIntoSharedSqliteDatabase(): void
    {
        file_put_contents($this->tmpDir . '/user_action_log.json', json_encode([[
            'id' => 'legacy-action', 'at' => '2026-01-01 00:00:00', 'action' => 'legacy',
            'ip' => 'cli', 'user_agent' => 'cli', 'details' => ['imported' => true],
        ]]));
        putenv('STATE_STORAGE=sqlite');
        try {
            $this->assertSame('legacy-action', UserActionLog::all()[0]['id']);
            $this->assertTrue(UserActionLog::all()[0]['details']['imported']);
            $this->assertFileExists($this->tmpDir . '/user_action_log.json');
        } finally {
            putenv('STATE_STORAGE');
        }
    }
}
