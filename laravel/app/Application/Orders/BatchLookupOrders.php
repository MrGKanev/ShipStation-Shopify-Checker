<?php

namespace App\Application\Orders;

use App\Domain\Orders\OrderRiskScorer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use InvalidArgumentException;
use LogicException;

class BatchLookupOrders
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationClients,
        private readonly OrderRiskScorer $riskScorer,
    ) {}

    /** @param list<string> $orderNumbers */
    public function handle(Store $store, array $orderNumbers, string $mode): BatchLookupResult
    {
        if (! in_array($mode, ['both', 'shipstation', 'shopify'], true)) {
            throw new InvalidArgumentException('The batch lookup mode is invalid.');
        }

        $checkShopify = in_array($mode, ['both', 'shopify'], true);
        $checkShipStation = in_array($mode, ['both', 'shipstation'], true);
        $shipStation = $checkShipStation ? $this->shipStationClients->forStore($store) : null;

        if ($checkShipStation && $shipStation === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }

        $shopifyOrdersByNumber = $checkShopify
            ? $this->shopify->findByOrderNumbers($store, $orderNumbers)
            : [];
        $results = [];
        $shopifyFoundCount = 0;
        $shipStationFoundCount = 0;

        foreach ($orderNumbers as $orderNumber) {
            $shopifyOrders = $checkShopify
                ? $this->withShopifyLinks($store, $shopifyOrdersByNumber[$orderNumber] ?? [])
                : null;
            $shipStationOrders = $checkShipStation
                ? $this->withShipStationLinks($shipStation->findByOrderNumber($orderNumber))
                : null;
            $shopifyFound = $shopifyOrders !== null && $shopifyOrders !== [];
            $shipStationFound = $shipStationOrders !== null && $shipStationOrders !== [];

            $shopifyFoundCount += $shopifyFound ? 1 : 0;
            $shipStationFoundCount += $shipStationFound ? 1 : 0;
            $results[] = [
                'number' => $orderNumber,
                'shopify_orders' => $shopifyOrders,
                'shipstation_orders' => $shipStationOrders,
                'shopify_found' => $shopifyFound,
                'shipstation_found' => $shipStationFound,
                'status' => $this->status($mode, $shopifyFound, $shipStationFound),
                'risk_scores' => $this->riskScores($shopifyOrders ?? []),
            ];
        }

        return new BatchLookupResult(
            mode: $mode,
            results: $results,
            shopifyFoundCount: $shopifyFoundCount,
            shipStationFoundCount: $shipStationFoundCount,
        );
    }

    private function status(string $mode, bool $shopifyFound, bool $shipStationFound): string
    {
        return match ($mode) {
            'shopify' => $shopifyFound ? 'Found' : 'Not found',
            'shipstation' => $shipStationFound ? 'Found' : 'Not found',
            default => match (true) {
                $shopifyFound && $shipStationFound => 'Both found',
                $shopifyFound => 'Shopify only',
                $shipStationFound => 'ShipStation only',
                default => 'Not found',
            },
        };
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array<string, array{score: int, level: string, signals: list<string>}>
     */
    private function riskScores(array $orders): array
    {
        $scores = [];

        foreach ($orders as $order) {
            $id = trim((string) ($order['id'] ?? ''));

            if ($id !== '') {
                $scores[$id] = $this->riskScorer->score($order);
            }
        }

        return $scores;
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    private function withShopifyLinks(Store $store, array $orders): array
    {
        return array_map(function (array $order) use ($store): array {
            $id = trim((string) ($order['id'] ?? ''));
            $order['url'] = ctype_digit($id)
                ? 'https://'.rawurlencode((string) $store->shopify_store).'.myshopify.com/admin/orders/'.$id
                : '';

            return $order;
        }, $orders);
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    private function withShipStationLinks(array $orders): array
    {
        return array_map(function (array $order): array {
            $id = trim((string) ($order['orderId'] ?? ''));
            $order['url'] = ctype_digit($id)
                ? 'https://app.shipstation.com/#!/orders/order-details/'.$id
                : '';

            return $order;
        }, $orders);
    }
}
