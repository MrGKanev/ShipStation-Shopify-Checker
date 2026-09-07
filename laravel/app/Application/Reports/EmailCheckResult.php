<?php

namespace App\Application\Reports;

readonly class EmailCheckResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public array $rows, public int $critical, public int $warnings, public int $pages, public bool $truncated) {}
}
