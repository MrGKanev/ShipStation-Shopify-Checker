<?php

namespace App\Application\Reports;

readonly class NoteFlagResult
{
    /** @param list<array<string, mixed>> $rows @param list<string> $keywords */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public array $rows, public array $keywords, public int $pages, public bool $truncated) {}
}
