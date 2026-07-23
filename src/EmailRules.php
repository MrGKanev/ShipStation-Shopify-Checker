<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

/**
 * File-backed Email notification rules.
 */
class EmailRules
{
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/email_rules.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/email_rules.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'email_enabled'      => false,
            'email_min_missing'  => 1,
            'include_zero_email' => false,
            'email_scan_enabled' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        $file = self::file();
        if (!file_exists($file)) {
            return self::defaults();
        }
        $decoded = json_decode(file_get_contents($file), true);
        return self::normalise(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public static function save(array $rules): void
    {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        AtomicFile::writeJson($file, self::normalise($rules));
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    public static function normalise(array $rules): array
    {
        $d = self::defaults();
        return [
            'email_enabled'      => (bool) ($rules['email_enabled'] ?? $d['email_enabled']),
            'email_min_missing'  => max(0, (int) ($rules['email_min_missing'] ?? $d['email_min_missing'])),
            'include_zero_email' => (bool) ($rules['include_zero_email'] ?? $d['include_zero_email']),
            'email_scan_enabled' => (bool) ($rules['email_scan_enabled'] ?? $d['email_scan_enabled']),
        ];
    }

    public static function shouldNotifyAudit(int $missingCount): bool
    {
        $rules = self::load();
        if (!$rules['email_enabled']) return false;
        if ($missingCount === 0 && !$rules['include_zero_email']) return false;
        return $missingCount >= (int) $rules['email_min_missing'];
    }

    public static function shouldNotifyScan(int $rowsFound): bool
    {
        $rules = self::load();
        return $rules['email_scan_enabled'] && $rowsFound >= 1;
    }
}
