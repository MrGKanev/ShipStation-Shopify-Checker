<?php

namespace App\Application\Reports;

use App\Domain\Reports\RefundTrackerAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunRefundTrackerReport
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationFactory,
        private readonly RefundTrackerAnalyzer $analyzer,
    ) {}

    public function handle(Store $store, string $startDate, string $endDate): RefundTrackerResult
    {
        $candidates = $this->shopify->refundTrackerCandidates($store, $startDate, $endDate);
        $shipStation = $this->shipStationFactory->forStore($store);
        $shipStationOrders = $shipStation?->fetchAllOrders($startDate, now()->parse($endDate)->addDays(7)->toDateString()) ?? [];
        $rows = $this->analyzer->analyze($candidates['orders'], $shipStationOrders);

        return new RefundTrackerResult(
            $startDate,
            $endDate,
            count($candidates['orders']),
            $rows,
            count(array_filter($rows, fn (array $row): bool => $row['risk'] === 'active')),
            count(array_filter($rows, fn (array $row): bool => $row['risk'] === 'missing')),
            $shipStation !== null,
            $candidates['pages'],
            $candidates['truncated'],
        );
    }
}
