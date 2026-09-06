<?php

namespace App\Application\Orders;

use App\Domain\Orders\ShopifyOrderComparator;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class CompareOrders
{
    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationClients,
        private readonly ShopifyOrderComparator $comparator,
    ) {}

    public function handle(Store $store, string $numberA, string $numberB): OrderComparisonResult
    {
        $shopifyMatchesA = $this->shopify->findByOrderNumber($store, $numberA);
        $shopifyMatchesB = $this->shopify->findByOrderNumber($store, $numberB);
        $orderA = count($shopifyMatchesA) === 1 ? $shopifyMatchesA[0] : null;
        $orderB = count($shopifyMatchesB) === 1 ? $shopifyMatchesB[0] : null;
        $shipStation = $this->shipStationClients->forStore($store);
        $shipStationStatusA = $this->shipStationStatus($shipStation, $numberA);
        $shipStationStatusB = $this->shipStationStatus($shipStation, $numberB);
        $comparison = $this->comparator->compare($orderA, $orderB, $numberA, $numberB, $shipStation !== null, $shipStationStatusA, $shipStationStatusB);

        return new OrderComparisonResult(
            numberA: $numberA,
            numberB: $numberB,
            orderA: $orderA,
            orderB: $orderB,
            shopifyMatchCountA: count($shopifyMatchesA),
            shopifyMatchCountB: count($shopifyMatchesB),
            shipStationStatusA: $shipStationStatusA,
            shipStationStatusB: $shipStationStatusB,
            shipStationConfigured: $shipStation !== null,
            rows: $comparison['rows'],
            differenceCount: $comparison['difference_count'],
        );
    }

    private function shipStationStatus(?ShipStationClientContract $client, string $orderNumber): ?string
    {
        if ($client === null) {
            return null;
        }

        $matches = $client->findByOrderNumber($orderNumber);

        if ($matches === []) {
            return 'Not found';
        }

        if (count($matches) !== 1) {
            return 'Multiple matches ('.count($matches).')';
        }

        $status = $matches[0]['orderStatus'] ?? null;

        return is_scalar($status) && trim((string) $status) !== '' ? trim((string) $status) : 'Unknown';
    }
}
