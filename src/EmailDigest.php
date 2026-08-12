<?php
declare(strict_types=1);

require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/EmailRules.php';

/**
 * Builds the recipient-grouped sections for the daily email digest.
 * Extracted from email_digest.php so the selection logic (which tools
 * qualify, grouped by recipient) is testable without a real RunLog file
 * or a cron-triggered script run.
 */
final class EmailDigest
{
    /**
     * For every tool in 'digest' mode, finds its latest RunLog entry from
     * $today and includes it if the entry's row/missing count clears the
     * tool's threshold. A digest tool with no run today, or whose latest
     * run today didn't clear the threshold, is silently omitted - the
     * digest only ever reports on today's activity, never stale runs.
     *
     * @param  array<string, array{mode: string, threshold: int, include_zero: bool, email: string}> $rules
     * @param  array<int, array<string, mixed>> $runLog newest first, across all tools
     * @return array<string, array<int, array{tool: string, label: string, count: int, run_at: string}>>
     *         keyed by recipient override ('' means "fall back to ALERT_EMAIL")
     */
    public static function buildSections(array $rules, array $runLog, string $today): array
    {
        $catalog = ToolRegistry::triggerCatalog();

        $latestForTool = [];
        foreach ($runLog as $entry) {
            $tool = (string) ($entry['tool'] ?? '');
            if ($tool === '' || isset($latestForTool[$tool])) {
                continue;
            }
            $latestForTool[$tool] = $entry;
        }

        $grouped = [];
        foreach ($rules as $tool => $rule) {
            if ($rule['mode'] !== 'digest') {
                continue;
            }

            $entry = $latestForTool[$tool] ?? null;
            if ($entry === null || substr((string) ($entry['created_at'] ?? ''), 0, 10) !== $today) {
                continue;
            }

            $count = (int) ($entry['rows_found'] ?? 0);
            if (!EmailRules::thresholdMet($rule, $count)) {
                continue;
            }

            $grouped[$rule['email']][] = [
                'tool'   => $tool,
                'label'  => (string) ($catalog[$tool]['label'] ?? $tool),
                'count'  => $count,
                'run_at' => (string) ($entry['created_at'] ?? ''),
            ];
        }

        return $grouped;
    }
}
