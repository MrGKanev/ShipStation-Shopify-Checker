<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for AtomicFile - the write-tmp-then-rename primitive backing
 * Cache, RunLog, PushLog, JobQueue, UserActionLog, IgnoreList, Auth,
 * AuditSnapshot, SlackRules, EmailRules, SidebarSettings, and Reporter.
 * Widely used but, until now, never tested directly (docs: "concurrency
 * primitives, currently untested").
 */
class AtomicFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/atomic_file_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
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

    public function testWriteCreatesFileWithContents(): void
    {
        $file = $this->tmpDir . '/data.txt';
        AtomicFile::write($file, 'hello world');

        $this->assertSame('hello world', file_get_contents($file));
    }

    public function testWriteOverwritesExistingFile(): void
    {
        $file = $this->tmpDir . '/data.txt';
        AtomicFile::write($file, 'first');
        AtomicFile::write($file, 'second');

        $this->assertSame('second', file_get_contents($file));
    }

    public function testWriteCreatesParentDirectoryIfMissing(): void
    {
        $file = $this->tmpDir . '/nested/dir/data.txt';
        AtomicFile::write($file, 'x');

        $this->assertFileExists($file);
    }

    public function testWriteDoesNotLeaveTempFilesBehind(): void
    {
        AtomicFile::write($this->tmpDir . '/data.txt', 'x');

        $leftovers = glob($this->tmpDir . '/data.txt.tmp.*') ?: [];
        $this->assertSame([], $leftovers, 'the .tmp. staging file must be renamed away, not left on disk');
    }

    public function testWriteAppliesRequestedPermissions(): void
    {
        $file = $this->tmpDir . '/data.txt';
        AtomicFile::write($file, 'x', 0640);

        $this->assertSame('0640', substr(sprintf('%o', fileperms($file)), -4));
    }

    public function testWriteJsonEncodesData(): void
    {
        $file = $this->tmpDir . '/data.json';
        AtomicFile::writeJson($file, ['a' => 1, 'b' => [2, 3]]);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], json_decode(file_get_contents($file), true));
    }

    public function testWriteJsonThrowsOnUnencodableData(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not encode JSON/');

        // NAN cannot be represented in JSON.
        AtomicFile::writeJson($this->tmpDir . '/data.json', ['bad' => NAN]);
    }

    public function testWriteJsonThrowsLeavesNoPartialFile(): void
    {
        $file = $this->tmpDir . '/data.json';
        try {
            AtomicFile::writeJson($file, ['bad' => NAN]);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFileDoesNotExist($file);
    }
}
