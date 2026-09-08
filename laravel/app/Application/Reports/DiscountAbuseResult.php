<?php

namespace App\Application\Reports;

readonly class DiscountAbuseResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public int $minimumEmails, public array $rows, public int $pages, public bool $truncated) {}
}
