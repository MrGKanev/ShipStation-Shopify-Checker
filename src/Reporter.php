<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * Reporter - formats and saves the audit results.
 */
class Reporter
{
    private const string REPORT_DIR = __DIR__ . '/../reports';

    // ── Terminal output ───────────────────────────────────────────────

    public static function printSummary(
        array  $missing,
        array  $found,
        array  $skipped,
        string $startDate,
        string $endDate,
        array  $spotChecks = [],
        array  $ignored    = []
    ): void {
        $total = count($missing) + count($found) + count($skipped) + count($ignored);

        echo "\n";
        echo "=======================================================\n";
        echo " AUDIT SUMMARY  {$startDate} -> {$endDate}\n";
        echo "=======================================================\n";
        printf("  Shopify orders in window : %d\n", $total);
        printf("  Matched in ShipStation   : %d\n", count($found));
        printf("  Skipped (cancelled/etc)  : %d\n", count($skipped));
        printf("  Ignored (manual)         : %d\n", count($ignored));
        printf("  MISSING from ShipStation : %d\n", count($missing));
        echo "-------------------------------------------------------\n";

        if (empty($missing)) {
            echo "  [OK] All paid orders are present in ShipStation.\n";
        } else {
            echo "  [!!] Missing orders:\n\n";
            echo "  " . str_pad("Order #", 12) . str_pad("Date", 12)
                      . str_pad("Total", 10)   . str_pad("Financial", 14) . "Email\n";
            echo "  " . str_repeat('-', 76) . "\n";

            foreach ($missing as $o) {
                $num       = $o['order_number'] ?? $o['name'] ?? '?';
                $date      = substr($o['created_at'] ?? '', 0, 10);
                $total_p   = isset($o['total_price']) ? '$' . number_format((float)$o['total_price'], 2) : '-';
                $financial = $o['financial_status'] ?? '';
                $email     = $o['email'] ?? '';

                echo "  " . str_pad("#{$num}", 12) . str_pad($date, 12)
                           . str_pad($total_p, 10) . str_pad($financial, 14) . $email . "\n";
            }
        }

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
                        $ssNum  = $o['orderNumber'] ?? '?';
                        $status = $o['orderStatus'] ?? '?';
                        $ssId   = $o['orderId']     ?? '?';
                        echo "  #{$num} => found as SS #{$ssNum} (id={$ssId}, status={$status})\n";
                    }
                }
            }
        }

        echo "=======================================================\n\n";
    }

    // ── File output ───────────────────────────────────────────────────

    public static function saveReports(array $missing, string $startDate, string $endDate, string $dir = ''): void
    {
        $dir = $dir ?: self::REPORT_DIR;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $stamp = date('Y-m-d');

        // CSV
        $csvPath = "{$dir}/missing_{$stamp}.csv";
        $csvTmp  = tempnam($dir, "missing_{$stamp}.csv.tmp.");
        if ($csvTmp === false) {
            throw new RuntimeException("Could not create temporary report file in {$dir}");
        }

        try {
            $writer = Writer::from($csvTmp, 'w+');
            $writer->addFormatter((new EscapeFormula())->escapeRecord(...));
            $writer->insertOne(['order_number', 'shopify_name', 'shopify_id', 'created_at', 'total_price', 'financial_status', 'fulfillment_status', 'email', 'order_type']);
            foreach ($missing as $o) {
                $writer->insertOne([
                    $o['order_number']       ?? '',
                    $o['name']               ?? '',
                    $o['id']                 ?? '',
                    $o['created_at']         ?? '',
                    $o['total_price']        ?? '',
                    $o['financial_status']   ?? '',
                    $o['fulfillment_status'] ?? '',
                    $o['email']              ?? '',
                    $o['_order_type']        ?? '',
                ]);
            }
            unset($writer);

            if (!@rename($csvTmp, $csvPath)) {
                throw new RuntimeException("Could not replace report {$csvPath}");
            }
        } finally {
            if (file_exists($csvTmp)) {
                @unlink($csvTmp);
            }
        }

        // Plain text
        $txtPath = "{$dir}/missing_{$stamp}.txt";
        $lines   = [
            "ShipStation missing orders - generated " . date('Y-m-d H:i:s'),
            "Period: {$startDate} -> {$endDate}",
            "Count: " . count($missing),
            "",
        ];
        foreach ($missing as $o) {
            $total_p = isset($o['total_price']) ? '$' . number_format((float)$o['total_price'], 2) : '-';
            $num     = $o['order_number'] ?? '';
            $name    = $o['name'] ?? '';
            $lines[] = sprintf(
                "#%s (%s)  %s  %-8s  %-12s  %s",
                $num,
                $name,
                substr($o['created_at'] ?? '', 0, 10),
                $total_p,
                $o['financial_status'] ?? '',
                $o['email']            ?? ''
            );
        }
        AtomicFile::write($txtPath, implode("\n", $lines) . "\n");

        if (!empty($missing) && php_sapi_name() === 'cli') {
            echo "  Reports saved:\n";
            echo "    {$csvPath}\n";
            echo "    {$txtPath}\n\n";
        }
    }
}
