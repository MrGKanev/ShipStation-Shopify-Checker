<?php
declare(strict_types=1);

/**
 * Shared helpers for page loaders that run a single real-time scan on
 * demand (no date range) - credential check, run-log entry shape, and
 * the output-suppression/time-limit niceties every one of them needs.
 * Date-range scans get equivalent plumbing from ScanRunner instead.
 */
trait RealtimeScanLoader
{
    private static function requireShopify(array $ctx): ?string
    {
        return (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A')
            ? 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.'
            : null;
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
    }

    private static function suppressOutput(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }

    private static function appendRunLog(
        string $tool,
        string $status,
        string $createdAt,
        float $startedAt,
        string $error = '',
        ?int $scanned = null,
        ?int $rowsFound = null,
        array $meta = []
    ): void {
        RunLog::append([
            'tool'       => $tool,
            'status'     => $status,
            'created_at' => $createdAt,
            'duration'   => round(microtime(true) - $startedAt, 2),
            'scanned'    => $scanned,
            'rows_found' => $rowsFound,
            'error'      => $error,
            'meta'       => ['api_version' => Shopify::API_VERSION] + $meta,
        ]);
    }
}
