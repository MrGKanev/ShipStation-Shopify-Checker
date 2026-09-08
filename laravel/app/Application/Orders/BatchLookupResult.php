<?php

namespace App\Application\Orders;

readonly class BatchLookupResult
{
    /**
     * @param  list<array{
     *     number: string,
     *     shopify_orders: list<array<string, mixed>>|null,
     *     shipstation_orders: list<array<string, mixed>>|null,
     *     shopify_found: bool,
     *     shipstation_found: bool,
     *     status: string,
     *     risk_scores: array<string, array{score: int, level: string, signals: list<string>}>
     * }>  $results
     */
    public function __construct(
        public string $mode,
        public array $results,
        public int $shopifyFoundCount,
        public int $shipStationFoundCount,
    ) {}
}
