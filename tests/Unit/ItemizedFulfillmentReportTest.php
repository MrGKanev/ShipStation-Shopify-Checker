<?php
declare(strict_types=1);

use League\Csv\Reader;
use PHPUnit\Framework\TestCase;

class ItemizedFulfillmentReportTest extends TestCase
{
    private function order(?string $fulfillmentStatus, array $lineItems, string $name = ''): array
    {
        return ['fulfillment_status' => $fulfillmentStatus, 'line_items' => $lineItems, 'name' => $name];
    }

    private function item(string $title, ?string $variant, int $qty): array
    {
        return ['title' => $title, 'variant_title' => $variant, 'quantity' => $qty];
    }

    public function testSumsQuantitiesForFulfilledOrdersOnly(): void
    {
        $orders = [
            $this->order('fulfilled', [$this->item('Widget', 'blue', 10)]),
            $this->order('fulfilled', [$this->item('Widget', 'blue', 30)]),
            $this->order('partial', [$this->item('Widget', 'blue', 999)]),
            $this->order(null, [$this->item('Widget', 'blue', 999)]),
        ];

        $totals = ItemizedFulfillmentReport::aggregate($orders);

        $this->assertSame(['Widget blue' => 40], $totals);
    }

    public function testDropsDefaultTitleVariantSuffix(): void
    {
        $orders = [$this->order('fulfilled', [$this->item('Carrying Case', 'Default Title', 5)])];

        $totals = ItemizedFulfillmentReport::aggregate($orders);

        $this->assertSame(['Carrying Case' => 5], $totals);
    }

    public function testMultipleProductsSortedByLabel(): void
    {
        $orders = [$this->order('fulfilled', [
            $this->item('Gadget', 'red', 50),
            $this->item('Spare Part', null, 50),
        ])];

        $totals = ItemizedFulfillmentReport::aggregate($orders);

        $this->assertSame(['Gadget red' => 50, 'Spare Part' => 50], $totals);
    }

    public function testItemizeByOrderShowsEveryProductRowFromFulfilledOrder(): void
    {
        $orders = [
            $this->order('fulfilled', [
                $this->item('Gadget', 'red', 2),
                $this->item('Spare Part', null, 1),
                $this->item('Gadget', 'red', 3),
            ], '#1002'),
            $this->order('partial', [
                $this->item('Hidden', null, 99),
            ], '#1001'),
        ];

        $rows = ItemizedFulfillmentReport::itemizeByOrder($orders);

        $this->assertSame([
            ['order' => '#1002', 'product' => 'Gadget red', 'quantity' => 5],
            ['order' => '#1002', 'product' => 'Spare Part', 'quantity' => 1],
        ], $rows);
    }

    public function testGroupByProductWithOrdersTotalsQuantityAndListsOrders(): void
    {
        $orders = [
            $this->order('fulfilled', [
                $this->item('Widget', 'blue', 1),
                $this->item('Case', null, 2),
            ], '#1001'),
            $this->order('fulfilled', [
                $this->item('Widget', 'blue', 1),
            ], '#1002'),
            $this->order('fulfilled', [
                $this->item('Widget', 'blue', 1),
            ], '#1003'),
            $this->order('partial', [
                $this->item('Widget', 'blue', 99),
            ], '#1004'),
        ];

        $rows = ItemizedFulfillmentReport::groupByProductWithOrders($orders);

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

    public function testEmailHtmlEscapesProductLabelsAndIncludesDateRange(): void
    {
        $html = ItemizedFulfillmentReport::emailHtml(['<script>x</script>' => 3], '2026-07-01', '2026-07-31');

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('2026-07-01', $html);
        $this->assertStringContainsString('2026-07-31', $html);
    }
}
