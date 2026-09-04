<?php
declare(strict_types=1);

require_once __DIR__ . '/DashboardPageLoader.php';

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
            'dashboard'   => DashboardPageLoader::load($ctx, $data),
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
            'taxaudit'          => SimpleScanPageLoader::load($page, $action, $ctx),
            'consentaudit'      => OrderPolicyPageLoader::load($page, $action, $ctx),
            'riskreport'        => OrderPolicyPageLoader::load($page, $action, $ctx),
            'sameip'            => OrderPolicyPageLoader::load($page, $action, $ctx),
            'disputes'          => DisputesPageLoader::load($page, $action, $ctx),
            'catalogquality'    => ProductInventoryPageLoader::load($page, $action, $ctx),
            'giftcards'         => GiftCardPageLoader::load($page, $action, $ctx),
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
            $manifest = implode('|', array_map(
                fn(string $path) => basename($path) . ':' . filemtime($path) . ':' . filesize($path) . ':' . fileinode($path),
                $files
            ));
            $build = fn() => self::buildReportHistory($files);
            $summary = $ctx['cacheObj']
                ? $ctx['cacheObj']->remember('report_history', $manifest, $build, 3600)
                : $build();
            $reports = $summary['reports'];
            $orderHistory = $summary['orderHistory'];
            foreach ($reports as &$report) {
                $report['count'] = count(array_filter(
                    $report['orderNumbers'] ?? [],
                    fn(string $number) => !isset($ignoredOrders[$number])
                ));
            }
            unset($report);
        }

        $latestReport = $reports[0] ?? null;
        $selectedDate = $_GET['date'] ?? ($latestReport['date'] ?? null);
        $populateMissing = function (array $report) use ($ignoredOrders): array {
            $missing = self::visibleReportRows($report['csvPath'], $ignoredOrders);
            return $report + ['missing' => $missing, 'count' => count($missing)];
        };

        $selectedReport = null;
        foreach ($reports as $i => $r) {
            if ($r['date'] !== $selectedDate) continue;
            $selectedReport = $reports[$i] = $populateMissing($r);
            break;
        }
        // views/dashboard.php and views/layout.php read $latestReport['missing']
        // unconditionally, so reports[0] must carry it even when $selectedDate
        // (driven by the global ?date= param) points at an older report.
        if (isset($reports[0])) {
            $reports[0] = ($selectedReport !== null && $reports[0]['date'] === $selectedDate)
                ? $selectedReport
                : $populateMissing($reports[0]);
        }
        $latestReport   = $reports[0] ?? null;
        $selectedReport ??= $latestReport;

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

    /** @return array{reports: array<int, array{date: string, csvPath: string, count: int}>, orderHistory: array<string, array{count: int, first: string, last: string}>} */
    private static function buildReportHistory(array $files): array
    {
        $reports = [];
        $orderHistory = [];
        foreach ($files as $csvPath) {
            preg_match('/missing_(\d{4}-\d{2}-\d{2})\.csv$/', $csvPath, $m);
            $date = $m[1] ?? 'unknown';
            $rows = self::visibleReportRows($csvPath, []);
            $orderNumbers = [];
            foreach ($rows as $row) {
                $num = Comparator::normalise((string)($row['order_number'] ?? ''));
                if ($num === '') continue;
                $orderNumbers[] = $num;
                $entry = $orderHistory[$num] ?? ['count' => 0, 'first' => $date, 'last' => $date];
                $entry['count']++;
                $entry['first'] = min($entry['first'], $date);
                $entry['last'] = max($entry['last'], $date);
                $orderHistory[$num] = $entry;
            }
            $reports[] = ['date' => $date, 'csvPath' => $csvPath, 'count' => count($rows), 'orderNumbers' => $orderNumbers];
        }
        return compact('reports', 'orderHistory');
    }

    /** @return array<int, array<string, mixed>> */
    private static function visibleReportRows(string $csvPath, array $ignoredOrders): array
    {
        $csv = Reader::from($csvPath, 'r');
        $csv->setHeaderOffset(0);
        $rows = [];
        foreach ($csv->getRecords() as $row) {
            if (!isset($ignoredOrders[Comparator::normalise((string)($row['order_number'] ?? ''))])) {
                $rows[] = $row;
            }
        }
        return $rows;
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

                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret'], $ctx['cacheObj'], $ctx['httpStack'] ?? null);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj'], $ctx['httpStack'] ?? null);

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
                        'mentions'       => SlackRules::mentionText(),
                    ];

                    if (SlackRules::shouldNotifyAudit(count($comparison['missing'])) && ($notifier = $ctx['slackNotifier'] ?? SlackNotifier::fromEnvironment())) {
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

                    if (EmailRules::shouldNotify('run_audit', count($comparison['missing'])) && ($emailNotifier = $ctx['emailNotifier'] ?? EmailNotifier::fromEnvironment())) {
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

}
