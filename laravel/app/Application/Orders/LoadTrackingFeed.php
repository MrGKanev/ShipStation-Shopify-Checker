<?php

namespace App\Application\Orders;

use App\Domain\Orders\TrackingFeedBuilder;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use LogicException;

class LoadTrackingFeed
{
    public function __construct(
        private readonly ShipStationClientFactory $clients,
        private readonly TrackingFeedBuilder $builder,
    ) {}

    /** @param list<string> $numbers @return list<array<string, mixed>> */
    public function handle(Store $store, array $numbers): array
    {
        $client = $this->clients->forStore($store);
        if ($client === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }

        $results = [];
        foreach ($numbers as $number) {
            $orders = $client->findByOrderNumber($number);
            $shipments = $orders === [] ? [] : $client->getOrderShipments($number);
            $results[] = $this->builder->build($number, $orders, $shipments);
        }

        return $results;
    }
}
