<?php

namespace App\Application\Orders;

use App\Domain\Orders\OrderRiskScorer;
use App\Domain\Orders\OrderTimelineBuilder;
use App\Domain\Orders\OrderTimelineRiskAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class LoadOrderTimeline
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationClients,
        private readonly OrderTimelineBuilder $timelineBuilder,
        private readonly OrderTimelineRiskAnalyzer $riskAnalyzer,
        private readonly OrderRiskScorer $riskScorer,
    ) {}

    public function handle(Store $store, string $orderNumber): OrderTimelineResult
    {
        $shopifyOrders = $this->shopify->findByOrderNumber($store, $orderNumber);
        $shopifyMatchCount = count($shopifyOrders);
        $shipStationConfigured = trim((string) $store->shipstation_api_key) !== ''
            && trim((string) $store->shipstation_api_secret) !== '';

        if ($shopifyMatchCount !== 1) {
            return new OrderTimelineResult(
                orderNumber: $orderNumber,
                state: $shopifyMatchCount === 0 ? 'not_found' : 'ambiguous',
                shopifyMatchCount: $shopifyMatchCount,
                order: null,
                shipStationOrders: [],
                shipStationShipments: [],
                shipStationConfigured: $shipStationConfigured,
                timeline: [],
                operationalRisks: [],
                timeToShip: null,
                riskScore: null,
            );
        }

        $order = $shopifyOrders[0];
        $orderId = trim((string) ($order['admin_graphql_api_id'] ?? $order['id'] ?? ''));
        $events = $this->shopify->getOrderEvents($store, $orderId);
        $shipStation = $this->shipStationClients->forStore($store);
        $shipStationOrders = $shipStation?->findByOrderNumber($orderNumber) ?? [];
        $shipStationShipments = $shipStation?->getOrderShipments($orderNumber) ?? [];

        return new OrderTimelineResult(
            orderNumber: $orderNumber,
            state: 'ready',
            shopifyMatchCount: 1,
            order: $order,
            shipStationOrders: $shipStationOrders,
            shipStationShipments: $shipStationShipments,
            shipStationConfigured: $shipStation !== null,
            timeline: $this->timelineBuilder->build($order, $events, $shipStationOrders, $shipStationShipments),
            operationalRisks: $this->riskAnalyzer->analyze($order, $shipStationOrders),
            timeToShip: $this->riskAnalyzer->timeToShip($order),
            riskScore: $this->riskScorer->score($order),
        );
    }
}
