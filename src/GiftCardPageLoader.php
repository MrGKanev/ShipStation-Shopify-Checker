<?php
declare(strict_types=1);

/**
 * Loads the Gift Cards audit page.
 */
class GiftCardPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'giftcards' => self::loadGiftCards($action, $ctx),
            default     => [],
        };
    }

    private static function loadGiftCards(string $action, array $ctx): array
    {
        $gcResult = null;
        $gcError  = '';
        $gcDays   = max(1, (int)($_POST['gc_days'] ?? $_GET['gc_days'] ?? 30));

        if ($action === 'scan_giftcards') {
            $gcDays = max(1, (int)($_POST['gc_days'] ?? 30));
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);

            if ($err = self::requireShopify($ctx)) {
                $gcError = $err;
                self::appendRunLog('scan_giftcards', 'config_error', $runStartedAt, $t0, $gcError);
            } else {
                try {
                    self::setLimits(120);
                    $shopify   = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $giftCards = self::suppressOutput(fn() => $shopify->fetchGiftCards());
                    $scanned   = count($giftCards);
                    $rows      = self::buildGiftCardRows($giftCards, $gcDays, time());

                    $gcResult = ['rows' => $rows, 'scanned' => $scanned, 'days' => $gcDays];
                    self::appendRunLog(
                        'scan_giftcards',
                        count($rows) > 0 ? 'issues_found' : 'ok',
                        $runStartedAt,
                        $t0,
                        scanned: $scanned,
                        rowsFound: count($rows)
                    );
                } catch (Throwable $e) {
                    $gcError = $e->getMessage();
                    self::appendRunLog('scan_giftcards', 'error', $runStartedAt, $t0, $gcError);
                }
            }
        }

        return compact('gcResult', 'gcError', 'gcDays');
    }

    /**
     * Gift Cards rows: enabled gift cards with a remaining balance that are
     * either expiring within $gcDays days, or have never been redeemed
     * (balance still equals initial_value). A card can carry both reasons.
     * Disabled or fully-redeemed cards are excluded. Sorted by balance
     * descending.
     *
     * @param  array<int, array<string, mixed>> $giftCards
     * @return array<int, array<string, mixed>>
     */
    private static function buildGiftCardRows(array $giftCards, int $gcDays, int $now): array
    {
        $rows = [];
        foreach ($giftCards as $gc) {
            if (empty($gc['enabled'])) continue;
            $balance = (float)($gc['balance'] ?? 0);
            if ($balance <= 0) continue;

            $reasons = [];
            $daysUntilExpiry = null;
            $expiresOn = (string)($gc['expires_on'] ?? '');
            if ($expiresOn !== '') {
                $daysUntilExpiry = (int) floor((strtotime($expiresOn) - $now) / 86400);
                if ($daysUntilExpiry <= $gcDays) {
                    $reasons[] = $daysUntilExpiry < 0 ? 'Expired' : "Expiring in {$daysUntilExpiry}d";
                }
            }
            if ($balance === (float)($gc['initial_value'] ?? 0)) {
                $reasons[] = 'Never redeemed';
            }
            if (empty($reasons)) continue;

            $rows[] = [
                'id'                => $gc['id'] ?? '',
                'masked_code'       => $gc['masked_code'] ?? '',
                'balance'           => $balance,
                'initial_value'     => (float)($gc['initial_value'] ?? 0),
                'currency'          => $gc['currency'] ?? '',
                'expires_on'        => $expiresOn,
                'days_until_expiry' => $daysUntilExpiry,
                'created_at'        => self::dateOnly($gc['created_at'] ?? ''),
                'customer_email'    => $gc['customer_email'] ?? '',
                'reasons'           => $reasons,
            ];
        }
        usort($rows, fn($a, $b) => $b['balance'] <=> $a['balance']);
        return $rows;
    }

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
    }

    private static function requireShopify(array $ctx): ?string
    {
        return (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A')
            ? 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.'
            : null;
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

    private static function suppressOutput(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }
}
