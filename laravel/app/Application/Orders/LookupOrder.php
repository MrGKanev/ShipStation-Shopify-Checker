<?php

namespace App\Application\Orders;

use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class LookupOrder
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationClients,
    ) {}

    public function handle(Store $store, string $orderNumber): OrderLookupResult
    {
        $shopifyOrders = $this->shopify->findByOrderNumber($store, $orderNumber);
        $shipStation = $this->shipStationClients->forStore($store);

        return new OrderLookupResult(
            orderNumber: $orderNumber,
            shopifyOrders: $shopifyOrders,
            shipStationOrders: $shipStation?->findByOrderNumber($orderNumber) ?? [],
            shipStationShipments: $shipStation?->getOrderShipments($orderNumber) ?? [],
            shipStationConfigured: $shipStation !== null,
        );
    }
}
