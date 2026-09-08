<?php

namespace App\Application\Reports;

readonly class ReturnRmaResult
{
    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $skuStats */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public int $scanned,
        public array $rows,
        public array $skuStats,
        public int $pages,
        public bool $truncated,
    ) {}
}
