<?php

namespace App\Application\Orders;

readonly class OrderTimelineResult
{
    /**
     * @param  array<string, mixed>|null  $order
     * @param  list<array<string, mixed>>  $shipStationOrders
     * @param  list<array<string, mixed>>  $shipStationShipments
     * @param  list<array{timestamp: string, formatted_at: string, type: string, source: string, title: string, detail: string, tracking: string, url: string}>  $timeline
     * @param  list<array{level: string, message: string}>  $operationalRisks
     * @param  array{score: int, level: string, signals: list<string>}|null  $riskScore
     */
    public function __construct(
        public string $orderNumber,
        public string $state,
        public int $shopifyMatchCount,
        public ?array $order,
        public array $shipStationOrders,
        public array $shipStationShipments,
        public bool $shipStationConfigured,
        public array $timeline,
        public array $operationalRisks,
        public ?int $timeToShip,
        public ?array $riskScore,
    ) {}
}
