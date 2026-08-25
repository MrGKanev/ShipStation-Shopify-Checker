<?php
declare(strict_types=1);

/**
 * Loads the Chargebacks / Disputes Tracker page.
 */
class DisputesPageLoader
{
    use RealtimeScanLoader;

    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'disputes' => self::loadDisputes($action, $ctx),
            default    => [],
        };
    }

    private static function loadDisputes(string $action, array $ctx): array
    {
        $dpResult = null;
        $dpError  = '';

        if ($action === 'scan_disputes') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);

            if ($err = self::requireShopify($ctx)) {
                $dpError = $err;
                self::appendRunLog('scan_disputes', 'config_error', $runStartedAt, $t0, $dpError);
            } else {
                try {
                    self::setLimits(120);
                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], null, $ctx['httpStack'] ?? null);
                    $disputes = self::suppressOutput(fn() => $shopify->fetchOpenDisputes());
                    $rows     = self::buildDisputeRows($disputes, time());

                    $dpResult = ['rows' => $rows, 'scanned' => count($disputes)];
                    self::appendRunLog(
                        'scan_disputes',
                        count($rows) > 0 ? 'issues_found' : 'ok',
                        $runStartedAt,
                        $t0,
                        scanned: count($disputes),
                        rowsFound: count($rows)
                    );
                } catch (Throwable $e) {
                    $dpError = $e->getMessage();
                    self::appendRunLog('scan_disputes', 'error', $runStartedAt, $t0, $dpError);
                }
            }
        }

        return compact('dpResult', 'dpError');
    }

    /**
     * Dispute rows: adds a days_until_due field for the evidence deadline
     * (null when the dispute has no deadline, e.g. UNDER_REVIEW) and sorts
     * the most urgent deadline first. Disputes without an evidenceDueBy
     * sort after ones that have one.
     *
     * @param  array<int, array<string, mixed>> $disputes
     * @return array<int, array<string, mixed>>
     */
    private static function buildDisputeRows(array $disputes, int $now): array
    {
        $rows = [];
        foreach ($disputes as $d) {
            $dueBy = (string)($d['evidence_due_by'] ?? '');
            $rows[] = $d + [
                'days_until_due' => $dueBy !== '' ? (int) ceil((strtotime($dueBy) - $now) / 86400) : null,
            ];
        }
        usort($rows, fn($a, $b) => ($a['days_until_due'] ?? PHP_INT_MAX) <=> ($b['days_until_due'] ?? PHP_INT_MAX));
        return $rows;
    }
}
