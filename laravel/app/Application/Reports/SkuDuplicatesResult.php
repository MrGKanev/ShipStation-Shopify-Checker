<?php

namespace App\Application\Reports;

readonly class SkuDuplicatesResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public int $scanned, public array $rows, public int $totalVariants, public int $pages, public bool $truncated) {}
}
