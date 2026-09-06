<?php

namespace App\Application\Orders;

readonly class OrderLookupResult
{
    /**
     * @param  list<array<string, mixed>>  $shopifyOrders
     * @param  list<array<string, mixed>>  $shipStationOrders
     * @param  list<array<string, mixed>>  $shipStationShipments
     * @param  array<string, mixed>|null  $comparison
     */
    public function __construct(
        public string $orderNumber,
        public array $shopifyOrders,
        public array $shipStationOrders,
        public array $shipStationShipments,
        public bool $shipStationConfigured,
        public string $comparisonState,
        public ?array $comparison,
    ) {}
}
