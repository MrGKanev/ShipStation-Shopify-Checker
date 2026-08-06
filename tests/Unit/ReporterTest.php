<?php
declare(strict_types=1);

use League\Csv\Reader;
use PHPUnit\Framework\TestCase;

class ReporterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/reporter_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function save(array $missing = [], string $start = '2024-01-01', string $end = '2024-01-31'): void
    {
        Reporter::saveReports($missing, $start, $end, $this->tmpDir);
    }

    private function csvPath(): string
    {
        return $this->tmpDir . '/missing_' . date('Y-m-d') . '.csv';
    }

    private function txtPath(): string
    {
        return $this->tmpDir . '/missing_' . date('Y-m-d') . '.txt';
    }

    private function readCsv(): array
    {
        $csv = Reader::from($this->csvPath(), 'r');
        $csv->setHeaderOffset(0);
        return [...$csv->getRecords()];
    }

    // ── CSV ───────────────────────────────────────────────────────────────────

    public function testCreatesCsvFile(): void
    {
        $this->save();
        $this->assertFileExists($this->csvPath());
    }

    public function testCsvHasCorrectHeaders(): void
    {
        $this->save();
        $csv = Reader::from($this->csvPath(), 'r');
        $csv->setHeaderOffset(0);
        $expected = ['order_number', 'shopify_name', 'shopify_id', 'created_at', 'total_price',
                     'financial_status', 'fulfillment_status', 'email', 'order_type'];
        $this->assertSame($expected, $csv->getHeader());
    }

    public function testCsvEmptyMissingWritesHeaderOnly(): void
    {
        $this->save([]);
        $this->assertCount(0, $this->readCsv());
    }

    public function testCsvWritesOrderRow(): void
    {
        $order = [
            'order_number'       => 65001,
            'name'               => '#165001',
            'id'                 => 99,
            'created_at'         => '2024-01-15T10:00:00Z',
            'total_price'        => '99.00',
            'financial_status'   => 'paid',
            'fulfillment_status' => null,
            'email'              => 'test@example.com',
            '_order_type'        => 'Widget',
        ];

        $this->save([$order]);
        $rows = $this->readCsv();

        $this->assertCount(1, $rows);
        $this->assertSame('65001',            $rows[0]['order_number']);
        $this->assertSame('#165001',          $rows[0]['shopify_name']);
        $this->assertSame('99',               $rows[0]['shopify_id']);
        $this->assertSame('99.00',            $rows[0]['total_price']);
        $this->assertSame('paid',             $rows[0]['financial_status']);
        $this->assertSame('test@example.com', $rows[0]['email']);
        $this->assertSame('Widget',               $rows[0]['order_type']);
    }

    public function testCsvHandlesMissingFields(): void
    {
        $this->save([['order_number' => 12345]]);
        $rows = $this->readCsv();

        $this->assertCount(1, $rows);
        $this->assertSame('12345', $rows[0]['order_number']);
        $this->assertSame('',      $rows[0]['email']);
        $this->assertSame('',      $rows[0]['order_type']);
    }

    public function testCsvWritesMultipleRows(): void
    {
        $orders = [
            ['order_number' => 1001, 'email' => 'a@b.com'],
            ['order_number' => 1002, 'email' => 'c@d.com'],
            ['order_number' => 1003, 'email' => 'e@f.com'],
        ];

        $this->save($orders);
        $this->assertCount(3, $this->readCsv());
    }

    // ── TXT ───────────────────────────────────────────────────────────────────

    public function testCreatesTxtFile(): void
    {
        $this->save();
        $this->assertFileExists($this->txtPath());
    }

    public function testTxtContainsPeriod(): void
    {
        $this->save([], '2024-01-01', '2024-01-31');
        $content = file_get_contents($this->txtPath());
        $this->assertStringContainsString('2024-01-01 -> 2024-01-31', $content);
    }

    public function testTxtContainsCount(): void
    {
        $orders = [['order_number' => 1001], ['order_number' => 1002]];
        $this->save($orders);
        $this->assertStringContainsString('Count: 2', file_get_contents($this->txtPath()));
    }

    public function testTxtContainsOrderLine(): void
    {
        $order = [
            'order_number'     => 65001,
            'name'             => '#165001',
            'created_at'       => '2024-01-15T10:00:00Z',
            'total_price'      => '99.00',
            'financial_status' => 'paid',
            'email'            => 'test@example.com',
        ];

        $this->save([$order]);
        $content = file_get_contents($this->txtPath());

        $this->assertStringContainsString('65001', $content);
        $this->assertStringContainsString('2024-01-15', $content);
        $this->assertStringContainsString('$99.00', $content);
        $this->assertStringContainsString('test@example.com', $content);
    }
}
