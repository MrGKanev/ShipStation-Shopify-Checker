<?php
declare(strict_types=1);

final class DashboardPageLoader
{
    /** @param array<string, mixed> $ctx @param array<string, mixed> $already @return array<string, mixed> */
    public static function load(array $ctx, array $already): array
    {
        $reports = $already['reports'] ?? [];
        $pushLog = $already['pushLog'] ?? [];
        $ignoredOrders = $ctx['ignoredOrders'] ?? [];
        $cacheObj = $ctx['cacheObj'];
        $dbCacheFlushed = max(0, (int) ($_GET['cache_flushed'] ?? 0));

        $cutoff30 = date('Y-m-d', strtotime('-30 days'));
        $dbPushRecent = array_values(array_filter($pushLog, fn($e) => substr($e['pushed_at'] ?? '', 0, 10) >= $cutoff30));
        $dbTrendReports = array_slice($reports, 0, 10);
        $counts = array_column($dbTrendReports, 'count');
        $dbMaxCount = max(1, ...(count($counts) ? $counts : [1]));
        $dbTotalReports = count($reports);
        $dbTotalMissing = (int) array_sum(array_column($reports, 'count'));
        $dbTrend = count($reports) >= 2 ? $reports[0]['count'] <=> $reports[1]['count'] : null;
        $dbLastPush = $pushLog[0]['pushed_at'] ?? null;
        $dbCacheCount = $cacheObj ? count($cacheObj->entries()) : 0;

        $dbDaysSinceAudit = null;
        if (!empty($reports[0]['date'])) {
            $dbDaysSinceAudit = (int) round((strtotime('today') - strtotime($reports[0]['date'])) / 86400);
        }

        $dbOldestMissingDays = null;
        if (!empty($reports[0]['missing'])) {
            $dates = array_filter(array_column($reports[0]['missing'], 'created_at'));
            if ($dates) $dbOldestMissingDays = (int) round((strtotime('today') - strtotime(substr(min($dates), 0, 10))) / 86400);
        }

        $cutoff60 = date('Y-m-d', strtotime('-60 days'));
        $dbStaleIgnored = count(array_filter($ignoredOrders, fn($e) => ($e['ignored_at'] ?? '9999-99-99') <= $cutoff60));

        $dbMissingByType = [];
        foreach ($reports[0]['missing'] ?? [] as $order) {
            $type = $order['order_type'] ?? 'Unknown';
            $dbMissingByType[$type] = ($dbMissingByType[$type] ?? 0) + 1;
        }
        arsort($dbMissingByType);

        $dbAvgCadence = null;
        if (count($reports) >= 2) {
            $gaps = [];
            for ($i = 0; $i < count($reports) - 1; $i++) {
                $gaps[] = (strtotime($reports[$i]['date']) - strtotime($reports[$i + 1]['date'])) / 86400;
            }
            $dbAvgCadence = (float) round(array_sum($gaps) / count($gaps), 1);
        }

        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('-6 days'));
        $dbPushesToday = count(array_filter($pushLog, fn($e) => substr($e['pushed_at'] ?? '', 0, 10) === $today));
        $dbPushesWeek = count(array_filter($pushLog, fn($e) => substr($e['pushed_at'] ?? '', 0, 10) >= $weekStart));

        $dbLast7DayAudits = [];
        for ($i = 6; $i >= 0; $i--) $dbLast7DayAudits[date('Y-m-d', strtotime("-{$i} days"))] = null;
        foreach ($reports as $report) {
            if (array_key_exists($report['date'], $dbLast7DayAudits)) $dbLast7DayAudits[$report['date']] = $report['count'];
        }

        $dbCacheNewestRefresh = null;
        $dbCacheFreshCount = 0;
        $dbCacheExpiredCount = 0;
        if ($cacheObj) {
            $entries = $cacheObj->entries();
            $dbCacheFreshCount = count(array_filter($entries, fn($e) => !$e['expired']));
            $dbCacheExpiredCount = count(array_filter($entries, fn($e) => $e['expired']));
            $ttl = $ctx['cacheTtl'] ?? 0;
            if ($entries && $ttl > 0) $dbCacheNewestRefresh = max(array_column($entries, 'expires_at')) - $ttl;
        }

        $dbAvgResolutionDays = null;
        $orderHistory = $already['orderHistory'] ?? [];
        $lags = [];
        foreach ($pushLog as $push) {
            $normal = Comparator::normalise($push['order_number'] ?? '');
            $pushed = substr($push['pushed_at'] ?? '', 0, 10);
            if ($normal && $pushed && isset($orderHistory[$normal]['first'])) {
                $lag = (strtotime($pushed) - strtotime($orderHistory[$normal]['first'])) / 86400;
                if ($lag >= 0) $lags[] = $lag;
            }
        }
        if ($lags) $dbAvgResolutionDays = (float) round(array_sum($lags) / count($lags), 1);

        return compact(
            'dbPushRecent', 'dbTrendReports', 'dbMaxCount', 'dbTotalReports', 'dbTotalMissing', 'dbTrend',
            'dbLastPush', 'dbCacheCount', 'dbDaysSinceAudit', 'dbOldestMissingDays', 'dbStaleIgnored',
            'dbMissingByType', 'dbAvgCadence', 'dbAvgResolutionDays', 'dbPushesToday', 'dbPushesWeek',
            'dbLast7DayAudits', 'dbCacheNewestRefresh', 'dbCacheFreshCount', 'dbCacheExpiredCount', 'dbCacheFlushed'
        );
    }
}
