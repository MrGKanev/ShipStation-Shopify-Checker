<?php
declare(strict_types=1);

use League\Csv\Reader;

/**
 * Loads all view data for each page.
 * Returns an array that gets extract()-ed into the view scope.
 */
class PageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        $data = [];

        // Always loaded
        $data += self::loadGlobal($ctx);

        // Page-specific
        $data += match ($page) {
            'dashboard'   => self::loadDashboard($ctx, $data),
            'run'         => self::loadAudit($action, $ctx, $data),
            'globalsearch'=> SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'spotcheck'   => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'metafields'=> SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'tagsearch' => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'tagaudit'  => SimpleScanPageLoader::load($page, $action, $ctx),
            'dupes'     => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'customer'  => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'cohort'    => CustomerLTVPageLoader::load($page, $action, $ctx),
            'refunds'   => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'addrcheck'  => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'tracking'   => SearchLookupPageLoader::load($page, $action, $ctx, $data),
            'compare'    => OrderInsightPageLoader::load($page, $action, $ctx),
            'emailcheck' => SimpleScanPageLoader::load($page, $action, $ctx),
            'orphans'       => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'hvorders'      => SimpleScanPageLoader::load($page, $action, $ctx),
            'repeatrefunds' => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'failedship'    => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'addrchanges'   => OrderAnomalyPageLoader::load($page, $action, $ctx),
            'timeline'      => OrderInsightPageLoader::load($page, $action, $ctx),
            'orderedits'    => OrderPolicyPageLoader::load($page, $action, $ctx),
            'bundlecheck'   => ProductInventoryPageLoader::load($page, $action, $ctx),
            'productcheck'  => ProductInventoryPageLoader::load($page, $action, $ctx),
            'skudupes'      => ProductInventoryPageLoader::load($page, $action, $ctx),
            'packingslip'       => PackingSlipPageLoader::load($action, $ctx),
            'inventoryoversell' => ProductInventoryPageLoader::load($page, $action, $ctx),
            'countrymismatch'   => SimpleScanPageLoader::load($page, $action, $ctx),
            'partialfulfill'    => SimpleScanPageLoader::load($page, $action, $ctx),
            'onholdstall'       => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'notracking'        => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'postshipaddr'      => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'noteflags'         => OrderPolicyPageLoader::load($page, $action, $ctx),
            'ssshipped'         => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'itemmismatch'      => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'zombieproducts'    => ProductInventoryPageLoader::load($page, $action, $ctx),
            'addrdupes'         => OrderPolicyPageLoader::load($page, $action, $ctx),
            'slabreaches'       => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'activess'          => OrderPolicyPageLoader::load($page, $action, $ctx),
            'discountabuse'     => OrderPolicyPageLoader::load($page, $action, $ctx),
            'tagpolicy'         => OrderPolicyPageLoader::load($page, $action, $ctx),
            'inventoryaging'    => ProductInventoryPageLoader::load($page, $action, $ctx),
            'inventoryforecast' => ProductInventoryPageLoader::load($page, $action, $ctx),
            'shipmentaging'     => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'carrierperf'       => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'shipmargin'        => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'fulfilleditems'    => FulfillmentIssuePageLoader::load($page, $action, $ctx),
            'returns'           => SimpleScanPageLoader::load($page, $action, $ctx),
            'returneditems'     => SimpleScanPageLoader::load($page, $action, $ctx),
            'jobs',
            'slackrules',
            'emailrules',
            'apihealth',
            'configcheck',
            'actionlog',
            'settings',
            'webhookhealth',
            'printqueue'        => ManageSettingsPageLoader::load($page, $action, $ctx),
            default     => [],
        };

        return $data;
    }

    // ── Always-loaded data ────────────────────────────────────────────────────

    private static function loadGlobal(array $ctx): array
    {
        // Probabilistic background prune - keeps cache dir tidy without blocking every request
        if ($ctx['cacheObj'] && mt_rand(1, 10) === 1) {
            $ctx['cacheObj']->pruneExpired();
        }

        $reports      = [];
        $orderHistory = [];
        $reportDir    = $ctx['reportDir'];
        $ignoredOrders = $ctx['ignoredOrders'];

        if (is_dir($reportDir)) {
            $files = glob($reportDir . '/missing_*.csv') ?: [];
            rsort($files);

            foreach ($files as $csvPath) {
                preg_match('/missing_(\d{4}-\d{2}-\d{2})\.csv$/', $csvPath, $m);
                $date    = $m[1] ?? 'unknown';
                $rawRows = [];

                $csv = Reader::from($csvPath, 'r');
                $csv->setHeaderOffset(0);
                foreach ($csv->getRecords() as $record) {
                    $rawRows[] = $record;
                }

                foreach ($rawRows as $row) {
                    $num = Comparator::normalise((string) ($row['order_number'] ?? ''));
                    if (!$num) continue;
                    if (!isset($orderHistory[$num])) {
                        $orderHistory[$num] = ['count' => 0, 'first' => $date, 'last' => $date];
                    }
                    $orderHistory[$num]['count']++;
                    if ($date < $orderHistory[$num]['first']) $orderHistory[$num]['first'] = $date;
                    if ($date > $orderHistory[$num]['last'])  $orderHistory[$num]['last']  = $date;
                }

                $missing = array_values(array_filter(
                    $rawRows,
                    fn($o) => !isset($ignoredOrders[Comparator::normalise((string) ($o['order_number'] ?? ''))])
                ));

                $reports[] = ['date' => $date, 'csvPath' => $csvPath, 'missing' => $missing, 'count' => count($missing)];
            }
        }

        $latestReport = $reports[0] ?? null;
        $selectedDate = $_GET['date'] ?? ($latestReport['date'] ?? null);
        $selectedReport = null;
        foreach ($reports as $r) {
            if ($r['date'] === $selectedDate) { $selectedReport = $r; break; }
        }

        $shopifyStore     = $ctx['shopifyStore'];
        $shopifyAdminBase = 'https://'
            . (str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com")
            . '/admin/orders';

        $pushLog      = PushLog::all();
        $runLog       = RunLog::all();
        $jobLog       = JobQueue::all();
        $actionLog    = UserActionLog::all();
        $bannedIps    = Auth::bannedIps();
        $recentRuns   = AuditSnapshot::recentAcrossTools();

        return compact('reports', 'orderHistory', 'latestReport', 'selectedDate', 'selectedReport',
                       'shopifyAdminBase', 'pushLog', 'runLog', 'jobLog', 'actionLog', 'bannedIps', 'recentRuns');
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    private static function loadAudit(string $action, array $ctx, array $already): array
    {
        $auditResult    = null;
        $auditError     = '';
        $auditDuration  = 0;
        $auditFromCache = ['shopify' => false, 'ss' => false];
        $auditSlack     = ['configured' => SlackNotifier::isConfigured(), 'sent' => false, 'error' => ''];
        $auditEmail     = ['configured' => EmailNotifier::isConfigured(), 'sent' => false, 'error' => ''];
        $cacheEntries   = $ctx['cacheObj']->entries();
        $cacheFlushed   = max(0, (int) ($_GET['cache_flushed'] ?? 0));
        $auditStart     = $_POST['audit_start'] ?? $_GET['start'] ?? date('Y-m-d', strtotime('-12 months'));
        $auditEnd       = $_POST['audit_end']   ?? $_GET['end']   ?? date('Y-m-d');

        if ($action === 'run_audit') {
            $auditStart = $_POST['audit_start'] ?? '';
            $auditEnd   = $_POST['audit_end']   ?? '';
            $runStartedAt = date('Y-m-d H:i:s');

            if ($err = DateRange::validate($auditStart, $auditEnd)) {
                $auditError = $err;
                RunLog::append([
                    'tool'       => 'run_audit',
                    'status'     => 'validation_error',
                    'created_at' => $runStartedAt,
                    'start_date' => $auditStart,
                    'end_date'   => $auditEnd,
                    'error'      => $err,
                    'meta'       => ['api_version' => Shopify::API_VERSION],
                ]);
            } elseif (!$ctx['ssKey'] || !$ctx['ssSecret'] || !$ctx['shopifyToken']) {
                $auditError = 'API credentials missing in .env.';
                RunLog::append([
                    'tool'       => 'run_audit',
                    'status'     => 'config_error',
                    'created_at' => $runStartedAt,
                    'start_date' => $auditStart,
                    'end_date'   => $auditEnd,
                    'error'      => $auditError,
                    'meta'       => ['api_version' => Shopify::API_VERSION],
                ]);
            } else {
                try {
                    self::setLimits(600);
                    ini_set('memory_limit', '512M');
                    $t0 = microtime(true);

                    $ssAuditEnd = date('Y-m-d', strtotime($auditEnd . ' +7 days'));

                    $auditFromCache = [
                        'shopify' => $ctx['cacheObj']->isFresh('shopify', "{$auditStart}|{$auditEnd}"),
                        'ss'      => $ctx['cacheObj']->isFresh('ss',      "{$auditStart}|{$ssAuditEnd}"),
                    ];

                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj']);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);

                    [$shopifyOrders, $ssOrders] = self::suppressOutput(function () use ($ss, $shopify, $auditStart, $auditEnd, $ssAuditEnd) {
                        return [
                            $shopify->fetchAllOrders($auditStart, $auditEnd),
                            $ss->fetchAllOrders($auditStart, $ssAuditEnd),
                        ];
                    });

                    [$comparison, $auditResult] = self::buildAuditRunResult(
                        $shopifyOrders, $ssOrders, $ctx['ignoredOrders'], $ctx['reportDir'], $auditStart, $auditEnd
                    );

                    $auditDuration = round(microtime(true) - $t0, 1);

                    $auditSummary = [
                        'store'          => $ctx['shopifyStore'],
                        'start'          => $auditStart,
                        'end'            => $auditEnd,
                        'duration'       => $auditDuration,
                        'missing_count'  => count($comparison['missing']),
                        'missing_orders' => $comparison['missing'],
                        'found'          => count($comparison['found']),
                        'skipped'        => count($comparison['skipped']),
                        'ignored'        => count($comparison['ignored']),
                        'total_ss'       => count($ssOrders),
                    ];

                    if (SlackRules::shouldNotifyAudit(count($comparison['missing'])) && ($notifier = SlackNotifier::fromEnvironment())) {
                        try {
                            $notifier->notifyAudit($auditSummary);
                            $auditSlack['sent'] = true;
                        } catch (Throwable $e) {
                            $auditSlack['error'] = $e->getMessage();
                            Logger::getInstance()->warning('Slack audit notification failed: {message}', [
                                'message'   => $e->getMessage(),
                                'exception' => $e->getFile() . ':' . $e->getLine(),
                            ]);
                        }
                    }

                    if (EmailRules::shouldNotify('run_audit', count($comparison['missing'])) && ($emailNotifier = EmailNotifier::fromEnvironment())) {
                        try {
                            $emailNotifier->notifyAudit($auditSummary, EmailRules::recipientFor('run_audit'));
                            $auditEmail['sent'] = true;
                        } catch (Throwable $e) {
                            $auditEmail['error'] = $e->getMessage();
                            Logger::getInstance()->warning('Email audit notification failed: {message}', [
                                'message'   => $e->getMessage(),
                                'exception' => $e->getFile() . ':' . $e->getLine(),
                            ]);
                        }
                    }

                    RunLog::append([
                        'tool'       => 'run_audit',
                        'status'     => count($comparison['missing']) > 0 ? 'issues_found' : 'ok',
                        'created_at' => $runStartedAt,
                        'duration'   => $auditDuration,
                        'start_date' => $auditStart,
                        'end_date'   => $auditEnd,
                        'scanned'    => count($shopifyOrders),
                        'rows_found' => count($comparison['missing']),
                        'meta'       => [
                            'api_version' => Shopify::API_VERSION,
                            'shopify_cache' => $auditFromCache['shopify'],
                            'shipstation_cache' => $auditFromCache['ss'],
                            'shipstation_total' => count($ssOrders),
                            'found' => count($comparison['found']),
                            'skipped' => count($comparison['skipped']),
                            'ignored' => count($comparison['ignored']),
                        ],
                    ]);

                    $cacheEntries = $ctx['cacheObj']->entries();
                } catch (Throwable $e) {
                    $auditError = $e->getMessage();
                    RunLog::append([
                        'tool'       => 'run_audit',
                        'status'     => 'error',
                        'created_at' => $runStartedAt,
                        'start_date' => $auditStart,
                        'end_date'   => $auditEnd,
                        'error'      => $auditError,
                        'meta'       => ['api_version' => Shopify::API_VERSION],
                    ]);
                }
            }
        }

        $cacheTtl = $ctx['cacheTtl'];
        return compact('auditResult', 'auditError', 'auditDuration', 'auditFromCache', 'auditSlack', 'auditEmail',
                       'auditStart', 'auditEnd', 'cacheEntries', 'cacheFlushed', 'cacheTtl');
    }

    /**
     * Indexes SS orders, compares against Shopify orders, persists the
     * missing-orders report to $reportDir (must be the caller's
     * store-specific report directory - passing the wrong one means the
     * report silently won't show up in that store's dashboard history,
     * since loadGlobal() reads reports from $ctx['reportDir']), and builds
     * the dashboard result summary.
     *
     * @param  array<int, array<string, mixed>> $shopifyOrders
     * @param  array<int, array<string, mixed>> $ssOrders
     * @param  array<string, array<string, mixed>> $ignoredOrders
     * @return array{0: array{missing: list<array>, found: list<array>, skipped: list<array>, ignored: list<array>}, 1: array<string, mixed>}
     */
    private static function buildAuditRunResult(
        array $shopifyOrders,
        array $ssOrders,
        array $ignoredOrders,
        string $reportDir,
        string $auditStart,
        string $auditEnd
    ): array {
        $ssIndex      = Comparator::buildSSIndex($ssOrders);
        $ssEmailIndex = Comparator::buildSSEmailIndex($ssOrders);
        $comparison   = Comparator::compare($shopifyOrders, $ssIndex, $ignoredOrders, $ssEmailIndex);

        Reporter::saveReports($comparison['missing'], $auditStart, $auditEnd, $reportDir);

        $auditResult = [
            'missing'    => $comparison['missing'],
            'ignored'    => $comparison['ignored'],
            'found'      => count($comparison['found']),
            'skipped'    => count($comparison['skipped']),
            'total_ss'   => count($ssOrders),
            'duplicates' => Comparator::findDuplicates($shopifyOrders),
        ];

        return [$comparison, $auditResult];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    // ── Dashboard ─────────────────────────────────────────────────────────────

    private static function loadDashboard(array $ctx, array $already): array
    {
        $reports       = $already['reports']   ?? [];
        $pushLog       = $already['pushLog']   ?? [];
        $ignoredOrders = $ctx['ignoredOrders'] ?? [];
        $cacheObj      = $ctx['cacheObj'];

        $dbCacheFlushed = max(0, (int) ($_GET['cache_flushed'] ?? 0));

        // Push stats - last 30 days
        $cutoff30      = date('Y-m-d', strtotime('-30 days'));
        $dbPushRecent  = array_values(array_filter(
            $pushLog,
            fn($e) => substr($e['pushed_at'] ?? '', 0, 10) >= $cutoff30
        ));

        // Last 10 reports for the history bar chart
        $dbTrendReports = array_slice($reports, 0, 10);
        $counts         = array_column($dbTrendReports, 'count');
        $dbMaxCount     = max(1, ...(count($counts) ? $counts : [1]));

        // Totals across all history
        $dbTotalReports = count($reports);
        $dbTotalMissing = (int) array_sum(array_column($reports, 'count'));

        // Trend: compare latest vs previous report (-1 better, 0 same, 1 worse)
        $dbTrend = null;
        if (count($reports) >= 2) {
            $dbTrend = $reports[0]['count'] <=> $reports[1]['count'];
        }

        // Last push date
        $dbLastPush = $pushLog[0]['pushed_at'] ?? null;

        // Cache stats
        $dbCacheCount = $cacheObj ? count($cacheObj->entries()) : 0;

        // Days since last audit
        $dbDaysSinceAudit = null;
        if (!empty($reports[0]['date'])) {
            $dbDaysSinceAudit = (int) round(
                (strtotime('today') - strtotime($reports[0]['date'])) / 86400
            );
        }

        // Oldest unresolved missing order (by created_at) in latest report
        $dbOldestMissingDays = null;
        if (!empty($reports[0]['missing'])) {
            $dates = array_filter(array_column($reports[0]['missing'], 'created_at'));
            if ($dates) {
                $dbOldestMissingDays = (int) round(
                    (strtotime('today') - strtotime(substr(min($dates), 0, 10))) / 86400
                );
            }
        }

        // Stale ignored orders (ignored 60+ days ago)
        $cutoff60       = date('Y-m-d', strtotime('-60 days'));
        $dbStaleIgnored = count(array_filter(
            $ignoredOrders,
            fn($e) => ($e['ignored_at'] ?? '9999-99-99') <= $cutoff60
        ));

        // Missing order type breakdown for the latest report
        $dbMissingByType = [];
        foreach ($reports[0]['missing'] ?? [] as $o) {
            $t = $o['order_type'] ?? 'Unknown';
            $dbMissingByType[$t] = ($dbMissingByType[$t] ?? 0) + 1;
        }
        arsort($dbMissingByType);

        // Average days between consecutive audits
        $dbAvgCadence = null;
        if (count($reports) >= 2) {
            $gaps = [];
            for ($i = 0; $i < count($reports) - 1; $i++) {
                $gaps[] = (strtotime($reports[$i]['date']) - strtotime($reports[$i + 1]['date'])) / 86400;
            }
            $dbAvgCadence = (float) round(array_sum($gaps) / count($gaps), 1);
        }

        // Pushes today and this week
        $today     = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('-6 days'));
        $dbPushesToday = count(array_filter($pushLog, fn($e) => substr($e['pushed_at'] ?? '', 0, 10) === $today));
        $dbPushesWeek  = count(array_filter($pushLog, fn($e) => substr($e['pushed_at'] ?? '', 0, 10) >= $weekStart));

        // 7-day audit timeline (null = no audit ran that day)
        $dbLast7DayAudits = [];
        for ($i = 6; $i >= 0; $i--) {
            $dbLast7DayAudits[date('Y-m-d', strtotime("-{$i} days"))] = null;
        }
        foreach ($reports as $r) {
            if (array_key_exists($r['date'], $dbLast7DayAudits)) {
                $dbLast7DayAudits[$r['date']] = $r['count'];
            }
        }

        // Cache freshness
        $dbCacheNewestRefresh  = null;
        $dbCacheFreshCount     = 0;
        $dbCacheExpiredCount   = 0;
        if ($cacheObj) {
            $entries = $cacheObj->entries();
            $dbCacheFreshCount   = count(array_filter($entries, fn($e) => !$e['expired']));
            $dbCacheExpiredCount = count(array_filter($entries, fn($e) => $e['expired']));
            $ttl = $ctx['cacheTtl'] ?? 0;
            if ($entries && $ttl > 0) {
                $maxExpiry = max(array_column($entries, 'expires_at'));
                $dbCacheNewestRefresh = $maxExpiry - $ttl;
            }
        }

        // Average days from first-seen-as-missing to pushed
        $dbAvgResolutionDays = null;
        $orderHistory        = $already['orderHistory'] ?? [];
        $lags = [];
        foreach ($pushLog as $p) {
            $norm   = Comparator::normalise($p['order_number'] ?? '');
            $pushed = substr($p['pushed_at'] ?? '', 0, 10);
            if ($norm && $pushed && isset($orderHistory[$norm]['first'])) {
                $first = $orderHistory[$norm]['first'];
                $lag   = (strtotime($pushed) - strtotime($first)) / 86400;
                if ($lag >= 0) $lags[] = $lag;
            }
        }
        if ($lags) {
            $dbAvgResolutionDays = (float) round(array_sum($lags) / count($lags), 1);
        }

        return compact(
            'dbPushRecent', 'dbTrendReports', 'dbMaxCount',
            'dbTotalReports', 'dbTotalMissing', 'dbTrend',
            'dbLastPush', 'dbCacheCount',
            'dbDaysSinceAudit', 'dbOldestMissingDays', 'dbStaleIgnored',
            'dbMissingByType', 'dbAvgCadence', 'dbAvgResolutionDays',
            'dbPushesToday', 'dbPushesWeek',
            'dbLast7DayAudits',
            'dbCacheNewestRefresh', 'dbCacheFreshCount', 'dbCacheExpiredCount', 'dbCacheFlushed'
        );
    }

}
