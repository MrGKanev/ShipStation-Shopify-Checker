<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Shopify metafield definition and value lookup operations.
 */
class CustomDataLookups
{
    public function __construct(private readonly Client $client, private readonly ?\Cache $cache = null)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchMetafieldDefinitions(string $ownerType = 'ORDER'): array
    {
        $fetch = function () use ($ownerType): array {
        $query = <<<GQL
        {
          metafieldDefinitions(first: 250, ownerType: {$ownerType}) {
            edges {
              node {
                namespace
                key
                name
                description
                type { name }
              }
            }
          }
        }
        GQL;

        $data  = $this->client->graphql($query);
        $edges = $data['data']['metafieldDefinitions']['edges'] ?? [];
        return array_map(fn($e) => $e['node'], $edges);
        };
        return $this->cache
            ? $this->cache->remember('shopify_metadata', "definitions|v1|{$ownerType}", $fetch, 3600)
            : $fetch();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderMetafields(string $orderId): array
    {
        $query = <<<'GQL'
        query GetOrderMetafields($id: ID!, $after: String) {
          order(id: $id) {
            metafields(first: 250, after: $after) {
              pageInfo { hasNextPage endCursor }
              nodes {
                id
                namespace
                key
                value
                type
                createdAt
                updatedAt
              }
            }
          }
        }
        GQL;

        $metafields = [];
        $cursor     = null;
        do {
            $data = $this->client->graphql($query, [
                'id'    => Normalizer::orderGid($orderId),
                'after' => $cursor,
            ]);

            $connection = $data['data']['order']['metafields'] ?? null;
            if (!is_array($connection)) {
                return $metafields;
            }

            foreach (($connection['nodes'] ?? []) as $node) {
                if (is_array($node)) {
                    $metafields[] = Normalizer::normalizeMetafield($node, $orderId);
                }
            }

            $hasNext = (bool)($connection['pageInfo']['hasNextPage'] ?? false);
            $cursor  = $connection['pageInfo']['endCursor'] ?? null;
        } while ($hasNext && $cursor);

        return $metafields;
    }

    /** @return array<string, array<int, array<string, mixed>>> keyed by order ID */
    public function getOrderMetafieldsByOrderIds(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $orderIds))));
        if ($ids === []) return [];
        $query = <<<'GQL'
        query GetOrderMetafieldsBatch($ids: [ID!]!) {
          nodes(ids: $ids) { ... on Order {
            id legacyResourceId
            metafields(first: 250) { pageInfo { hasNextPage } nodes {
              id namespace key value type createdAt updatedAt
            }}
          }}
        }
        GQL;
        $result = array_fill_keys($ids, []);
        $overflow = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $gids = array_map(fn(string $id) => Normalizer::orderGid($id), $chunk);
            $data = $this->client->graphql($query, ['ids' => $gids]);
            foreach (($data['data']['nodes'] ?? []) as $node) {
                $id = (string)($node['legacyResourceId'] ?? '');
                if ($id === '') continue;
                $conn = $node['metafields'] ?? [];
                foreach (($conn['nodes'] ?? []) as $metafield) {
                    if (is_array($metafield)) $result[$id][] = Normalizer::normalizeMetafield($metafield, $id);
                }
                if ($conn['pageInfo']['hasNextPage'] ?? false) $overflow[] = $id;
            }
        }
        foreach ($overflow as $id) $result[$id] = $this->getOrderMetafields($id);
        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchOrdersByMetafield(
        string $namespace,
        string $key,
        string $value,
        string $startDate = '',
        string $endDate   = '',
        int    $maxPages  = 10
    ): array {
        $ns = addslashes($namespace);
        $k  = addslashes($key);

        $dateFilter = '';
        if ($startDate) $dateFilter .= ' created_at:>=' . $startDate . 'T00:00:00Z';
        if ($endDate)   $dateFilter .= ' created_at:<=' . $endDate   . 'T23:59:59Z';
        $qFilter = trim($dateFilter) ? ', query: "' . trim($dateFilter) . '"' : '';

        $matches      = [];
        $totalScanned = 0;
        $totalWithMf  = 0;
        $sampleValues = [];

        $template = <<<GQL
        {
          orders(first: 250, sortKey: CREATED_AT, reverse: true{$qFilter}{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                name
                displayFinancialStatus
                displayFulfillmentStatus
                createdAt
                email
                totalPriceSet { shopMoney { amount currencyCode } }
                metafield(namespace: "{$ns}", key: "{$k}") {
                  value
                  type
                }
              }
            }
          }
        }
        GQL;

        ['truncated' => $truncated, 'pages' => $pages] = $this->client->paginateGraphQL(
            $template,
            'orders',
            function (array $edges) use (&$matches, &$totalScanned, &$totalWithMf, &$sampleValues, $value) {
                foreach ($edges as $edge) {
                    $node    = $edge['node'];
                    $mfValue = $node['metafield']['value'] ?? null;
                    $totalScanned++;

                    if ($mfValue !== null) {
                        $totalWithMf++;
                        if (count($sampleValues) < 5 && !in_array($mfValue, $sampleValues, true)) {
                            $sampleValues[] = $mfValue;
                        }
                    }

                    if ($value === '') {
                        if ($mfValue !== null) $matches[] = $node;
                    } else {
                        if ($mfValue !== null && stripos($mfValue, $value) !== false) {
                            $matches[] = $node;
                        }
                    }
                }
            },
            $maxPages
        );

        return [
            'matches'       => $matches,
            'scanned'       => $totalScanned,
            'with_mf'       => $totalWithMf,
            'sample_values' => $sampleValues,
            'pages'         => $pages,
            'truncated'     => $truncated,
        ];
    }
}
