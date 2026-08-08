<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

/**
 * Generic per-tool, per-day result snapshot store backing the sidebar run history.
 * One JSON file per (tool, date); a later run on the same day overwrites the earlier
 * snapshot. Lets the "history" links in the sidebar re-open the exact past result
 * instead of only showing that a scan happened.
 */
final class AuditSnapshot
{
    private static string $customDir = '';

    public static function setDataDir(string $dir): void
    {
        self::$customDir = rtrim($dir, '/') . '/history';
    }

    private static function dir(): string
    {
        return self::$customDir ?: (__DIR__ . '/../data/history');
    }

    /**
     * @param array<string, mixed> $result the loader's result array (must be JSON-serialisable)
     */
    public static function save(string $tool, string $date, array $result, string $start, string $end, ?int $rowsFound): void
    {
        $dir = self::dir() . '/' . self::safeName($tool);
        $path = "{$dir}/{$date}.json";

        AtomicFile::writeJson($path, [
            'tool'       => $tool,
            'date'       => $date,
            'start'      => $start,
            'end'        => $end,
            'rows_found' => $rowsFound,
            'saved_at'   => date('Y-m-d H:i:s'),
            'result'     => $result,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function load(string $tool, string $date): ?array
    {
        $path = self::dir() . '/' . self::safeName($tool) . "/{$date}.json";
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Most recent snapshots for a single tool, newest first.
     *
     * @return array<int, array{date: string, rows_found: int|null, saved_at: string}>
     */
    public static function forTool(string $tool, int $limit = 5): array
    {
        $dir = self::dir() . '/' . self::safeName($tool);
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json') ?: [];
        rsort($files);
        $files = array_slice($files, 0, max(0, $limit));

        $rows = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $rows[] = [
                'date'       => (string) ($data['date'] ?? ''),
                'rows_found' => $data['rows_found'] ?? null,
                'saved_at'   => (string) ($data['saved_at'] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * Most recent runs across every tool, newest first - backs the compact
     * "Recent Activity" feed in the sidebar (as opposed to forTool(), which is
     * the full per-tool history shown on that audit's own history page).
     *
     * @return array<int, array{tool: string, date: string, rows_found: int|null, saved_at: string}>
     */
    public static function recentAcrossTools(int $limit = 8): array
    {
        $base = self::dir();
        if (!is_dir($base)) {
            return [];
        }

        $rows = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $toolDir) {
            $tool = basename($toolDir);
            foreach (self::forTool($tool, $limit) as $run) {
                $rows[] = ['tool' => $tool] + $run;
            }
        }

        usort($rows, fn($a, $b) => [$b['saved_at'], $b['date']] <=> [$a['saved_at'], $a['date']]);

        return array_slice($rows, 0, max(0, $limit));
    }

    private static function safeName(string $tool): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '_', $tool) ?? $tool;
    }
}
