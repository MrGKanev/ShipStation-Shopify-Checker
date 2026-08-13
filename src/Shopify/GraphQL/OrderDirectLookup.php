<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Direct Shopify order lookup operations.
 */
class OrderDirectLookup
{
    public function __construct(private readonly Client $client, private readonly ?\Cache $cache = null)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        $clean = ltrim(trim($orderNumber), '#');
        $fetch = function () use ($clean): array {
        $query = <<<'GQL'
        query FindOrderByName($query: String!) {
          orders(first: 10, query: $query) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                name
                createdAt
                cancelledAt
                email
                displayFinancialStatus
                displayFulfillmentStatus
                totalPriceSet { shopMoney { amount currencyCode } }
              }
            }
          }
        }
        GQL;

            $data  = $this->client->graphql($query, ['query' => "name:{$clean}"]);
            $edges = $data['data']['orders']['edges'] ?? [];
            return array_map(fn($edge) => Normalizer::normalizeOrder($edge['node'] ?? []), $edges);
        };
        return $this->cache ? $this->cache->remember('shopify_lookup', "single|v1|{$clean}", $fetch, 60) : $fetch();
    }

    /**
     * Looks up up to 50 exact order names with one Shopify search query.
     * @param array<int, string> $orderNumbers
     * @return array<string, array<int, array<string, mixed>>> keyed by clean order number
     */
    public function findByOrderNumbers(array $orderNumbers): array
    {
        $numbers = [];
        foreach ($orderNumbers as $number) {
            $clean = ltrim(trim($number), '#');
            if ($clean !== '') $numbers[$clean] = true;
        }
        if ($numbers === []) return [];

        $fetch = function () use ($numbers): array {
        $query = <<<'GQL'
        query FindOrdersByNames($query: String!) {
          orders(first: 250, query: $query) {
            edges { node {
              id legacyResourceId name createdAt cancelledAt email
              displayFinancialStatus displayFulfillmentStatus
              totalPriceSet { shopMoney { amount currencyCode } }
            }}
          }
        }
        GQL;
        $terms = array_map(fn(string $number) => "name:{$number}", array_keys($numbers));
        $data = $this->client->graphql($query, ['query' => '(' . implode(' OR ', $terms) . ')']);
        $result = array_fill_keys(array_keys($numbers), []);
        foreach (($data['data']['orders']['edges'] ?? []) as $edge) {
            $order = Normalizer::normalizeOrder($edge['node'] ?? []);
            $key = ltrim((string)($order['name'] ?? ''), '#');
            if (isset($numbers[$key])) $result[$key][] = $order;
        }
        return $result;
        };
        return $this->cache
            ? $this->cache->remember('shopify_lookup', 'batch|v1|' . implode('|', array_keys($numbers)), $fetch, 60)
            : $fetch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $query = <<<'GQL'
        query GetOrderForRestShape($id: ID!) {
          order(id: $id) {
            id
            legacyResourceId
            name
            createdAt
            cancelledAt
            email
            note
            tags
            displayFinancialStatus
            displayFulfillmentStatus
            totalPriceSet { shopMoney { amount currencyCode } }
            totalTaxSet { shopMoney { amount currencyCode } }
            shippingAddress {
              firstName
              lastName
              name
              company
              address1
              address2
              city
              province
              provinceCode
              country
              countryCodeV2
              zip
              phone
            }
            billingAddress {
              firstName
              lastName
              name
              company
              address1
              address2
              city
              province
              provinceCode
              country
              countryCodeV2
              zip
              phone
            }
            lineItems(first: 250) {
              nodes {
                id
                title
                name
                sku
                quantity
                variantTitle
                originalUnitPriceSet { shopMoney { amount currencyCode } }
              }
            }
            shippingLines(first: 250) {
              nodes {
                id
                title
                code
                originalPriceSet { shopMoney { amount currencyCode } }
              }
            }
          }
        }
        GQL;

        $data = $this->client->graphql($query, ['id' => Normalizer::orderGid($orderId)]);
        $node = $data['data']['order'] ?? null;
        return is_array($node) ? Normalizer::normalizeOrder($node) : [];
    }
}
