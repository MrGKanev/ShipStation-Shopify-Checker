<?php

namespace App\Application\Orders;

readonly class OrderTagSearchResult
{
    /** @param list<array<string, mixed>> $orders */
    public function __construct(
        public string $tag,
        public ?string $startDate,
        public ?string $endDate,
        public array $orders,
        public int $pages,
        public bool $truncated,
    ) {}
}
