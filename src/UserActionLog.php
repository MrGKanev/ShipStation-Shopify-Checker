<?php
declare(strict_types=1);

require_once __DIR__ . '/StateDatabase.php';

/**
 * Append-only audit log for operator actions in the dashboard.
 */
class UserActionLog
{
    use JsonFileLock;

    private const int MAX_ENTRIES = 1000;
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/user_action_log.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/user_action_log.json');
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function append(
        string $action,
        array  $details   = [],
        string $ip        = '',
        string $userAgent = '',
    ): void {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        $entry = [
            'id'         => bin2hex(random_bytes(6)),
            'at'         => date('Y-m-d H:i:s'),
            'action'     => $action,
            'ip'         => $ip        ?: ($_SERVER['REMOTE_ADDR']     ?? 'cli'),
            'user_agent' => substr($userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 180),
            'details'    => $details,
        ];

        if (StateDatabase::enabled()) {
            $pdo = self::database();
            $statement = $pdo->prepare('INSERT INTO user_actions (id, data) VALUES (:id, :data)');
            $statement->execute([':id' => $entry['id'], ':data' => StateDatabase::encode($entry)]);
            $pdo->exec('DELETE FROM user_actions WHERE seq NOT IN (SELECT seq FROM user_actions ORDER BY seq DESC LIMIT ' . self::MAX_ENTRIES . ')');
            return;
        }

        self::writeJson(self::file(), function (array $log) use ($entry): array {
            $log[] = $entry;
            return count($log) > self::MAX_ENTRIES ? array_slice($log, -self::MAX_ENTRIES) : $log;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (StateDatabase::enabled()) {
            $rows = self::database()->query('SELECT data FROM user_actions ORDER BY seq DESC')->fetchAll(PDO::FETCH_COLUMN);
            return array_map(static fn(string $row): array => StateDatabase::decode($row), $rows);
        }
        return array_reverse(self::readJson(self::file()));
    }

    private static function database(): PDO
    {
        $pdo = StateDatabase::connect(self::file());
        StateDatabase::migrateUserActions($pdo, self::file());
        return $pdo;
    }
}
