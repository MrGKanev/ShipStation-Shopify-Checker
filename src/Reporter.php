<?php
/**
 * Reporter — formats and saves the audit results.
 *
 * Terminal output uses basic ASCII so it works cleanly in cron logs.
 * Files saved: reports/missing_YYYY-MM-DD.csv  (machine-readable)
 *              reports/missing_YYYY-MM-DD.txt  (human-readable summary)
 */
class Reporter
{
    private const REPORT_DIR = __DIR__ . '/../reports';

    // ── Terminal output ───────────────────────────────────────────────

    /**
     * @param list<array>                               $missing
     * @param list<array>                               $found
     * @param list<array>                               $skipped
     * @param array<int, array{orderNumber: string, ssOrders: list<array>}> $spotChecks
     */
    public static function printSummary(
        array  $missing,
        array  $found,
        array  $skipped,
        string $startDate,
        string $endDate,
        array  $spotChecks = []
    ): void {
        $total = count($missing) + count($found) + count($skipped);

        echo "\n";
        echo "=======================================================\n";
        echo " AUDIT SUMMARY  {$startDate} -> {$endDate}\n";
        echo "=======================================================\n";
        printf("  Shopify orders in window : %d\n", $total);
        printf("  Matched in ShipStation   : %d\n", count($found));
        printf("  Skipped (cancelled/etc)  : %d\n", count($skipped));
        printf("  MISSING from ShipStation : %d\n", count($missing));
        echo "-------------------------------------------------------\n";

        if (empty($missing)) {
            echo "  [OK] All paid orders are present in ShipStation.\n";
        } else {
            echo "  [!!] Missing orders:\n\n";
            echo "  " . str_pad("Order #", 12)
                       . str_pad("Date", 22)
                       . str_pad("Financial", 14)
                       . "Email\n";
            echo "  " . str_repeat('-', 70) . "\n";

            foreach ($missing as $o) {
                $num       = $o['order_number'] ?? $o['name'] ?? '?';
                $date      = substr($o['created_at'] ?? '', 0, 10);
                $financial = $o['financial_status'] ?? '';
                $email     = $o['email'] ?? '';

                echo "  " . str_pad("#{$num}", 12)
                           . str_pad($date, 22)
                           . str_pad($financial, 14)
                           . $email . "\n";
            }
        }

        // ── Spot-check section ────────────────────────────────────────
        if (!empty($spotChecks)) {
            echo "\n-------------------------------------------------------\n";
            echo "  SPOT-CHECK RESULTS\n";
            echo "-------------------------------------------------------\n";
            foreach ($spotChecks as $sc) {
                $num    = $sc['orderNumber'];
                $orders = $sc['ssOrders'];
                if (empty($orders)) {
                    echo "  #{$num} => NOT FOUND in ShipStation\n";
                } else {
                    foreach ($orders as $o) {
                        $ssNum    = $o['orderNumber']  ?? '?';
                        $status   = $o['orderStatus']  ?? '?';
                        $ssId     = $o['orderId']      ?? '?';
                        echo "  #{$num} => found as SS #{$ssNum} (id={$ssId}, status={$status})\n";
                    }
                }
            }
        }

        echo "=======================================================\n\n";
    }

    // ── File output ───────────────────────────────────────────────────

    /**
     * Save missing orders to CSV and a plain-text summary.
     *
     * @param list<array> $missing
     */
    public static function saveReports(array $missing, string $startDate, string $endDate): void
    {
        $dir = self::REPORT_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stamp = date('Y-m-d');

        // ── CSV ───────────────────────────────────────────────────────
        $csvPath = "{$dir}/missing_{$stamp}.csv";
        $fh = fopen($csvPath, 'w');
        fputcsv($fh, ['order_number', 'shopify_id', 'created_at', 'financial_status', 'fulfillment_status', 'email']);
        foreach ($missing as $o) {
            fputcsv($fh, [
                $o['order_number']      ?? $o['name'] ?? '',
                $o['id']                ?? '',
                $o['created_at']        ?? '',
                $o['financial_status']  ?? '',
                $o['fulfillment_status'] ?? '',
                $o['email']             ?? '',
            ]);
        }
        fclose($fh);

        // ── Plain text ────────────────────────────────────────────────
        $txtPath = "{$dir}/missing_{$stamp}.txt";
        $lines   = [
            "ShipStation missing orders — generated " . date('Y-m-d H:i:s'),
            "Period: {$startDate} -> {$endDate}",
            "Count: " . count($missing),
            "",
        ];
        foreach ($missing as $o) {
            $lines[] = sprintf(
                "#%s  %s  %-12s  %s",
                $o['order_number']     ?? $o['name'] ?? '?',
                substr($o['created_at'] ?? '', 0, 10),
                $o['financial_status'] ?? '',
                $o['email']            ?? ''
            );
        }
        file_put_contents($txtPath, implode("\n", $lines) . "\n");

        if (!empty($missing)) {
            echo "  Reports saved:\n";
            echo "    {$csvPath}\n";
            echo "    {$txtPath}\n\n";
        }
    }
}
