<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Backward-compatible facade for Shopify order insight workflows.
 */
class OrderInsights
{
    private readonly OrderTagInsights $tagInsights;
    private readonly CustomerOrderInsights $customerInsights;
    private readonly DuplicateOrderInsights $duplicateInsights;

    public function __construct(Client $client)
    {
        $this->tagInsights       = new OrderTagInsights($client);
        $this->customerInsights  = new CustomerOrderInsights($client);
        $this->duplicateInsights = new DuplicateOrderInsights($client);
    }

    /**
     * @return array{matches: array, scanned: int, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(
        string $tag,
        string $startDate = '',
        string $endDate   = '',
        int    $maxPages  = 20
    ): array {
        return $this->tagInsights->searchOrdersByTag($tag, $startDate, $endDate, $maxPages);
    }

    /**
     * @return array{tags: list<array>, total_orders: int, truncated: bool, pages: int}
     */
    public function fetchTagStats(string $startDate = '', string $endDate = '', int $maxPages = 40): array
    {
        return $this->tagInsights->fetchTagStats($startDate, $endDate, $maxPages);
    }

    /**
     * @return array{orders: array, customer: array|null, totalSpent: float, currency: string, truncated: bool}
     */
    public function lookupCustomer(string $email, int $maxPages = 20): array
    {
        return $this->customerInsights->lookupCustomer($email, $maxPages);
    }

    /**
     * @return array{pairs: list<array>, scanned: int, truncated: bool}
     */
    public function findDuplicateOrders(string $startDate, string $endDate): array
    {
        return $this->duplicateInsights->findDuplicateOrders($startDate, $endDate);
    }
}
