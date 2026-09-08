<?php

namespace App\Application\Reports;

readonly class OrderEditResult
{
    /**
     * Create a new class instance.
     */
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public array $rows, public int $pages, public bool $truncated) {}
}
