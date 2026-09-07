<?php

namespace App\Application\Reports;

readonly class GiftCardsResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public int $scanned, public int $days, public array $rows, public int $pages, public bool $truncated) {}
}
