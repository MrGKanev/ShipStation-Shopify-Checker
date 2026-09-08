<?php

namespace Tests\Unit;

use App\Application\Exports\CsvExporter;
use Tests\TestCase;

class CsvExporterTest extends TestCase
{
    public function test_streams_rfc4180_csv_with_safe_filename_and_formula_escaping(): void
    {
        $response = (new CsvExporter)->download('../Risk Report', ['Order', 'Value'], [['#1', '=2+5'], ['#2', "+cmd\r\nnext"], ['#3', 'safe, value']]);
        ob_start();
        ($response->getCallback())();
        $content = (string) ob_get_clean();

        $this->assertSame('attachment; filename=Risk-Report.csv', $response->headers->get('content-disposition'));
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('content-type'));
        $this->assertStringContainsString("Order,Value\r\n", $content);
        $this->assertStringContainsString("#1,'=2+5\r\n", $content);
        $this->assertStringContainsString("'+cmd", $content);
        $this->assertStringContainsString('"safe, value"', $content);
    }
}
