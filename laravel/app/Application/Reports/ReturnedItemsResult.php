<?php

namespace App\Application\Reports;

readonly class ReturnedItemsResult
{
    /** @param list<array{product: string, quantity: int}> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public array $rows, public int $pages, public bool $truncated) {}
}
