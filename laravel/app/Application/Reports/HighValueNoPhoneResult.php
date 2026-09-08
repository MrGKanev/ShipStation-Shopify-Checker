<?php

namespace App\Application\Reports;

readonly class HighValueNoPhoneResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public float $minimum, public string $currency, public int $scanned, public array $rows, public int $pages, public bool $truncated) {}
}
