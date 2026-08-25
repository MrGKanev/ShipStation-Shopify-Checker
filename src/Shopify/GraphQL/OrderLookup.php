<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Backward-compatible facade for Shopify order lookup operations.
 */
class OrderLookup
{
    private readonly OrderDirectLookup $directLookup;
    private readonly OrderHoldLookup $holdLookup;
    private readonly OrderEventLookup $eventLookup;

    public function __construct(
        Client $client,
        ?\Cache $cache = null
    ) {
        $this->directLookup = new OrderDirectLookup($client, $cache);
        $this->holdLookup   = new OrderHoldLookup($client, $cache);
        $this->eventLookup  = new OrderEventLookup($client);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        return $this->directLookup->findByOrderNumber($orderNumber);
    }

    public function findByOrderNumbers(array $orderNumbers): array
    {
        return $this->directLookup->findByOrderNumbers($orderNumbers);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        return $this->directLookup->getOrder($orderId);
    }

    public function isOnHold(string $orderId): bool
    {
        return $this->holdLookup->isOnHold($orderId);
    }

    /** @return array<string, true> */
    public function findOnHoldOrderIds(array $orderIds): array
    {
        return $this->holdLookup->findOnHoldOrderIds($orderIds);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderEvents(string $orderId): array
    {
        return $this->eventLookup->getOrderEvents($orderId);
    }
}
