<?php

namespace App\Application\Reports;

readonly class RefundTrackerResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public int $scanned,
        public array $rows,
        public int $active,
        public int $missing,
        public bool $hasShipStation,
        public int $pages,
        public bool $truncated,
    ) {}
}
