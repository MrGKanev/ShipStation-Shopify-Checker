<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Catalog and fulfillment-order utility queries.
 */
class CatalogAndFulfillment
{
    public function __construct(
        private readonly Client $client,
        private readonly ?\Cache $cache = null
    )
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllProducts(string $status = 'active'): array
    {
        $fetch = function () use ($status): array {
            $all      = [];
            $queryArg = Queries::productStatusGraphQLArg($status);
            $template = <<<GQL
        {
          products(first: 250{$queryArg}{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                title
                status
                descriptionHtml
                vendor
                productType
                onlineStoreUrl
                seo { title description }
                collections(first: 1) { edges { node { id } } }
                mediaCount { count }
                variants(first: 250) {
                  edges {
                    node {
                      id
                      legacyResourceId
                      title
                      sku
                      barcode
                      inventoryQuantity
                      inventoryPolicy
                      inventoryItem { tracked }
                    }
                  }
                }
              }
            }
          }
        }
        GQL;

            $this->client->paginateGraphQL(
                $template,
                'products',
                function (array $edges) use (&$all) {
                    foreach ($edges as $edge) {
                        $all[] = Normalizer::normalizeProduct($edge['node'] ?? []);
                    }
                },
                1000
            );

            return $all;
        };

        // Inventory data changes more often than the full-order audit data;
        // cap this cache at 15 minutes even when CACHE_TTL is longer.
        return $this->cache
            ? $this->cache->remember('shopify_catalog', "v1|{$status}", $fetch, 900)
            : $fetch();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOnHoldFulfillmentOrders(string $startDate, string $endDate): array
    {
        $all      = [];
        $template = <<<'GQL'
        {
          fulfillmentOrders(first: 250, query: "status:on_hold"{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                status
                order {
                  id
                  legacyResourceId
                  name
                  email
                  createdAt
                  displayFinancialStatus
                  displayFulfillmentStatus
                  totalPriceSet { shopMoney { amount } }
                }
                fulfillmentHolds {
                  reason
                  reasonNotes
                }
              }
            }
          }
        }
        GQL;

        $this->client->paginateGraphQL($template, 'fulfillmentOrders', function (array $edges) use (&$all, $startDate, $endDate) {
            foreach ($edges as $e) {
                $node      = $e['node'];
                $orderDate = substr($node['order']['createdAt'] ?? '', 0, 10);
                if ($orderDate >= $startDate && $orderDate <= $endDate) {
                    $all[] = $node;
                }
            }
        }, 40);

        return $all;
    }

    /**
     * Fetches all gift cards from the store, normalized into a flat array shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchGiftCards(): array
    {
        $all      = [];
        $template = <<<'GQL'
        {
          giftCards(first: 250{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                maskedCode
                balance { amount currencyCode }
                initialValue { amount currencyCode }
                expiresOn
                enabled
                createdAt
                customer { email }
              }
            }
          }
        }
        GQL;

        $this->client->paginateGraphQL(
            $template,
            'giftCards',
            function (array $edges) use (&$all) {
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    $all[] = [
                        'id'               => (string)($node['id'] ?? ''),
                        'masked_code'      => $node['maskedCode'] ?? '',
                        'balance'          => (float)($node['balance']['amount'] ?? 0),
                        'initial_value'    => (float)($node['initialValue']['amount'] ?? 0),
                        'currency'         => $node['balance']['currencyCode'] ?? $node['initialValue']['currencyCode'] ?? '',
                        'expires_on'       => $node['expiresOn'] ?? null,
                        'enabled'          => (bool)($node['enabled'] ?? false),
                        'created_at'       => $node['createdAt'] ?? '',
                        'customer_email'   => $node['customer']['email'] ?? '',
                    ];
                }
            },
            1000
        );

        return $all;
    }
}
