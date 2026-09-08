<?php

namespace App\Application\Reports;

readonly class AddressChangeResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public array $rows, public int $pages, public bool $truncated) {}
}
