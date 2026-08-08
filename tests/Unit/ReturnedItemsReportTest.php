<?php
declare(strict_types=1);

use League\Csv\Reader;
use PHPUnit\Framework\TestCase;

class ReturnedItemsReportTest extends TestCase
{
    private const string START = '2026-07-01';
    private const string END   = '2026-07-31';

    private function order(array $refunds): array
    {
        return ['refunds' => $refunds];
    }

    private function refund(array $lineItems, string $createdAt = '2026-07-15T10:00:00Z'): array
    {
        return ['created_at' => $createdAt, 'refund_line_items' => $lineItems];
    }

    private function lineItem(string $name, int $qty): array
    {
        return ['quantity' => $qty, 'line_item' => ['name' => $name, 'title' => $name]];
    }

    public function testSumsQuantitiesAcrossRefundLineItems(): void
    {
        $orders = [
            $this->order([$this->refund([$this->lineItem('Widget - blue', 2)])]),
            $this->order([$this->refund([$this->lineItem('Widget - blue', 1)])]),
        ];

        $totals = ReturnedItemsReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Widget - blue' => 3], $totals);
    }

    public function testSumsAcrossMultipleRefundsOnSameOrder(): void
    {
        $orders = [
            $this->order([
                $this->refund([$this->lineItem('Gadget', 1)]),
                $this->refund([$this->lineItem('Gadget', 4)]),
            ]),
        ];

        $totals = ReturnedItemsReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Gadget' => 5], $totals);
    }

    public function testFallsBackToTitleWhenNameMissing(): void
    {
        $orders = [$this->order([$this->refund([
            ['quantity' => 3, 'line_item' => ['title' => 'Spare Part']],
        ])])];

        $totals = ReturnedItemsReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Spare Part' => 3], $totals);
    }

    public function testMultipleProductsSortedByLabel(): void
    {
        $orders = [$this->order([$this->refund([
            $this->lineItem('Zeta', 5),
            $this->lineItem('Alpha', 2),
        ])])];

        $totals = ReturnedItemsReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Alpha' => 2, 'Zeta' => 5], $totals);
    }

    public function testIncludesOrderCreatedBeforeWindowButRefundedInsideIt(): void
    {
        $order = $this->order([$this->refund([$this->lineItem('Widget - blue', 5)])]);
        $order['created_at'] = '2026-03-02T10:00:00Z';

        $totals = ReturnedItemsReport::aggregate([$order], self::START, self::END);

        $this->assertSame(['Widget - blue' => 5], $totals);
    }

    public function testExcludesRefundsIssuedOutsideTheWindow(): void
    {
        $orders = [
            $this->order([$this->refund([$this->lineItem('Widget - blue', 5)], '2026-07-15T10:00:00Z')]),
            $this->order([$this->refund([$this->lineItem('Widget - blue', 999)], '2026-06-20T10:00:00Z')]),
        ];

        $totals = ReturnedItemsReport::aggregate($orders, self::START, self::END);

        $this->assertSame(['Widget - blue' => 5], $totals);
    }

    public function testSaveCsvWritesProductAndQuantityColumns(): void
    {
        $tmpDir = sys_get_temp_dir() . '/returned_items_' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $path = ReturnedItemsReport::saveCsv(['Widget - blue' => 3], '2026-07-01', '2026-07-31', $tmpDir);

            $csv = Reader::from($path, 'r');
            $csv->setHeaderOffset(0);
            $rows = [...$csv->getRecords()];

            $this->assertSame(['product' => 'Widget - blue', 'quantity' => '3'], $rows[0]);
        } finally {
            array_map('unlink', glob($tmpDir . '/*') ?: []);
            rmdir($tmpDir);
        }
    }

    public function testToCsvStringMatchesSaveCsvContent(): void
    {
        $csv = ReturnedItemsReport::toCsvString(['Widget - blue' => 3, 'Gadget' => 5]);

        $reader = Reader::fromString($csv);
        $reader->setHeaderOffset(0);
        $rows = [...$reader->getRecords()];

        $this->assertSame(['product' => 'Widget - blue', 'quantity' => '3'], $rows[0]);
        $this->assertSame(['product' => 'Gadget', 'quantity' => '5'], $rows[1]);
    }

    public function testEmailHtmlEscapesProductLabelsAndIncludesDateRange(): void
    {
        $html = ReturnedItemsReport::emailHtml(['<script>x</script>' => 3], '2026-07-01', '2026-07-31');

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('2026-07-01', $html);
        $this->assertStringContainsString('2026-07-31', $html);
    }
}
