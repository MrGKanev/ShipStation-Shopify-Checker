<?php

namespace App\Application\Reports;

use App\Domain\Reports\InventoryOversellAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use LogicException;

class RunInventoryOversellReport
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationFactory,
        private readonly InventoryOversellAnalyzer $analyzer,
    ) {}

    public function handle(Store $store): InventoryOversellResult
    {
        $catalogue = $this->shopify->inventoryOversellCandidates($store);
        $shipStation = $this->shipStationFactory->forStore($store);

        if ($shipStation === null) {
            throw new LogicException('ShipStation credentials are required for the inventory oversell report.');
        }

        $orders = $shipStation->fetchAwaitingOrders();

        return new InventoryOversellResult(count($catalogue['products']), count($orders), $this->analyzer->analyze($catalogue['products'], $orders), $catalogue['pages'], $catalogue['truncated']);
    }
}
