<?php
declare(strict_types=1);

use League\Csv\Reader;
use PHPUnit\Framework\TestCase;

class ItemizedFulfillmentReportTest extends TestCase
{
    private const string START = '2026-07-01';
    private const string END   = '2026-07-31';

    /**
     * @param array<int, array{created_at: string, status?: string, items: array<int, array{title: string, variant_title: ?string, quantity: int}>}> $fulfillments
     */
    private function order(array $fulfillments, string $name = ''): array
    {
        return [
            'name'         => $name,
            'fulfillments' => array_map(
                fn($f) => [
                    'created_at' => $f['created_at'],
                    'status'     => $f['status'] ?? 'success',
                    'line_items' => $f['items'],
                ],
                $fulfillments
            ),
        ];
    }

    private function item(string $title, ?string $variant, int $qty): array
    {
        return ['title' => $title, 'variant_title' => $variant, 'quantity' => $qty];
    }

    private function shippedInWindow(array $items): array
    {
        return ['created_at' => '2026-07-15T10:00:00Z', 'items' => $items];
    }

    private function shippedOutsideWindow(array $items): array
    {
        return ['created_at' => '2026-06-20T10:00:00Z', 'items' => $items];
    }

    private function cancelledInWindow(array $items): array
    {
        return ['created_at' => '2026-07-15T10:00:00Z', 'status' => 'cancelled', 'items' => $items];
    }

    public function testSumsQuantitiesForFulfillmentsShippedInWindowOnly(): void
    {
        $orders = [
            $this->order([$this->shippedInWindow([$this->item('Widget', 'blue', 10)])]),
            $this->order([$this->shippedInWindow([$this->item('Widget', 'blue', 30)])]),
            $this->order([$this->shippedOutsideWindow([$this->item('Widget', 'blue', 999)])]),
        ];

        $totals = ItemizedFulfillmentReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Widget blue' => 40], $totals);
    }

    public function testIncludesOrderCreatedBeforeWindowButShippedInsideIt(): void
    {
        $order = $this->order([$this->shippedInWindow([$this->item('Widget', 'blue', 5)])]);
        $order['created_at'] = '2026-03-02T10:00:00Z';

        $totals = ItemizedFulfillmentReport::aggregate([$order], self::START, self::END);

        $this->assertSame(['Widget blue' => 5], $totals);
    }

    public function testCountsOnlyShippedLineItemsFromAPartiallyFulfilledOrder(): void
    {
        $orders = [
            $this->order([$this->shippedInWindow([$this->item('Widget', 'blue', 3)])]),
        ];

        $totals = ItemizedFulfillmentReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Widget blue' => 3], $totals);
    }

    public function testExcludesCancelledFulfillmentsEvenIfCreatedInWindow(): void
    {
        $orders = [
            $this->order([$this->shippedInWindow([$this->item('Widget', 'blue', 5)])]),
            $this->order([$this->cancelledInWindow([$this->item('Widget', 'blue', 999)])]),
        ];

        $totals = ItemizedFulfillmentReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Widget blue' => 5], $totals);
    }

    public function testDropsDefaultTitleVariantSuffix(): void
    {
        $orders = [$this->order([$this->shippedInWindow([$this->item('Carrying Case', 'Default Title', 5)])])];

        $totals = ItemizedFulfillmentReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Carrying Case' => 5], $totals);
    }

    public function testMultipleProductsSortedByLabel(): void
    {
        $orders = [$this->order([$this->shippedInWindow([
            $this->item('Gadget', 'red', 50),
            $this->item('Spare Part', null, 50),
        ])])];

        $totals = ItemizedFulfillmentReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Gadget red' => 50, 'Spare Part' => 50], $totals);
    }

    public function testItemizeByOrderShowsEveryProductRowFromFulfillmentsInWindow(): void
    {
        $orders = [
            $this->order([$this->shippedInWindow([
                $this->item('Gadget', 'red', 2),
                $this->item('Spare Part', null, 1),
                $this->item('Gadget', 'red', 3),
            ])], '#1002'),
            $this->order([$this->shippedOutsideWindow([
                $this->item('Hidden', null, 99),
            ])], '#1001'),
        ];

        $rows = ItemizedFulfillmentReport::itemizeByOrder($orders, self::START, self::END);

        $this->assertSame([
            ['order' => '#1002', 'product' => 'Gadget red', 'quantity' => 5],
            ['order' => '#1002', 'product' => 'Spare Part', 'quantity' => 1],
        ], $rows);
    }

    public function testGroupByProductWithOrdersTotalsQuantityAndListsOrders(): void
    {
        $orders = [
            $this->order([$this->shippedInWindow([
                $this->item('Widget', 'blue', 1),
                $this->item('Case', null, 2),
            ])], '#1001'),
            $this->order([$this->shippedInWindow([
                $this->item('Widget', 'blue', 1),
            ])], '#1002'),
            $this->order([$this->shippedInWindow([
                $this->item('Widget', 'blue', 1),
            ])], '#1003'),
            $this->order([$this->shippedOutsideWindow([
                $this->item('Widget', 'blue', 99),
            ])], '#1004'),
        ];

        $rows = ItemizedFulfillmentReport::groupByProductWithOrders($orders, self::START, self::END);

        $this->assertSame([
            ['product' => 'Case', 'quantity' => 2, 'orders' => '#1001'],
            ['product' => 'Widget blue', 'quantity' => 3, 'orders' => '#1001, #1002, #1003'],
        ], $rows);
    }

    public function testToGroupedCsvStringWritesOrderList(): void
    {
        $csv = ItemizedFulfillmentReport::toGroupedCsvString([
            ['product' => 'Widget blue', 'quantity' => 3, 'orders' => '#1001, #1002, #1003'],
        ]);

        $reader = Reader::fromString($csv);
        $reader->setHeaderOffset(0);
        $rows = [...$reader->getRecords()];

        $this->assertSame([
            'product' => 'Widget blue',
            'quantity' => '3',
            'orders' => '#1001, #1002, #1003',
        ], $rows[0]);
    }

    public function testSaveCsvWritesProductAndQuantityColumns(): void
    {
        $tmpDir = sys_get_temp_dir() . '/itemized_' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $path = ItemizedFulfillmentReport::saveCsv(['Widget blue' => 40], '2026-07-01', '2026-07-31', $tmpDir);

            $csv = Reader::from($path, 'r');
            $csv->setHeaderOffset(0);
            $rows = [...$csv->getRecords()];

            $this->assertSame(['product' => 'Widget blue', 'quantity' => '40'], $rows[0]);
        } finally {
            array_map('unlink', glob($tmpDir . '/*') ?: []);
            rmdir($tmpDir);
        }
    }

    public function testToCsvStringMatchesSaveCsvContent(): void
    {
        $csv = ItemizedFulfillmentReport::toCsvString(['Widget blue' => 40, 'Gadget red' => 50]);

        $reader = Reader::fromString($csv);
        $reader->setHeaderOffset(0);
        $rows = [...$reader->getRecords()];

        $this->assertSame(['product' => 'Widget blue', 'quantity' => '40'], $rows[0]);
        $this->assertSame(['product' => 'Gadget red', 'quantity' => '50'], $rows[1]);
    }

    public function testToCsvStringEscapesFormulaLeadingProductLabels(): void
    {
        $csv = ItemizedFulfillmentReport::toCsvString(['=cmd|\'/c calc\'!A0' => 3]);

        $reader = Reader::fromString($csv);
        $reader->setHeaderOffset(0);
        $rows = [...$reader->getRecords()];

        $this->assertStringStartsNotWith('=', $rows[0]['product']);
    }

    public function testToDetailedCsvStringEscapesFormulaLeadingFields(): void
    {
        $csv = ItemizedFulfillmentReport::toDetailedCsvString([
            ['order' => '+1+1', 'product' => '@SUM(A1:A9)', 'quantity' => 3],
        ]);

        $reader = Reader::fromString($csv);
        $reader->setHeaderOffset(0);
        $rows = [...$reader->getRecords()];

        $this->assertStringStartsNotWith('+', $rows[0]['order']);
        $this->assertStringStartsNotWith('@', $rows[0]['product']);
    }

    public function testToGroupedCsvStringEscapesFormulaLeadingFields(): void
    {
        $csv = ItemizedFulfillmentReport::toGroupedCsvString([
            ['product' => '-2+3', 'quantity' => 3, 'orders' => '=HYPERLINK("http://evil.test")'],
        ]);

        $reader = Reader::fromString($csv);
        $reader->setHeaderOffset(0);
        $rows = [...$reader->getRecords()];

        $this->assertStringStartsNotWith('-', $rows[0]['product']);
        $this->assertStringStartsNotWith('=', $rows[0]['orders']);
    }

    public function testEmailHtmlEscapesProductLabelsAndIncludesDateRange(): void
    {
        $html = ItemizedFulfillmentReport::emailHtml(['<script>x</script>' => 3], '2026-07-01', '2026-07-31');

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('2026-07-01', $html);
        $this->assertStringContainsString('2026-07-31', $html);
    }

    // ── printSummary ─────────────────────────────────────────────────────────

    public function testPrintSummaryOutputsDateRangeAndTotals(): void
    {
        ob_start();
        ItemizedFulfillmentReport::printSummary(['Widget' => 3, 'Gadget' => 1], '2026-07-01', '2026-07-31');
        $output = ob_get_clean();

        $this->assertStringContainsString('Date: 2026-07-01 to 2026-07-31', $output);
        $this->assertStringContainsString('Widget: 3', $output);
        $this->assertStringContainsString('Gadget: 1', $output);
    }

    public function testPrintSummaryHandlesEmptyTotals(): void
    {
        ob_start();
        ItemizedFulfillmentReport::printSummary([], '2026-07-01', '2026-07-31');
        $output = ob_get_clean();

        $this->assertStringContainsString('Products:', $output);
    }

    // ── detailedEmailHtml / groupedEmailHtml ────────────────────────────────

    public function testDetailedEmailHtmlEscapesFieldsAndListsRows(): void
    {
        $rows = [['order' => '<b>#1001</b>', 'product' => 'Widget', 'quantity' => 2]];
        $html = ItemizedFulfillmentReport::detailedEmailHtml($rows, '2026-07-01', '2026-07-31');

        $this->assertStringNotContainsString('<b>#1001</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;#1001&lt;/b&gt;', $html);
        $this->assertStringContainsString('Widget', $html);
        $this->assertStringContainsString('>2<', $html);
        $this->assertStringContainsString('2026-07-01', $html);
        $this->assertStringContainsString('2026-07-31', $html);
    }

    public function testDetailedEmailHtmlHandlesEmptyRows(): void
    {
        $html = ItemizedFulfillmentReport::detailedEmailHtml([], '2026-07-01', '2026-07-31');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    public function testGroupedEmailHtmlEscapesFieldsAndListsRows(): void
    {
        $rows = [['product' => '<i>Widget</i>', 'quantity' => 5, 'orders' => '#1001, #1002']];
        $html = ItemizedFulfillmentReport::groupedEmailHtml($rows, '2026-07-01', '2026-07-31');

        $this->assertStringNotContainsString('<i>Widget</i>', $html);
        $this->assertStringContainsString('&lt;i&gt;Widget&lt;/i&gt;', $html);
        $this->assertStringContainsString('#1001, #1002', $html);
        $this->assertStringContainsString('>5<', $html);
    }

    public function testGroupedEmailHtmlHandlesEmptyRows(): void
    {
        $html = ItemizedFulfillmentReport::groupedEmailHtml([], '2026-07-01', '2026-07-31');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('</table>', $html);
    }
}
