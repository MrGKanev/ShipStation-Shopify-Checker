<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * Itemized quantity report for fulfilled orders in a date range.
 */
final class ItemizedFulfillmentReport
{
    private const string REPORT_DIR = __DIR__ . '/../reports';

    /**
     * Sums line-item quantities across fulfillments shipped within [startDate, endDate],
     * keyed by "title variant". Orders are matched by each fulfillment's own created_at,
     * not the order's creation date or overall status, so an order created before the
     * window but shipped inside it is still counted — and only its shipped items.
     *
     * @param array<int, array<string, mixed>> $orders orders shaped like Shopify::fetchOrdersFulfilledSince()
     * @return array<string, int> product label => total quantity, sorted by label
     */
    public static function aggregate(array $orders, string $startDate, string $endDate): array
    {
        $totals = [];
        foreach (self::fulfillmentsInRange($orders, $startDate, $endDate) as ['items' => $items]) {
            foreach ($items as $item) {
                $label = self::itemLabel($item);
                $totals[$label] = ($totals[$label] ?? 0) + (int)($item['quantity'] ?? 0);
            }
        }
        ksort($totals);
        return $totals;
    }

    /**
     * Same as aggregate(), but keeps each fulfillment's line items separate
     * instead of summing across the whole date range.
     *
     * @param array<int, array<string, mixed>> $orders orders shaped like Shopify::fetchOrdersFulfilledSince()
     * @return array<int, array{order: string, product: string, quantity: int}> sorted by order, then product
     */
    public static function itemizeByOrder(array $orders, string $startDate, string $endDate): array
    {
        $rows = [];
        foreach (self::fulfillmentsInRange($orders, $startDate, $endDate) as ['orderLabel' => $orderLabel, 'items' => $items]) {
            foreach ($items as $item) {
                $label = self::itemLabel($item);
                $key   = "{$orderLabel}\0{$label}";
                if (!isset($rows[$key])) {
                    $rows[$key] = ['order' => $orderLabel, 'product' => $label, 'quantity' => 0];
                }
                $rows[$key]['quantity'] += (int)($item['quantity'] ?? 0);
            }
        }

        usort($rows, fn($a, $b) => [$a['order'], $a['product']] <=> [$b['order'], $b['product']]);
        return $rows;
    }

    /**
     * Groups shipped line items by product and records the order numbers that contain each product.
     *
     * @param array<int, array<string, mixed>> $orders orders shaped like Shopify::fetchOrdersFulfilledSince()
     * @return array<int, array{product: string, quantity: int, orders: string}> sorted by product
     */
    public static function groupByProductWithOrders(array $orders, string $startDate, string $endDate): array
    {
        $groups = [];
        foreach (self::fulfillmentsInRange($orders, $startDate, $endDate) as ['orderLabel' => $orderLabel, 'items' => $items]) {
            foreach ($items as $item) {
                $label = self::itemLabel($item);
                if (!isset($groups[$label])) {
                    $groups[$label] = ['product' => $label, 'quantity' => 0, 'orders' => []];
                }
                $groups[$label]['quantity'] += (int)($item['quantity'] ?? 0);
                $groups[$label]['orders'][$orderLabel] = true;
            }
        }

        ksort($groups);
        $rows = [];
        foreach ($groups as $group) {
            $orders = array_keys($group['orders']);
            sort($orders);
            $rows[] = [
                'product'  => $group['product'],
                'quantity' => $group['quantity'],
                'orders'   => implode(', ', $orders),
            ];
        }
        return $rows;
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
     * @param array<int, array{order: string, product: string, quantity: int}> $rows
     */
    public static function toDetailedCsvString(array $rows): string
    {
        $writer = Writer::fromString();
        $writer->addFormatter((new EscapeFormula())->escapeRecord(...));
        $writer->insertOne(['order', 'product', 'quantity']);
        foreach ($rows as $row) {
            $writer->insertOne([$row['order'], $row['product'], $row['quantity']]);
        }
        return $writer->toString();
    }

    /**
     * @param array<int, array{product: string, quantity: int, orders: string}> $rows
     */
    public static function toGroupedCsvString(array $rows): string
    {
        $writer = Writer::fromString();
        $writer->addFormatter((new EscapeFormula())->escapeRecord(...));
        $writer->insertOne(['product', 'quantity', 'orders']);
        foreach ($rows as $row) {
            $writer->insertOne([$row['product'], $row['quantity'], $row['orders']]);
        }
        return $writer->toString();
    }

    /**
     * @param array<int, array{order: string, product: string, quantity: int}> $rows
     */
    public static function detailedEmailHtml(array $rows, string $startDate, string $endDate): string
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td style="padding:4px 12px 4px 0">' . self::h($row['order']) . '</td>'
                . '<td style="padding:4px 12px 4px 0">' . self::h($row['product']) . '</td>'
                . '<td style="padding:4px 0;text-align:right">' . $row['quantity'] . '</td></tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:sans-serif;color:#111;max-width:600px;margin:0 auto;padding:20px">'
            . '<h2 style="margin-bottom:4px">Fulfilled Items Report</h2>'
            . '<p style="margin:0 0 16px;color:#555">' . self::h($startDate) . ' &rarr; ' . self::h($endDate) . ' &middot; fulfillment status: fulfilled</p>'
            . '<table style="border-collapse:collapse;width:100%">'
            . '<tr><th style="text-align:left;padding:4px 12px 4px 0">Order</th><th style="text-align:left;padding:4px 12px 4px 0">Product</th><th style="text-align:right;padding:4px 0">Qty</th></tr>'
            . $body . '</table>'
            . '<p style="margin-top:20px;font-size:12px;color:#888">Full itemized CSV attached. Sent by Shopify Ops</p>'
            . '</body></html>';
    }

    /**
     * @param array<int, array{product: string, quantity: int, orders: string}> $rows
     */
    public static function groupedEmailHtml(array $rows, string $startDate, string $endDate): string
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td style="padding:4px 12px 4px 0">' . self::h($row['product']) . '</td>'
                . '<td style="padding:4px 12px 4px 0;text-align:right">' . $row['quantity'] . '</td>'
                . '<td style="padding:4px 0">' . self::h($row['orders']) . '</td></tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:sans-serif;color:#111;max-width:700px;margin:0 auto;padding:20px">'
            . '<h2 style="margin-bottom:4px">Fulfilled Items Report</h2>'
            . '<p style="margin:0 0 16px;color:#555">' . self::h($startDate) . ' &rarr; ' . self::h($endDate) . ' &middot; grouped by product</p>'
            . '<table style="border-collapse:collapse;width:100%">'
            . '<tr><th style="text-align:left;padding:4px 12px 4px 0">Product</th><th style="text-align:right;padding:4px 12px 4px 0">Qty</th><th style="text-align:left;padding:4px 0">Orders</th></tr>'
            . $body . '</table>'
            . '<p style="margin-top:20px;font-size:12px;color:#888">Full itemized CSV attached. Sent by Shopify Ops</p>'
            . '</body></html>';
    }

    /**
     * @param array<string, int> $totals
     */
    private static function writeCsv(Writer $writer, array $totals): Writer
    {
        $writer->addFormatter((new EscapeFormula())->escapeRecord(...));
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

    /**
     * Flattens each order's fulfillments down to the ones shipped within [startDate, endDate],
     * paired with their own line items and the parent order's label.
     *
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array{orderLabel: string, items: array<int, array<string, mixed>>}>
     */
    private static function fulfillmentsInRange(array $orders, string $startDate, string $endDate): array
    {
        $rangeStart = "{$startDate}T00:00:00Z";
        $rangeEnd   = "{$endDate}T23:59:59Z";

        $result = [];
        foreach ($orders as $order) {
            $orderLabel = (string)($order['name'] ?? $order['order_number'] ?? '');
            foreach ($order['fulfillments'] ?? [] as $fulfillment) {
                if (($fulfillment['status'] ?? '') !== 'success') {
                    continue;
                }
                $createdAt = $fulfillment['created_at'] ?? '';
                if ($createdAt < $rangeStart || $createdAt > $rangeEnd) {
                    continue;
                }
                $result[] = ['orderLabel' => $orderLabel, 'items' => $fulfillment['line_items'] ?? []];
            }
        }
        return $result;
    }

    private static function itemLabel(array $item): string
    {
        $variant = $item['variant_title'] ?? null;
        return trim($item['title'] . (($variant && $variant !== 'Default Title') ? " {$variant}" : ''));
    }
}
