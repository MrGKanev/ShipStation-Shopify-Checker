<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Fulfillment hold-state lookup for Shopify orders.
 */
class OrderHoldLookup
{
    private const int BATCH_SIZE = 50;
    private const int FULFILLMENT_ORDERS_PER_ORDER = 20;

    public function __construct(
        private readonly Client $client,
        private readonly ?\Cache $cache = null
    ) {
    }

    public function isOnHold(string $orderId): bool
    {
        $check = function () use ($orderId): bool {
            $query = <<<'GQL'
            query OrderFulfillmentOrdersHold($id: ID!, $after: String) {
              order(id: $id) {
                fulfillmentOrders(first: 250, after: $after) {
                  pageInfo { hasNextPage endCursor }
                  nodes {
                    id
                    status
                  }
                }
              }
            }
            GQL;

            $cursor = null;
            do {
                $data = $this->client->graphql($query, [
                    'id'    => Normalizer::orderGid($orderId),
                    'after' => $cursor,
                ]);

                $connection = $data['data']['order']['fulfillmentOrders'] ?? null;
                if (!is_array($connection)) {
                    return false;
                }

                foreach (($connection['nodes'] ?? []) as $fo) {
                    if (strtoupper((string)($fo['status'] ?? '')) === 'ON_HOLD') {
                        return true;
                    }
                }

                $hasNext = (bool)($connection['pageInfo']['hasNextPage'] ?? false);
                $cursor  = $connection['pageInfo']['endCursor'] ?? null;
            } while ($hasNext && $cursor);

            return false;
        };

        if ($this->cache) {
            return $this->cache->remember('shopify_hold', $orderId, $check);
        }

        return $check();
    }

    /**
     * Resolves hold state for many orders in batches rather than issuing one
     * GraphQL request per missing order. Orders with an unusually long
     * fulfillment-order history fall back to the fully paginated single-order
     * lookup, preserving the completeness guarantee of isOnHold().
     *
     * @param array<int, int|string> $orderIds
     * @return array<string, true> Shopify legacy order IDs that are on hold
     */
    public function findOnHoldOrderIds(array $orderIds): array
    {
        $ids = [];
        foreach ($orderIds as $orderId) {
            $id = trim((string)$orderId);
            if ($id !== '') {
                $ids[Normalizer::orderGid($id)] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $perOrder = self::FULFILLMENT_ORDERS_PER_ORDER;
        $query = <<<GQL
        query OrderFulfillmentOrdersHoldBatch(\$ids: [ID!]!) {
          nodes(ids: \$ids) {
            ... on Order {
              id
              fulfillmentOrders(first: {$perOrder}) {
                pageInfo { hasNextPage }
                nodes { status }
              }
            }
          }
        }
        GQL;

        $onHold = [];
        $overflow = [];
        foreach (array_chunk(array_keys($ids), self::BATCH_SIZE) as $chunk) {
            $data = $this->client->graphql($query, ['ids' => $chunk]);
            foreach (($data['data']['nodes'] ?? []) as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $gid = (string)($node['id'] ?? '');
                $orderId = $ids[$gid] ?? '';
                if ($orderId === '') {
                    continue;
                }

                $connection = $node['fulfillmentOrders'] ?? [];
                $isOnHold = false;
                foreach (($connection['nodes'] ?? []) as $fulfillmentOrder) {
                    if (strtoupper((string)($fulfillmentOrder['status'] ?? '')) === 'ON_HOLD') {
                        $isOnHold = true;
                        break;
                    }
                }

                if ($isOnHold) {
                    $onHold[$orderId] = true;
                }

                if (($connection['pageInfo']['hasNextPage'] ?? false) && !$isOnHold) {
                    $overflow[] = $orderId;
                } elseif ($this->cache) {
                    $this->cache->put('shopify_hold', $orderId, $isOnHold);
                }
            }
        }

        // A batch reads the first 20 fulfillment orders to keep GraphQL cost
        // bounded. Only the exceptional overflow orders need individual paging.
        foreach ($overflow as $orderId) {
            if ($this->isOnHold($orderId)) {
                $onHold[$orderId] = true;
            }
        }

        return $onHold;
    }
}
