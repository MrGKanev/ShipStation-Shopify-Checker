<?php
declare(strict_types=1);

/**
 * Customer Cohort & LTV page loader.
 *
 * Builds two datasets from a date-range order fetch:
 *   1. Top 100 customers by lifetime value (total spent in the period).
 *   2. Monthly cohort table — customers grouped by the month of their first
 *      order in the period, with repeat-buyer rate and average orders.
 */
class CustomerLTVPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        $ltvResult = null;
        $ltvError  = '';
        $ltvStart  = $_POST['ltv_start'] ?? date('Y-m-d', strtotime('-12 months'));
        $ltvEnd    = $_POST['ltv_end']   ?? date('Y-m-d');

        if ($action === 'scan_ltv') {
            $ltvStart = trim($_POST['ltv_start'] ?? '');
            $ltvEnd   = trim($_POST['ltv_end']   ?? '');

            if ($err = DateRange::validate($ltvStart, $ltvEnd)) {
                $ltvError = $err;
            } elseif (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
                $ltvError = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
            } else {
                try {
                    if (function_exists('set_time_limit')) set_time_limit(300);
                    $shopify     = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], null, $ctx['httpStack'] ?? null);
                    $orders      = $shopify->fetchAllOrders($ltvStart, $ltvEnd);
                    $ltvResult   = self::build($orders, $ltvStart, $ltvEnd);
                } catch (Throwable $e) {
                    $ltvError = $e->getMessage();
                }
            }
        }

        return compact('ltvResult', 'ltvError', 'ltvStart', 'ltvEnd');
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<string, mixed>
     */
    private static function build(array $orders, string $start, string $end): array
    {
        $customers = [];

        foreach ($orders as $o) {
            $email = strtolower(trim((string) ($o['email'] ?? '')));
            if ($email === '') continue;

            // Exclude cancelled orders from revenue totals
            if ($o['cancelled_at'] ?? null) continue;

            $total     = (float) ($o['total_price'] ?? 0);
            $createdAt = (string) ($o['created_at'] ?? '');

            if (!isset($customers[$email])) {
                $customers[$email] = [
                    'email'  => $email,
                    'orders' => 0,
                    'total'  => 0.0,
                    'first'  => $createdAt,
                    'last'   => $createdAt,
                ];
            }

            $customers[$email]['orders']++;
            $customers[$email]['total'] += $total;

            if ($createdAt !== '' && $createdAt < $customers[$email]['first']) {
                $customers[$email]['first'] = $createdAt;
            }
            if ($createdAt > $customers[$email]['last']) {
                $customers[$email]['last'] = $createdAt;
            }
        }

        // ── Top customers by LTV ──────────────────────────────────────────────
        $topCustomers = array_values($customers);
        usort($topCustomers, fn($a, $b) => $b['total'] <=> $a['total']);
        $topCustomers = array_slice($topCustomers, 0, 100);

        foreach ($topCustomers as &$c) {
            $c['avg']        = $c['orders'] > 0 ? $c['total'] / $c['orders'] : 0.0;
            $c['first_date'] = $c['first'] ? substr($c['first'], 0, 10) : '';
            $c['last_date']  = $c['last']  ? substr($c['last'],  0, 10) : '';
        }
        unset($c);

        // ── Cohort table: group by month of first order in period ─────────────
        $cohort = [];

        foreach ($customers as $email => $c) {
            $month = $c['first'] ? substr($c['first'], 0, 7) : '';
            if ($month === '') continue;

            if (!isset($cohort[$month])) {
                $cohort[$month] = [
                    'month'         => $month,
                    'new'           => 0,
                    'repeat'        => 0,
                    'total_orders'  => 0,
                    'total_revenue' => 0.0,
                ];
            }

            $cohort[$month]['new']++;
            $cohort[$month]['total_orders']  += $c['orders'];
            $cohort[$month]['total_revenue'] += $c['total'];

            if ($c['orders'] > 1) {
                $cohort[$month]['repeat']++;
            }
        }

        ksort($cohort);
        $cohortRows = array_values($cohort);

        foreach ($cohortRows as &$row) {
            $row['retention_rate'] = $row['new'] > 0
                ? round($row['repeat'] / $row['new'] * 100, 1)
                : 0.0;
            $row['avg_orders'] = $row['new'] > 0
                ? round($row['total_orders'] / $row['new'], 2)
                : 0.0;
            $row['avg_revenue'] = $row['new'] > 0
                ? $row['total_revenue'] / $row['new']
                : 0.0;
        }
        unset($row);

        $totalRevenue = array_sum(array_column($customers, 'total'));

        return [
            'top_customers'    => $topCustomers,
            'cohort'           => $cohortRows,
            'total_orders'     => count($orders),
            'total_customers'  => count($customers),
            'total_revenue'    => $totalRevenue,
            'start'            => $start,
            'end'              => $end,
        ];
    }
}
