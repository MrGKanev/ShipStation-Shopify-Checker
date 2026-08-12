<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

/**
 * File-backed Slack notification rules.
 */
class SlackRules
{
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/slack_rules.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/slack_rules.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'audit_enabled'      => true,
            'audit_min_missing'  => 0,
            'scan_enabled'       => false,
            'scan_min_rows'      => 1,
            'include_zero_audit' => true,
            'mentions'           => '',
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
            'audit_enabled'      => (bool)($rules['audit_enabled'] ?? $d['audit_enabled']),
            'audit_min_missing'  => max(0, (int)($rules['audit_min_missing'] ?? $d['audit_min_missing'])),
            'scan_enabled'       => (bool)($rules['scan_enabled'] ?? $d['scan_enabled']),
            'scan_min_rows'      => max(1, (int)($rules['scan_min_rows'] ?? $d['scan_min_rows'])),
            'include_zero_audit' => (bool)($rules['include_zero_audit'] ?? $d['include_zero_audit']),
            'mentions'           => self::normaliseMentions((string)($rules['mentions'] ?? $d['mentions'])),
        ];
    }

    /**
     * Extracts valid-looking Slack user/group IDs (e.g. "U012ABC3DE",
     * "S0123ABCDE" for a group) from free-text input - garbage tokens
     * (names, emails, stray punctuation) are silently dropped rather than
     * rejecting the whole save, since operators may paste IDs copied from
     * various places with extra whitespace/commas.
     */
    private static function normaliseMentions(string $raw): string
    {
        preg_match_all('/[UWS][A-Z0-9]{8,}/', strtoupper($raw), $matches);
        return implode(' ', array_unique($matches[0]));
    }

    /**
     * @return string[] Slack user/group IDs configured for @-mention on notifications.
     */
    public static function mentionIds(): array
    {
        $mentions = self::load()['mentions'];
        return $mentions === '' ? [] : explode(' ', $mentions);
    }

    /**
     * Formatted "<@ID1> <@ID2> " ready to prepend to a Slack message, or ''
     * when no mentions are configured.
     */
    public static function mentionText(): string
    {
        $ids = self::mentionIds();
        return $ids === [] ? '' : implode(' ', array_map(fn($id) => "<@{$id}>", $ids)) . ' ';
    }

    public static function shouldNotifyAudit(int $missingCount): bool
    {
        $rules = self::load();
        if (!$rules['audit_enabled']) return false;
        if ($missingCount === 0 && !$rules['include_zero_audit']) return false;
        return $missingCount >= (int)$rules['audit_min_missing'];
    }

    public static function shouldNotifyScan(int $rowsFound): bool
    {
        $rules = self::load();
        return $rules['scan_enabled'] && $rowsFound >= (int)$rules['scan_min_rows'];
    }
}
