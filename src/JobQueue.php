<?php
declare(strict_types=1);

require_once __DIR__ . '/StateDatabase.php';

/**
 * Simple file-backed background job queue.
 */
class JobQueue
{
    use JsonFileLock;

    private const int MAX_ENTRIES = 500;
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/jobs.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/jobs.json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function enqueue(string $type, array $payload, string $label = ''): string
    {
        $id = bin2hex(random_bytes(8));
        $job = [
            'id'          => $id,
            'type'        => $type,
            'label'       => $label ?: $type,
            'status'      => 'pending',
            'queued_at'   => date('Y-m-d H:i:s'),
            'started_at'  => '',
            'finished_at' => '',
            'payload'     => $payload,
            'result'      => [],
            'error'       => '',
        ];

        if (StateDatabase::enabled()) {
            $pdo = self::database();
            $statement = $pdo->prepare('INSERT INTO jobs (id, status, data) VALUES (:id, :status, :data)');
            $statement->execute([':id' => $id, ':status' => 'pending', ':data' => StateDatabase::encode($job)]);
            $pdo->exec('DELETE FROM jobs WHERE seq NOT IN (SELECT seq FROM jobs ORDER BY seq DESC LIMIT ' . self::MAX_ENTRIES . ')');
            return $id;
        }

        self::writeJson(self::file(), function (array $jobs) use ($job): array {
            $jobs[] = $job;
            return count($jobs) > self::MAX_ENTRIES ? array_slice($jobs, -self::MAX_ENTRIES) : $jobs;
        });

        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (StateDatabase::enabled()) {
            $rows = self::database()->query('SELECT data FROM jobs ORDER BY seq DESC')->fetchAll(PDO::FETCH_COLUMN);
            return array_map(static fn(string $row): array => StateDatabase::decode($row), $rows);
        }
        return array_reverse(self::readJson(self::file()));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function claimNext(): ?array
    {
        if (StateDatabase::enabled()) return self::claimNextSqlite();

        $claimed = null;
        self::writeJson(self::file(), function (array $jobs) use (&$claimed): array {
            foreach ($jobs as &$job) {
                if (($job['status'] ?? '') !== 'pending') continue;
                $job['status'] = 'running';
                $job['started_at'] = date('Y-m-d H:i:s');
                $claimed = $job;
                break;
            }
            unset($job);
            return $jobs;
        });
        return $claimed;
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function complete(string $id, array $result = []): void
    {
        self::update($id, [
            'status'      => 'done',
            'finished_at' => date('Y-m-d H:i:s'),
            'result'      => $result,
            'error'       => '',
        ]);
    }

    public static function fail(string $id, string $error): void
    {
        self::update($id, [
            'status'      => 'failed',
            'finished_at' => date('Y-m-d H:i:s'),
            'error'       => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $patch
     */
    private static function update(string $id, array $patch): void
    {
        if (StateDatabase::enabled()) {
            $pdo = self::database();
            $select = $pdo->prepare('SELECT data FROM jobs WHERE id = :id');
            $select->execute([':id' => $id]);
            $raw = $select->fetchColumn();
            if (!is_string($raw)) return;
            $job = array_merge(StateDatabase::decode($raw), $patch);
            $update = $pdo->prepare('UPDATE jobs SET status = :status, data = :data WHERE id = :id');
            $update->execute([
                ':id' => $id,
                ':status' => (string) ($job['status'] ?? ''),
                ':data' => StateDatabase::encode($job),
            ]);
            return;
        }

        self::writeJson(self::file(), function (array $jobs) use ($id, $patch): array {
            foreach ($jobs as &$job) {
                if (($job['id'] ?? '') === $id) {
                    $job = array_merge($job, $patch);
                    break;
                }
            }
            unset($job);
            return $jobs;
        });
    }

    private static function database(): PDO
    {
        $pdo = StateDatabase::connect(self::file());
        StateDatabase::migrateJobs($pdo, self::file());
        return $pdo;
    }

    /** @return array<string, mixed>|null */
    private static function claimNextSqlite(): ?array
    {
        $pdo = self::database();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $raw = $pdo->query("SELECT data FROM jobs WHERE status = 'pending' ORDER BY seq ASC LIMIT 1")->fetchColumn();
            if (!is_string($raw)) {
                $pdo->commit();
                return null;
            }
            $job = StateDatabase::decode($raw);
            $job['status'] = 'running';
            $job['started_at'] = date('Y-m-d H:i:s');
            $update = $pdo->prepare('UPDATE jobs SET status = :status, data = :data WHERE id = :id AND status = :pending');
            $update->execute([
                ':status' => 'running',
                ':data' => StateDatabase::encode($job),
                ':id' => (string) $job['id'],
                ':pending' => 'pending',
            ]);
            $pdo->commit();
            return $update->rowCount() === 1 ? $job : null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

}
