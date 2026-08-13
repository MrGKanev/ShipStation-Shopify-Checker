<?php
declare(strict_types=1);

/**
 * Background job execution logic for worker.php, extracted so it's testable
 * without running the CLI script (whose top-level code calls JobQueue::claimNext()
 * and exit() immediately on include).
 */
final class Worker
{
    /**
     * @return array{store_id: string, shopify_store: string, shopify_token: string, ss_key: string, ss_secret: string}
     */
    public static function resolveStore(string $storeId): array
    {
        if (Stores::isMultiStore()) {
            $stores = Stores::all();
            $store  = $stores[0] ?? [];
            foreach ($stores as $candidate) {
                if (($candidate['id'] ?? '') === $storeId) {
                    $store = $candidate;
                    break;
                }
            }
            return [
                'store_id'      => (string)($store['id'] ?? 'default'),
                'shopify_store' => (string)($store['shopify_store'] ?? 'N/A'),
                'shopify_token' => (string)($store['shopify_token'] ?? ''),
                'ss_key'        => (string)($store['ss_key'] ?? ''),
                'ss_secret'     => (string)($store['ss_secret'] ?? ''),
            ];
        }

        return [
            'store_id'      => '',
            'shopify_store' => (string)(getenv('SHOPIFY_STORE') ?: 'N/A'),
            'shopify_token' => (string)(getenv('SHOPIFY_ACCESS_TOKEN') ?: ''),
            'ss_key'        => (string)(getenv('SS_API_KEY') ?: ''),
            'ss_secret'     => (string)(getenv('SS_API_SECRET') ?: ''),
        ];
    }

    public static function configureDataDirs(string $storeId, string $rootDir): void
    {
        if ($storeId === '') return;
        $dataDir = $rootDir . '/data/' . $storeId;
        IgnoreList::setDataDir($dataDir);
        RunLog::setDataDir($dataDir);
        SlackRules::setDataDir($dataDir);
        JobQueue::setDataDir($dataDir);
    }

    /** @param array<string, mixed> $config */
    public static function assertCredentialsPresent(array $config): void
    {
        foreach (['shopify_token', 'ss_key', 'ss_secret'] as $required) {
            if (($config[$required] ?? '') === '') {
                throw new RuntimeException("Missing required credential: {$required}");
            }
        }
    }

    /**
     * Indexes SS orders, compares against Shopify orders, applies the
     * on-hold skip pass, classifies missing orders, and persists the
     * report to $reportDir. Pure given already-fetched order data, so it's
     * testable without a real Shopify/ShipStation client.
     *
     * @param  array<int, array<string, mixed>>    $shopifyOrders
     * @param  array<int, array<string, mixed>>    $ssOrders
     * @param  array<string, array<string, mixed>> $ignoredOrders
     * @param  callable(array): bool               $isOnHold
     * @return array{missing: list<array>, found: list<array>, skipped: list<array>, ignored: list<array>}
     */
    public static function buildAuditComparison(
        array $shopifyOrders,
        array $ssOrders,
        array $ignoredOrders,
        string $reportDir,
        string $start,
        string $end,
        callable $isOnHold
    ): array {
        $comparison = Comparator::compare(
            $shopifyOrders,
            Comparator::buildSSIndex($ssOrders),
            $ignoredOrders,
            Comparator::buildSSEmailIndex($ssOrders)
        );

        return self::finishAuditComparison($comparison, $reportDir, $start, $end, $isOnHold);
    }

    /**
     * Applies the hold-state result, classifies remaining misses and writes the
     * report. Kept separate so runAuditJob() can supply a batched hold lookup.
     *
     * @param array{missing: list<array>, found: list<array>, skipped: list<array>, ignored: list<array>} $comparison
     * @param callable(array): bool $isOnHold
     * @return array{missing: list<array>, found: list<array>, skipped: list<array>, ignored: list<array>}
     */
    public static function finishAuditComparison(
        array $comparison,
        string $reportDir,
        string $start,
        string $end,
        callable $isOnHold
    ): array {

        $comparison = Comparator::applyOnHoldSkip($comparison, $isOnHold);

        foreach ($comparison['missing'] as &$order) {
            $order['_order_type'] = Comparator::classifyOrder($order);
        }
        unset($order);

        Reporter::saveReports($comparison['missing'], $start, $end, $reportDir);

        return $comparison;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function runAuditJob(array $payload, array $config, string $rootDir): array
    {
        $start = (string)($payload['start'] ?? date('Y-m-d', strtotime('-90 days')));
        $end   = (string)($payload['end']   ?? date('Y-m-d'));

        self::assertCredentialsPresent($config);

        $storeId        = $config['store_id'] ?? '';
        $cacheTtl       = (int)(getenv('CACHE_TTL') ?: 82800);
        $cacheRetention = (int)(getenv('CACHE_RETENTION') ?: 1209600);
        $cacheDir       = $rootDir . '/cache'  . ($storeId ? "/{$storeId}" : '');
        $reportDir      = $rootDir . '/reports' . ($storeId ? "/{$storeId}" : '');
        $cache          = new Cache($cacheDir, $cacheTtl, $cacheRetention);

        $t0      = microtime(true);
        $ssEnd   = DateRange::addDays($end, 7);
        $shopify = new Shopify($config['shopify_store'], $config['shopify_token'], $cache);
        $ss      = new ShipStation($config['ss_key'], $config['ss_secret'], $cache);

        $shopifyOrders = $shopify->fetchAllOrders($start, $end);
        $ssOrders      = $ss->fetchAllOrders($start, $ssEnd);

        $comparison = Comparator::compare(
            $shopifyOrders,
            Comparator::buildSSIndex($ssOrders),
            IgnoreList::load(),
            Comparator::buildSSEmailIndex($ssOrders)
        );
        $onHoldIds = $shopify->findOnHoldOrderIds(array_column($comparison['missing'], 'id'));

        $comparison = self::finishAuditComparison(
            $comparison,
            $reportDir,
            $start,
            $end,
            fn(array $order) => isset($onHoldIds[(string)($order['id'] ?? '')])
        );

        $duration = round(microtime(true) - $t0, 2);

        $auditSummary = [
            'store'          => $config['shopify_store'],
            'start'          => $start,
            'end'            => $end,
            'duration'       => $duration,
            'missing_count'  => count($comparison['missing']),
            'missing_orders' => $comparison['missing'],
            'found'          => count($comparison['found']),
            'skipped'        => count($comparison['skipped']),
            'ignored'        => count($comparison['ignored']),
            'total_ss'       => count($ssOrders),
            'mentions'       => SlackRules::mentionText(),
        ];

        if (SlackRules::shouldNotifyAudit(count($comparison['missing'])) && ($notifier = SlackNotifier::fromEnvironment())) {
            $notifier->notifyAuditSafely($auditSummary, Logger::getInstance($rootDir . '/logs'));
        }

        if (EmailRules::shouldNotify('run_audit', count($comparison['missing'])) && ($emailNotifier = EmailNotifier::fromEnvironment())) {
            $emailNotifier->notifyAuditSafely($auditSummary, Logger::getInstance($rootDir . '/logs'), EmailRules::recipientFor('run_audit'));
        }

        RunLog::append([
            'tool'       => 'queued_audit',
            'status'     => count($comparison['missing']) > 0 ? 'issues_found' : 'ok',
            'duration'   => $duration,
            'start_date' => $start,
            'end_date'   => $end,
            'scanned'    => count($shopifyOrders),
            'rows_found' => count($comparison['missing']),
            'meta'       => [
                'api_version'        => Shopify::API_VERSION,
                'shipstation_total'  => count($ssOrders),
                'found'              => count($comparison['found']),
                'skipped'            => count($comparison['skipped']),
                'ignored'            => count($comparison['ignored']),
            ],
        ]);

        return [
            'missing'  => count($comparison['missing']),
            'found'    => count($comparison['found']),
            'skipped'  => count($comparison['skipped']),
            'ignored'  => count($comparison['ignored']),
            'duration' => $duration,
        ];
    }
}
