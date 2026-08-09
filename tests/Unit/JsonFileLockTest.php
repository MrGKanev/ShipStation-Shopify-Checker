<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test double exposing the JsonFileLock trait's protected methods.
 */
class JsonFileLockTestDouble
{
    use JsonFileLock;

    public static function write(string $file, callable $mutator): void
    {
        self::writeJson($file, $mutator);
    }

    public static function read(string $file): array
    {
        return self::readJson($file);
    }
}

/**
 * Tests for the JsonFileLock trait - shared by RunLog, PushLog, JobQueue,
 * UserActionLog, PrintQueue, and IgnoreList to make concurrent writes to a
 * single JSON file safe. Each of those classes has its own test suite that
 * exercises writeJson()/readJson() indirectly, but the actual concurrency
 * guarantee (the reason the trait exists) had never been tested directly
 * (docs: "concurrency primitives, currently untested").
 */
class JsonFileLockTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/json_file_lock_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            is_dir($f) ? null : @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testReadJsonReturnsEmptyArrayForMissingFile(): void
    {
        $this->assertSame([], JsonFileLockTestDouble::read($this->tmpDir . '/missing.json'));
    }

    public function testReadJsonReturnsEmptyArrayForMalformedJson(): void
    {
        $file = $this->tmpDir . '/bad.json';
        file_put_contents($file, '{not valid json');

        $this->assertSame([], JsonFileLockTestDouble::read($file));
    }

    public function testWriteJsonPersistsMutatorResult(): void
    {
        $file = $this->tmpDir . '/data.json';

        JsonFileLockTestDouble::write($file, fn(array $data) => array_merge($data, ['a' => 1]));

        $this->assertSame(['a' => 1], JsonFileLockTestDouble::read($file));
    }

    public function testWriteJsonMutatorReceivesCurrentContents(): void
    {
        $file = $this->tmpDir . '/data.json';
        JsonFileLockTestDouble::write($file, fn(array $data) => ['count' => 1]);

        JsonFileLockTestDouble::write($file, function (array $data) {
            $data['count']++;
            return $data;
        });

        $this->assertSame(['count' => 2], JsonFileLockTestDouble::read($file));
    }

    public function testWriteJsonCreatesParentDirectoryIfMissing(): void
    {
        $file = $this->tmpDir . '/nested/dir/data.json';

        JsonFileLockTestDouble::write($file, fn(array $data) => ['x' => 1]);

        $this->assertFileExists($file);
    }

    /**
     * The real-world guarantee this trait exists for: N concurrent OS
     * processes incrementing a shared counter must not lose any updates.
     * Each worker process opens its own file handle and flock()s
     * independently, so this can't be simulated within a single PHP
     * process - it spawns real `php` subprocesses.
     */
    public function testConcurrentWritesFromMultipleProcessesLoseNoUpdates(): void
    {
        $file = $this->tmpDir . '/counter.json';
        JsonFileLockTestDouble::write($file, fn(array $data) => ['count' => 0]);

        $workerScript = $this->tmpDir . '/worker.php';
        file_put_contents($workerScript, $this->workerScriptSource());

        $processes = 5;
        $incrementsPerProcess = 20;
        $handles = [];
        for ($i = 0; $i < $processes; $i++) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerScript)
                . ' ' . escapeshellarg($file) . ' ' . escapeshellarg((string) $incrementsPerProcess);
            $handles[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($handles[count($handles) - 1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
        }
        foreach ($handles as $h) {
            $exitCode = proc_close($h);
            $this->assertSame(0, $exitCode, 'worker process must exit cleanly');
        }

        $final = JsonFileLockTestDouble::read($file);
        $this->assertSame($processes * $incrementsPerProcess, $final['count'], 'no increments should be lost to a race condition');
    }

    private function workerScriptSource(): string
    {
        $srcDir = __DIR__ . '/../../src';
        return <<<PHP
        <?php
        declare(strict_types=1);
        require_once '{$srcDir}/AtomicFile.php';
        require_once '{$srcDir}/JsonFileLock.php';

        class Worker
        {
            use JsonFileLock;

            public static function run(string \$file, int \$times): void
            {
                for (\$i = 0; \$i < \$times; \$i++) {
                    self::writeJson(\$file, function (array \$data) {
                        \$data['count'] = (\$data['count'] ?? 0) + 1;
                        return \$data;
                    });
                }
            }
        }

        Worker::run(\$argv[1], (int) \$argv[2]);
        PHP;
    }
}
