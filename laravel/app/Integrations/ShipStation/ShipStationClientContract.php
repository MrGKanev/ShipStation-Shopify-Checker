<?php

namespace App\Integrations\ShipStation;

interface ShipStationClientContract
{
    /**
     * @return list<array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderShipments(string $orderNumber): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllOrders(string $startDate, string $endDate): array;
}
