<?php

namespace App\Application\Reports;

readonly class CatalogQualityResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public int $scanned, public array $rows, public int $pages, public bool $truncated) {}
}
