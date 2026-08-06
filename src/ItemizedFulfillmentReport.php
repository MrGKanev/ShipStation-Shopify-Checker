<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

use League\Csv\Writer;

/**
 * Itemized quantity report for fulfilled orders in a date range.
 */
final class ItemizedFulfillmentReport
{
    private const string REPORT_DIR = __DIR__ . '/../reports';

    /**
     * Sums line-item quantities across fulfilled orders, keyed by "title variant".
     *
     * @param array<int, array<string, mixed>> $orders orders shaped like Shopify::fetchAllOrders()
     * @return array<string, int> product label => total quantity, sorted by label
     */
    public static function aggregate(array $orders): array
    {
        $totals = [];
        foreach ($orders as $order) {
            if (($order['fulfillment_status'] ?? '') !== 'fulfilled') {
                continue;
            }
            foreach ($order['line_items'] ?? [] as $item) {
                $variant = $item['variant_title'] ?? null;
                $label   = trim($item['title'] . (($variant && $variant !== 'Default Title') ? " {$variant}" : ''));
                $totals[$label] = ($totals[$label] ?? 0) + (int)($item['quantity'] ?? 0);
            }
        }
        ksort($totals);
        return $totals;
    }

    public static function printSummary(array $totals, string $startDate, string $endDate): void
    {
        echo "\nDate: {$startDate} to {$endDate}\n";
        echo "Order fulfillment status: fulfilled\n";
        echo "Products:\n";
        foreach ($totals as $label => $qty) {
            echo "{$label}: {$qty}\n";
        }
        echo "\n";
    }

    public static function saveCsv(array $totals, string $startDate, string $endDate, string $dir = ''): string
    {
        $dir = $dir ?: self::REPORT_DIR;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $path = "{$dir}/fulfilled_items_{$startDate}_to_{$endDate}.csv";
        $tmp  = tempnam($dir, 'fulfilled_items.csv.tmp.');
        if ($tmp === false) {
            throw new RuntimeException("Could not create temporary report file in {$dir}");
        }

        try {
            $writer = self::writeCsv(Writer::from($tmp, 'w+'), $totals);
            unset($writer);

            if (!@rename($tmp, $path)) {
                throw new RuntimeException("Could not replace report {$path}");
            }
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        return $path;
    }

    /**
     * @param array<string, int> $totals
     */
    public static function toCsvString(array $totals): string
    {
        return self::writeCsv(Writer::fromString(), $totals)->toString();
    }

    /**
     * @param array<string, int> $totals
     */
    public static function emailHtml(array $totals, string $startDate, string $endDate): string
    {
        $rows = '';
        foreach ($totals as $label => $qty) {
            $rows .= '<tr><td style="padding:4px 12px 4px 0">' . self::h($label) . '</td>'
                . '<td style="padding:4px 0;text-align:right">' . $qty . '</td></tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:sans-serif;color:#111;max-width:600px;margin:0 auto;padding:20px">'
            . '<h2 style="margin-bottom:4px">Fulfilled Items Report</h2>'
            . '<p style="margin:0 0 16px;color:#555">' . self::h($startDate) . ' &rarr; ' . self::h($endDate) . ' &middot; fulfillment status: fulfilled</p>'
            . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
            . '<p style="margin-top:20px;font-size:12px;color:#888">Full itemized CSV attached. Sent by Shopify Ops</p>'
            . '</body></html>';
    }

    /**
     * @param array<string, int> $totals
     */
    private static function writeCsv(Writer $writer, array $totals): Writer
    {
        $writer->insertOne(['product', 'quantity']);
        foreach ($totals as $label => $qty) {
            $writer->insertOne([$label, $qty]);
        }
        return $writer;
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
