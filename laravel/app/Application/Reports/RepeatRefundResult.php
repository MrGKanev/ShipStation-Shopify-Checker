<?php

namespace App\Application\Reports;

readonly class RepeatRefundResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public int $minimum, public array $rows, public int $pages, public bool $truncated) {}
}
