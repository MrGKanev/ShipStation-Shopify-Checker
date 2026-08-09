<?php
declare(strict_types=1);

/**
 * Error-handling wrapper shared by audit.php (and any future CLI audit
 * entry point) so the "log to RunLog on failure" behavior is testable
 * without running the CLI script as a subprocess.
 */
final class Audit
{
    /**
     * Runs $work and returns its result. If $work throws, appends a RunLog
     * entry with status 'error' (message from the exception) before
     * rethrowing, so the caller's own error handling (console output, exit
     * code) still runs. On success, $work is responsible for its own
     * RunLog::append() call (it has richer data than this wrapper does).
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    public static function withErrorLogging(callable $work, string $tool, string $startDate, string $endDate): mixed
    {
        $startedAt = date('Y-m-d H:i:s');
        $t0 = microtime(true);

        try {
            return $work();
        } catch (Throwable $e) {
            RunLog::append([
                'tool'       => $tool,
                'status'     => 'error',
                'created_at' => $startedAt,
                'duration'   => round(microtime(true) - $t0, 2),
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'error'      => $e->getMessage(),
                'meta'       => ['api_version' => Shopify::API_VERSION],
            ]);
            throw $e;
        }
    }
}
