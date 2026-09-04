<?php
declare(strict_types=1);

/**
 * SQLite persistence for mutable operational state.
 *
 * Legacy JSON files are imported once and deliberately kept as a rollback copy.
 */
final class StateDatabase
{
    private const int BUSY_TIMEOUT_MS = 5000;

    public static function enabled(): bool
    {
        $driver = strtolower(trim((string) (getenv('STATE_STORAGE') ?: 'sqlite')));
        return $driver !== 'json' && extension_loaded('pdo_sqlite');
    }

    public static function pathForJson(string $jsonFile): string
    {
        return dirname($jsonFile) . '/state.sqlite';
    }

    public static function connect(string $jsonFile): PDO
    {
        $dir = dirname($jsonFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create state directory: ' . $dir);
        }

        $pdo = new PDO('sqlite:' . self::pathForJson($jsonFile));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        self::createSchema($pdo);
        return $pdo;
    }

    public static function migrateJobs(PDO $pdo, string $jsonFile): void
    {
        self::migrate($pdo, 'jobs', $jsonFile, static function (PDO $pdo, array $entry): void {
            if (!isset($entry['id'])) return;
            $statement = $pdo->prepare(
                'INSERT OR IGNORE INTO jobs (id, status, data) VALUES (:id, :status, :data)'
            );
            $statement->execute([
                ':id' => (string) $entry['id'],
                ':status' => (string) ($entry['status'] ?? 'pending'),
                ':data' => self::encode($entry),
            ]);
        });
    }

    public static function migrateUserActions(PDO $pdo, string $jsonFile): void
    {
        self::migrate($pdo, 'user_actions', $jsonFile, static function (PDO $pdo, array $entry): void {
            if (!isset($entry['id'])) return;
            $statement = $pdo->prepare(
                'INSERT OR IGNORE INTO user_actions (id, data) VALUES (:id, :data)'
            );
            $statement->execute([
                ':id' => (string) $entry['id'],
                ':data' => self::encode($entry),
            ]);
        });
    }

    /** @param array<string, mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public static function decode(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private static function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS state_migrations (
            name TEXT PRIMARY KEY,
            imported_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS jobs (
            seq INTEGER PRIMARY KEY AUTOINCREMENT,
            id TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL,
            data TEXT NOT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS jobs_status_seq ON jobs (status, seq)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_actions (
            seq INTEGER PRIMARY KEY AUTOINCREMENT,
            id TEXT NOT NULL UNIQUE,
            data TEXT NOT NULL
        )');
    }

    /** @param callable(PDO, array<string, mixed>): void $insert */
    private static function migrate(PDO $pdo, string $name, string $jsonFile, callable $insert): void
    {
        $check = $pdo->prepare('SELECT 1 FROM state_migrations WHERE name = :name');
        $check->execute([':name' => $name]);
        if ($check->fetchColumn() !== false) return;

        $rows = [];
        if (is_file($jsonFile)) {
            $raw = file_get_contents($jsonFile);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (is_array($decoded)) $rows = $decoded;
        }

        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                if (is_array($row)) $insert($pdo, $row);
            }
            $mark = $pdo->prepare(
                'INSERT OR IGNORE INTO state_migrations (name, imported_at) VALUES (:name, :at)'
            );
            $mark->execute([':name' => $name, ':at' => date(DATE_ATOM)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
