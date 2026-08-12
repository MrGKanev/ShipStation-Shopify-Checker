<?php
declare(strict_types=1);

/**
 * Shared scaffold for date-range scan pages.
 *
 * Handles date extraction, credential checks, validation, run logging,
 * Slack notification thresholds, and exception capture.
 */
final class ScanRunner
{
    /**
     * The callable receives ($ctx, $start, $end) and should return the result array.
     * Extra validation or POST params can be read and mutated via closure bindings.
     *
     * @return array{result: mixed, error: string, start: string, end: string}
     */
    public static function run(
        string   $action,
        string   $trigger,
        array    $ctx,
        string   $prefix,
        callable $fn,
        int      $defaultDays = 30,
        bool     $needsSS     = false
    ): array {
        [$start, $end] = DateRange::fromRequest($prefix, $defaultDays);
        $result = null;
        $error  = '';

        $historyDate = trim($_GET["{$prefix}_history"] ?? '');
        if ($historyDate !== '') {
            $snapshot = AuditSnapshot::load($trigger, $historyDate);
            if ($snapshot === null) {
                $error = "No saved run for {$trigger} on {$historyDate}.";
                return compact('result', 'error', 'start', 'end');
            }
            return [
                'result' => $snapshot['result'],
                'error'  => '',
                'start'  => $snapshot['start'] ?? $start,
                'end'    => $snapshot['end']   ?? $end,
            ];
        }

        if ($action === $trigger) {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);
            $logged = false;
            $start = trim($_POST["{$prefix}_start"] ?? '');
            $end   = trim($_POST["{$prefix}_end"]   ?? '');

            if ($needsSS && ($err = self::requireSS($ctx))) {
                $error = $err;
            } elseif ($err = self::requireShopify($ctx)) {
                $error = $err;
            } elseif ($err = DateRange::validate($start, $end)) {
                $error = $err;
            } else {
                try {
                    $result = $fn($ctx, $start, $end);
                    $rowsFound = self::resultRowCount($result);
                    RunLog::append([
                        'tool'       => $trigger,
                        'status'     => $rowsFound > 0 ? 'issues_found' : 'ok',
                        'created_at' => $runStartedAt,
                        'duration'   => round(microtime(true) - $t0, 2),
                        'start_date' => $start,
                        'end_date'   => $end,
                        'scanned'    => is_array($result) ? ($result['scanned'] ?? $result['total_orders'] ?? null) : null,
                        'rows_found' => $rowsFound,
                        'meta'       => ['api_version' => Shopify::API_VERSION],
                    ]);
                    self::notifyScan($trigger, $result, $rowsFound, $start, $end);
                    if (is_array($result)) {
                        AuditSnapshot::save($trigger, substr($runStartedAt, 0, 10), $result, $start, $end, $rowsFound);
                    }
                    $logged = true;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    RunLog::append([
                        'tool'       => $trigger,
                        'status'     => 'error',
                        'created_at' => $runStartedAt,
                        'duration'   => round(microtime(true) - $t0, 2),
                        'start_date' => $start,
                        'end_date'   => $end,
                        'error'      => $error,
                        'meta'       => ['api_version' => Shopify::API_VERSION],
                    ]);
                    $logged = true;
                }
            }

            if (!$logged && $error !== '' && $result === null) {
                RunLog::append([
                    'tool'       => $trigger,
                    'status'     => 'validation_error',
                    'created_at' => $runStartedAt,
                    'duration'   => round(microtime(true) - $t0, 2),
                    'start_date' => $start,
                    'end_date'   => $end,
                    'error'      => $error,
                    'meta'       => ['api_version' => Shopify::API_VERSION],
                ]);
            }
        }

        return compact('result', 'error', 'start', 'end');
    }

    private static function notifyScan(
        string $trigger,
        mixed $result,
        ?int $rowsFound,
        string $start,
        string $end
    ): void {
        if ($rowsFound === null) {
            return;
        }

        $scanned = is_array($result) ? ($result['scanned'] ?? $result['total_orders'] ?? null) : null;

        if (SlackRules::shouldNotifyScan($rowsFound) && ($notifier = SlackNotifier::fromEnvironment())) {
            try {
                $notifier->notifyScan([
                    'tool'       => $trigger,
                    'rows_found' => $rowsFound,
                    'scanned'    => $scanned,
                    'start'      => $start,
                    'end'        => $end,
                ]);
            } catch (Throwable $e) {
                Logger::getInstance()->warning('Slack scan notification failed: {message}', [
                    'message'   => $e->getMessage(),
                    'exception' => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        if (EmailRules::shouldNotify($trigger, $rowsFound) && ($emailNotifier = EmailNotifier::fromEnvironment())) {
            try {
                $emailNotifier->notifyScan([
                    'tool'       => $trigger,
                    'rows_found' => $rowsFound,
                    'scanned'    => $scanned,
                    'start'      => $start,
                    'end'        => $end,
                ], EmailRules::recipientFor($trigger));
            } catch (Throwable $e) {
                Logger::getInstance()->warning('Email scan notification failed: {message}', [
                    'message'   => $e->getMessage(),
                    'exception' => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }
    }

    private static function resultRowCount(mixed $result): ?int
    {
        if (!is_array($result)) return null;
        if (isset($result['rows']) && is_array($result['rows'])) return count($result['rows']);
        if (isset($result['matches']) && is_array($result['matches'])) return count($result['matches']);
        if (isset($result['pairs']) && is_array($result['pairs'])) return count($result['pairs']);
        return null;
    }

    private static function requireShopify(array $ctx): ?string
    {
        return (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A')
            ? 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.'
            : null;
    }

    private static function requireSS(array $ctx): ?string
    {
        return (!$ctx['ssKey'] || !$ctx['ssSecret'])
            ? 'SS_API_KEY / SS_API_SECRET not set in .env.'
            : null;
    }
}
