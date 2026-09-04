<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Facade for non-audit Shopify Admin lookups and order searches.
 */
class AdminLookups
{
    private readonly OrderLookup $orders;
    private readonly CustomDataLookups $customData;
    private readonly OrderInsights $insights;

    public function __construct(
        Client $client,
        ?\Cache $cache = null
    ) {
        $this->orders     = new OrderLookup($client, $cache);
        $this->customData = new CustomDataLookups($client, $cache);
        $this->insights   = new OrderInsights($client);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        return $this->orders->findByOrderNumber($orderNumber);
    }

    public function findByOrderNumbers(array $orderNumbers): array
    {
        return $this->orders->findByOrderNumbers($orderNumbers);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        return $this->orders->getOrder($orderId);
    }

    public function isOnHold(string $orderId): bool
    {
        return $this->orders->isOnHold($orderId);
    }

    /** @return array<string, true> */
    public function findOnHoldOrderIds(array $orderIds): array
    {
        return $this->orders->findOnHoldOrderIds($orderIds);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchMetafieldDefinitions(string $ownerType = 'ORDER'): array
    {
        return $this->customData->fetchMetafieldDefinitions($ownerType);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderMetafields(string $orderId): array
    {
        return $this->customData->getOrderMetafields($orderId);
    }

    public function getOrderMetafieldsByOrderIds(array $orderIds): array
    {
        return $this->customData->getOrderMetafieldsByOrderIds($orderIds);
    }

    /**
     * @return array{matches: array, scanned: int, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(
        string $tag,
        string $startDate = '',
        string $endDate = '',
        int $maxPages = 20
    ): array {
        return $this->insights->searchOrdersByTag($tag, $startDate, $endDate, $maxPages);
    }

    /**
     * @return array{matches:list<array<string,mixed>>,scanned:int,with_mf:int,sample_values:list<mixed>,pages:int,truncated:bool}
     */
    public function searchOrdersByMetafield(
        string $namespace,
        string $key,
        string $value,
        string $startDate = '',
        string $endDate = '',
        int $maxPages = 10
    ): array {
        return $this->customData->searchOrdersByMetafield($namespace, $key, $value, $startDate, $endDate, $maxPages);
    }

    /**
     * @return array{tags: list<array>, total_orders: int, truncated: bool, pages: int}
     */
    public function fetchTagStats(string $startDate = '', string $endDate = '', int $maxPages = 40): array
    {
        return $this->insights->fetchTagStats($startDate, $endDate, $maxPages);
    }

    /**
     * @return array{orders: array, customer: array|null, totalSpent: float, currency: string, truncated: bool}
     */
    public function lookupCustomer(string $email, int $maxPages = 20): array
    {
        return $this->insights->lookupCustomer($email, $maxPages);
    }

    /**
     * @return array{pairs: list<array>, scanned: int, truncated: bool}
     */
    public function findDuplicateOrders(string $startDate, string $endDate): array
    {
        return $this->insights->findDuplicateOrders($startDate, $endDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderEvents(string $orderId): array
    {
        return $this->orders->getOrderEvents($orderId);
    }
}
