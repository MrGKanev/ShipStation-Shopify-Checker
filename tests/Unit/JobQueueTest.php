<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/JobQueue.php';

use PHPUnit\Framework\TestCase;

class JobQueueTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/jobqueue_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        JobQueue::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testEnqueueAndClaimNext(): void
    {
        $id = JobQueue::enqueue('audit', ['start' => '2026-06-01'], 'Audit');

        $job = JobQueue::claimNext();

        $this->assertSame($id, $job['id']);
        $this->assertSame('running', $job['status']);
        $this->assertSame('audit', $job['type']);
    }

    public function testCompleteUpdatesJob(): void
    {
        $id = JobQueue::enqueue('audit', []);
        JobQueue::claimNext();
        JobQueue::complete($id, ['missing' => 2]);

        $job = JobQueue::all()[0];

        $this->assertSame('done', $job['status']);
        $this->assertSame(2, $job['result']['missing']);
    }

    public function testFailUpdatesJob(): void
    {
        $id = JobQueue::enqueue('audit', []);
        JobQueue::claimNext();
        JobQueue::fail($id, 'bad credentials');

        $job = JobQueue::all()[0];

        $this->assertSame('failed', $job['status']);
        $this->assertSame('bad credentials', $job['error']);
    }

    public function testEnqueuePrunesOldestJobsBeyondMaxEntries(): void
    {
        $ref = new \ReflectionClass(JobQueue::class);
        $max = $ref->getConstant('MAX_ENTRIES');

        for ($i = 0; $i < $max + 10; $i++) {
            JobQueue::enqueue('audit', ['seq' => $i]);
        }

        $jobs = JobQueue::all();

        $this->assertCount($max, $jobs);
        $this->assertSame($max + 9, $jobs[0]['payload']['seq']);
        $this->assertSame(10, $jobs[$max - 1]['payload']['seq']);
    }

    public function testClaimNextSkipsNonPendingJobsAndReturnsNullWhenNoneLeft(): void
    {
        $id = JobQueue::enqueue('audit', []);
        JobQueue::claimNext();

        $this->assertNull(JobQueue::claimNext());
        $this->assertSame('running', JobQueue::all()[0]['status']);
        $this->assertSame($id, JobQueue::all()[0]['id']);
    }

    public function testImportsLegacyJsonIntoSqliteWithoutDeletingRollbackFile(): void
    {
        $legacy = [[
            'id' => 'legacy-job', 'type' => 'audit', 'label' => 'Legacy', 'status' => 'pending',
            'queued_at' => '2026-01-01 00:00:00', 'started_at' => '', 'finished_at' => '',
            'payload' => ['legacy' => true], 'result' => [], 'error' => '',
        ]];
        file_put_contents($this->tmpDir . '/jobs.json', json_encode($legacy));
        putenv('STATE_STORAGE=sqlite');
        try {
            $this->assertSame('legacy-job', JobQueue::all()[0]['id']);
            $this->assertFileExists($this->tmpDir . '/jobs.json');
            $this->assertFileExists($this->tmpDir . '/state.sqlite');
        } finally {
            putenv('STATE_STORAGE');
        }
    }

    public function testJsonDriverRemainsAvailableAsRollbackPath(): void
    {
        putenv('STATE_STORAGE=json');
        try {
            JobQueue::enqueue('audit', ['rollback' => true]);
            $this->assertFileExists($this->tmpDir . '/jobs.json');
            $this->assertFileDoesNotExist($this->tmpDir . '/state.sqlite');
        } finally {
            putenv('STATE_STORAGE');
        }
    }
}
