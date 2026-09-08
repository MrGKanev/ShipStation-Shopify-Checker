<?php

namespace App\Application\Orders;

readonly class OrderComparisonResult
{
    /**
     * @param  array<string, mixed>|null  $orderA
     * @param  array<string, mixed>|null  $orderB
     * @param  list<array{label: string, a: string, b: string, different: bool}>  $rows
     */
    public function __construct(
        public string $numberA,
        public string $numberB,
        public ?array $orderA,
        public ?array $orderB,
        public int $shopifyMatchCountA,
        public int $shopifyMatchCountB,
        public ?string $shipStationStatusA,
        public ?string $shipStationStatusB,
        public bool $shipStationConfigured,
        public array $rows,
        public int $differenceCount,
    ) {}
}
