<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Shared GraphQL batch fetches for orders and order events.
 */
class OrderFetcher
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchEventsByQuery(string $queryStr, int $maxPages = 1000): array
    {
        $events = [];
        $query = <<<'GQL'
        query FetchOrderEventsByQuery($query: String!, $after: String) {
          events(first: 250, sortKey: CREATED_AT, reverse: true, query: $query, after: $after) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                {{EVENT_FIELDS}}
              }
            }
          }
        }
        GQL;
        $query = str_replace('{{EVENT_FIELDS}}', Queries::eventFields(), $query);

        $this->client->paginateGraphQLVariables(
            $query,
            'events',
            ['query' => $queryStr],
            function (array $edges) use (&$events) {
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? null;
                    if (is_array($node)) {
                        $events[] = Normalizer::normalizeEvent($node);
                    }
                }
            },
            $maxPages
        );

        return $events;
    }

    /**
     * @param array<int|string, int|string> $orderIds
     * @return array<string, array<string, mixed>>
     */
    public function fetchOrdersByIds(array $orderIds, string $nodeFields): array
    {
        $ids = [];
        foreach ($orderIds as $orderId) {
            $id = (string)$orderId;
            if ($id === '') {
                continue;
            }
            $ids[] = Normalizer::orderGid($id);
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $query = <<<'GQL'
        query FetchOrdersByIds($ids: [ID!]!) {
          nodes(ids: $ids) {
            ... on Order {
              {{NODE_FIELDS}}
            }
          }
        }
        GQL;
        $query = str_replace('{{NODE_FIELDS}}', $nodeFields, $query);

        $orders = [];
        foreach (array_chunk($ids, 250) as $chunk) {
            $data = $this->client->graphql($query, ['ids' => $chunk]);
            foreach (($data['data']['nodes'] ?? []) as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $order = Normalizer::normalizeOrder($node);
                $id    = (string)($order['id'] ?? '');
                if ($id !== '') {
                    $orders[$id] = $order;
                }
            }
        }

        return $orders;
    }

    /**
     * @param callable(array<string, mixed>): bool|null $nodeFilter
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersByQuery(string $queryStr, string $nodeFields, ?callable $nodeFilter = null): array
    {
        $all = [];
        $query = <<<'GQL'
        query FetchOrdersByQuery($query: String!, $after: String) {
          orders(first: 250, sortKey: CREATED_AT, reverse: true, query: $query, after: $after) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                {{NODE_FIELDS}}
              }
            }
          }
        }
        GQL;
        $query = str_replace('{{NODE_FIELDS}}', $nodeFields, $query);

        $result = $this->client->paginateGraphQLVariables(
            $query,
            'orders',
            ['query' => $queryStr],
            function (array $edges) use (&$all, $nodeFilter) {
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    if ($nodeFilter !== null && !$nodeFilter($node)) {
                        continue;
                    }
                    $all[] = Normalizer::normalizeOrder($node);
                }
            },
            1000
        );

        if ($result['truncated'] ?? false) {
            $this->logWarning('Shopify order query truncated after {pages} pages; results may be incomplete: {query}', [
                'pages' => $result['pages'] ?? '?',
                'query' => $queryStr,
            ]);
        }

        return $all;
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (class_exists(\Logger::class)) {
            \Logger::getInstance()->warning($message, $context);
        }
    }
}
