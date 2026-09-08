<?php

namespace App\Application\Exports;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExporter
{
    /** @param list<string> $headers @param iterable<array-key, list<bool|float|int|string|null>> $rows */
    public function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $safeFilename = trim((string) preg_replace('/[^a-z0-9._-]+/i', '-', basename($filename)), '.-') ?: 'report.csv';
        if (! str_ends_with(mb_strtolower($safeFilename), '.csv')) {
            $safeFilename .= '.csv';
        }

        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }
            $writer = Writer::from($stream);
            $writer->setEscape('');
            $writer->setEndOfLine("\r\n");
            $writer->addFormatter((new EscapeFormula)->escapeRecord(...));
            $writer->insertOne($headers);
            $writer->insertAll($rows);
        }, $safeFilename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
