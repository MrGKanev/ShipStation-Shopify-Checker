<?php

namespace App\Application\Reports;

readonly class TagAuditResult
{
    /** @param list<array{tag: string, count: int, last_order: string, last_date: string, orphan: bool}> $tags */
    public function __construct(public string $startDate, public string $endDate, public int $scanned, public array $tags, public int $pages, public bool $truncated) {}
}
