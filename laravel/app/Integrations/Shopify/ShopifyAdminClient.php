<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Integrations\Shopify\Exceptions\ShopifyResponseException;
use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class ShopifyAdminClient implements ShopifyAdminGateway
{
    public const API_VERSION = '2026-07';

    /**
     * The normalizer keeps the migration boundary compatible with existing workflows.
     */
    public function __construct(
        private readonly ShopifyOrderNormalizer $orderNormalizer,
        private readonly ShopifyOrderEventNormalizer $orderEventNormalizer,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function findByOrderNumber(Store $store, string $orderNumber): array
    {
        $cleanOrderNumber = ltrim(trim($orderNumber), '#');

        if ($cleanOrderNumber === '' || preg_match('/\A[a-zA-Z0-9_-]+\z/', $cleanOrderNumber) !== 1) {
            throw new InvalidArgumentException('The Shopify order number is invalid.');
        }

        $query = <<<'GRAPHQL'
            query FindOrderByName($query: String!) {
              orders(first: 10, query: $query) {
                pageInfo { hasNextPage endCursor }
                edges {
                  node {
                    id
                    legacyResourceId
                    name
                    createdAt
                    processedAt
                    closedAt
                    cancelledAt
                    cancelReason
                    email
                    note
                    tags
                    displayFinancialStatus
                    displayFulfillmentStatus
                    totalPriceSet { shopMoney { amount currencyCode } }
                    risk {
                      recommendation
                      assessments { riskLevel }
                    }
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
                      pageInfo { hasNextPage endCursor }
                    }
                    fulfillments(first: 250) {
                      id
                      legacyResourceId
                      createdAt
                      status
                      displayStatus
                      trackingInfo(first: 10) { company number url }
                      fulfillmentLineItems(first: 250) {
                        edges {
                          node {
                            quantity
                            lineItem {
                              id
                              title
                              name
                              sku
                              quantity
                              variantTitle
                              originalUnitPriceSet { shopMoney { amount currencyCode } }
                            }
                          }
                        }
                      }
                    }
                    refunds {
                      id
                      legacyResourceId
                      createdAt
                      note
                      totalRefundedSet { shopMoney { amount currencyCode } }
                      transactions(first: 250) {
                        nodes {
                          id
                          kind
                          status
                          amountSet { shopMoney { amount currencyCode } }
                        }
                      }
                    }
                  }
                }
              }
            }
            GRAPHQL;

        $result = $this->graphql($store, $query, ['query' => "name:{$cleanOrderNumber}"]);
        $edges = $result['data']['orders']['edges'] ?? null;

        if (! is_array($edges)) {
            throw new ShopifyGraphqlException([], 'Shopify order lookup returned an unexpected response shape.');
        }

        $orders = [];

        foreach ($edges as $edge) {
            if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify order lookup returned an unexpected response shape.');
            }

            $orders[] = $this->orderNormalizer->normalize(
                $this->completeLineItems($store, $edge['node']),
            );
        }

        return $orders;
    }

    /**
     * @param  list<string>  $orderNumbers
     * @return array<int|string, list<array<string, mixed>>>
     */
    public function findByOrderNumbers(Store $store, array $orderNumbers): array
    {
        $cleanOrderNumbers = [];

        foreach ($orderNumbers as $orderNumber) {
            if (! is_string($orderNumber)) {
                throw new InvalidArgumentException('Every Shopify order number must be a string.');
            }

            $cleanOrderNumber = ltrim(trim($orderNumber), '#');

            if ($cleanOrderNumber === '') {
                continue;
            }

            if (mb_strlen($cleanOrderNumber) > 64
                || preg_match('/\A[a-zA-Z0-9_-]+\z/', $cleanOrderNumber) !== 1) {
                throw new InvalidArgumentException('A Shopify order number is invalid.');
            }

            $cleanOrderNumbers[$cleanOrderNumber] = true;
        }

        if ($cleanOrderNumbers === []) {
            return [];
        }

        if (count($cleanOrderNumbers) > 50) {
            throw new InvalidArgumentException('Shopify batch lookup accepts at most 50 unique order numbers.');
        }

        $query = <<<'GRAPHQL'
            query FindOrdersByNames($query: String!, $after: String) {
              orders(first: 250, after: $after, query: $query) {
                pageInfo { hasNextPage endCursor }
                edges {
                  node {
                    id
                    legacyResourceId
                    name
                    createdAt
                    cancelledAt
                    email
                    tags
                    displayFinancialStatus
                    displayFulfillmentStatus
                    totalPriceSet { shopMoney { amount currencyCode } }
                    shippingAddress {
                      address1
                      country
                      countryCodeV2
                      phone
                    }
                    billingAddress {
                      country
                      countryCodeV2
                    }
                    risk {
                      recommendation
                      assessments { riskLevel }
                    }
                  }
                }
              }
            }
            GRAPHQL;
        $terms = array_map(
            fn (string $orderNumber): string => "name:{$orderNumber}",
            array_keys($cleanOrderNumbers),
        );
        $page = $this->paginateGraphql(
            $store,
            $query,
            'orders',
            ['query' => '('.implode(' OR ', $terms).')'],
        );

        if ($page['truncated']) {
            throw new ShopifyGraphqlException([], 'Shopify batch order lookup exceeded its page limit.');
        }

        $ordersByNumber = array_fill_keys(array_keys($cleanOrderNumbers), []);

        foreach ($page['edges'] as $edge) {
            if (! is_array($edge['node'] ?? null) || ! is_string($edge['node']['name'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify batch order lookup returned an unexpected response shape.');
            }

            $cleanReturnedNumber = ltrim(trim($edge['node']['name']), '#');

            if (array_key_exists($cleanReturnedNumber, $ordersByNumber)) {
                $ordersByNumber[$cleanReturnedNumber][] = $this->orderNormalizer->normalize($edge['node']);
            }
        }

        return $ordersByNumber;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderEvents(Store $store, string $orderId): array
    {
        $graphqlOrderId = $this->orderGid($orderId);
        $query = <<<'GRAPHQL'
            query GetOrderEvents($id: ID!, $after: String) {
              order(id: $id) {
                events(first: 250, sortKey: CREATED_AT, reverse: true, after: $after) {
                  pageInfo { hasNextPage endCursor }
                  edges {
                    node {
                      __typename
                      id
                      action
                      appTitle
                      createdAt
                      message
                      ... on BasicEvent {
                        subjectId
                        subjectType
                      }
                    }
                  }
                }
              }
            }
            GRAPHQL;

        $events = [];
        $cursor = null;
        $pages = 0;

        do {
            $requestedCursor = $cursor;
            $result = $this->graphql($store, $query, [
                'id' => $graphqlOrderId,
                'after' => $cursor,
            ]);
            $data = $result['data'];

            if (! is_array($data) || ! array_key_exists('order', $data)) {
                throw new ShopifyGraphqlException([], 'Shopify order events returned an unexpected response shape.');
            }

            if ($data['order'] === null && $pages === 0) {
                return [];
            }

            $connection = is_array($data['order']) ? ($data['order']['events'] ?? null) : null;

            if (! $this->isValidEventConnection($connection)) {
                throw new ShopifyGraphqlException([], 'Shopify order events returned an unexpected response shape.');
            }

            foreach ($connection['edges'] as $edge) {
                if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                    throw new ShopifyGraphqlException([], 'Shopify order events returned an unexpected response shape.');
                }

                $events[] = $this->orderEventNormalizer->normalize($edge['node'], $graphqlOrderId);
            }

            $hasNextPage = $connection['pageInfo']['hasNextPage'];
            $cursor = is_string($connection['pageInfo']['endCursor'] ?? null)
                ? $connection['pageInfo']['endCursor']
                : null;
            $pages++;

            if ($hasNextPage && ($cursor === null || $cursor === '' || $cursor === $requestedCursor)) {
                throw new ShopifyGraphqlException([], 'Shopify order events returned an unexpected response shape.');
            }
        } while ($hasNextPage);

        return $events;
    }

    /**
     * @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(Store $store, string $tag, ?string $startDate = null, ?string $endDate = null): array
    {
        $escapedTag = str_replace(['\\', '"'], ['\\\\', '\\"'], $tag);
        $search = 'tag:"'.$escapedTag.'"';

        if ($startDate !== null) {
            $search .= ' created_at:>='.$startDate.'T00:00:00Z';
        }

        if ($endDate !== null) {
            $search .= ' created_at:<='.$endDate.'T23:59:59Z';
        }

        $query = <<<'GRAPHQL'
            query SearchOrdersByTag($search: String!, $after: String) {
              orders(first: 250, after: $after, sortKey: CREATED_AT, reverse: true, query: $search) {
                pageInfo { hasNextPage endCursor }
                edges {
                  node {
                    id
                    legacyResourceId
                    name
                    createdAt
                    email
                    tags
                    displayFinancialStatus
                    displayFulfillmentStatus
                    totalPriceSet { shopMoney { amount currencyCode } }
                  }
                }
              }
            }
            GRAPHQL;

        $result = $this->paginateGraphql($store, $query, 'orders', ['search' => $search]);
        $orders = [];

        foreach ($result['edges'] as $edge) {
            if (! is_array($edge['node'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify tag search returned an unexpected response shape.');
            }

            $orders[] = $this->orderNormalizer->normalize($edge['node']);
        }

        return ['orders' => $orders, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function highValueOrderCandidates(Store $store, string $startDate, string $endDate): array
    {
        $query = <<<'GRAPHQL'
            query HighValueOrderCandidates($search: String!, $after: String) {
              orders(first: 250, after: $after, sortKey: CREATED_AT, reverse: true, query: $search) {
                pageInfo { hasNextPage endCursor }
                edges { node { id legacyResourceId name createdAt cancelledAt email displayFinancialStatus displayFulfillmentStatus totalPriceSet { shopMoney { amount currencyCode } } shippingAddress { firstName lastName address1 address2 city province zip country phone } } }
              }
            }
            GRAPHQL;
        $search = "status:any (financial_status:paid OR financial_status:partially_paid) (fulfillment_status:unfulfilled OR fulfillment_status:partial) created_at:>={$startDate}T00:00:00Z created_at:<={$endDate}T23:59:59Z";
        $result = $this->paginateGraphql($store, $query, 'orders', ['search' => $search], 100);
        $orders = [];

        foreach ($result['edges'] as $edge) {
            if (! is_array($edge['node'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify high-value report returned an unexpected response shape.');
            }
            $orders[] = $this->orderNormalizer->normalize($edge['node']);
        }

        return ['orders' => $orders, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function countryMismatchCandidates(Store $store, string $startDate, string $endDate): array
    {
        $query = <<<'GRAPHQL'
            query CountryMismatchCandidates($search: String!, $after: String) {
              orders(first: 250, after: $after, sortKey: CREATED_AT, reverse: true, query: $search) {
                pageInfo { hasNextPage endCursor }
                edges { node { id legacyResourceId name createdAt cancelledAt email displayFinancialStatus displayFulfillmentStatus totalPriceSet { shopMoney { amount currencyCode } } billingAddress { firstName lastName country countryCodeV2 } shippingAddress { country countryCodeV2 } } }
              }
            }
            GRAPHQL;
        $search = "status:any (financial_status:paid OR financial_status:partially_paid) created_at:>={$startDate}T00:00:00Z created_at:<={$endDate}T23:59:59Z";
        $result = $this->paginateGraphql($store, $query, 'orders', ['search' => $search], 100);
        $orders = [];
        foreach ($result['edges'] as $edge) {
            if (! is_array($edge['node'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify country mismatch report returned an unexpected response shape.');
            }
            $orders[] = $this->orderNormalizer->normalize($edge['node']);
        }

        return ['orders' => $orders, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function completeLineItems(Store $store, array $order): array
    {
        if (! array_key_exists('lineItems', $order)) {
            return $order;
        }

        $connection = $order['lineItems'];

        if (! $this->isValidLineItemConnection($connection)) {
            throw new ShopifyGraphqlException([], 'Shopify line items returned an unexpected response shape.');
        }

        $pages = 1;

        while ($connection['pageInfo']['hasNextPage']) {
            if ($pages >= 20) {
                throw new ShopifyGraphqlException([], 'Shopify line item pagination exceeded its page limit.');
            }

            $cursor = $connection['pageInfo']['endCursor'] ?? null;
            $orderId = $order['id'] ?? null;

            if (! is_string($cursor) || $cursor === '' || ! is_string($orderId) || $orderId === '') {
                throw new ShopifyGraphqlException([], 'Shopify line items returned an unexpected response shape.');
            }

            $result = $this->graphql($store, $this->lineItemsQuery(), [
                'id' => $orderId,
                'after' => $cursor,
            ]);
            $connection = $result['data']['order']['lineItems'] ?? null;

            if (! $this->isValidLineItemConnection($connection)) {
                throw new ShopifyGraphqlException([], 'Shopify line items returned an unexpected response shape.');
            }

            foreach ($connection['nodes'] as $lineItem) {
                $order['lineItems']['nodes'][] = $lineItem;
            }

            $order['lineItems']['pageInfo'] = $connection['pageInfo'];
            $pages++;
        }

        return $order;
    }

    /**
     * @return non-empty-string
     */
    private function lineItemsQuery(): string
    {
        return <<<'GRAPHQL'
            query OrderLineItems($id: ID!, $after: String!) {
              order(id: $id) {
                lineItems(first: 250, after: $after) {
                  nodes {
                    id
                    title
                    name
                    sku
                    quantity
                    variantTitle
                    originalUnitPriceSet { shopMoney { amount currencyCode } }
                  }
                  pageInfo { hasNextPage endCursor }
                }
              }
            }
            GRAPHQL;
    }

    private function isValidLineItemConnection(mixed $connection): bool
    {
        if (! is_array($connection)
            || ! is_array($connection['nodes'] ?? null)
            || ! is_array($connection['pageInfo'] ?? null)
            || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
            return false;
        }

        return array_all($connection['nodes'], fn (mixed $node): bool => is_array($node));
    }

    private function isValidEventConnection(mixed $connection): bool
    {
        return is_array($connection)
            && is_array($connection['edges'] ?? null)
            && is_array($connection['pageInfo'] ?? null)
            && is_bool($connection['pageInfo']['hasNextPage'] ?? null);
    }

    private function orderGid(string $orderId): string
    {
        $orderId = trim($orderId);

        if (preg_match('/\Agid:\/\/shopify\/Order\/[0-9]+\z/', $orderId) === 1) {
            return $orderId;
        }

        if ($orderId === '' || ! ctype_digit($orderId)) {
            throw new InvalidArgumentException('The Shopify order ID is invalid.');
        }

        return "gid://shopify/Order/{$orderId}";
    }

    /**
     * @param  array<string, bool|int|string>  $query
     * @return array<string, mixed>
     */
    public function get(Store $store, string $resource, array $query = []): array
    {
        $response = $this->request($store)
            ->retry(
                [100, 500, 1000],
                when: fn (Throwable $exception): bool => $this->isTransientFailure($exception),
                throw: false,
            )
            ->get($this->resourcePath($resource), $query)
            ->throw();

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ShopifyResponseException('Shopify returned an invalid JSON response.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(Store $store, string $query, array $variables = []): array
    {
        $payload = ['query' => $query];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $response = $this->request($store)
            ->post('graphql.json', $payload)
            ->throw();

        $result = $response->json();

        if (! is_array($result)) {
            throw new ShopifyGraphqlException([], 'Shopify GraphQL returned an unexpected response shape.');
        }

        if (array_key_exists('errors', $result) && ! is_array($result['errors'])) {
            throw new ShopifyGraphqlException([], 'Shopify GraphQL returned an unexpected response shape.');
        }

        $errors = $result['errors'] ?? [];

        if (is_array($errors) && $errors !== []) {
            /** @var list<array<string, mixed>> $errors */
            throw new ShopifyGraphqlException($errors);
        }

        if (! array_key_exists('data', $result)) {
            throw new ShopifyGraphqlException([], 'Shopify GraphQL returned an unexpected response shape.');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{edges: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public function paginateGraphql(
        Store $store,
        string $query,
        string $rootKey,
        array $variables = [],
        int $maxPages = 20,
    ): array {
        if ($maxPages < 1) {
            throw new InvalidArgumentException('Shopify pagination requires at least one page.');
        }

        $edges = [];
        $cursor = null;
        $pages = 0;
        $hasNextPage = false;

        do {
            $result = $this->graphql($store, $query, [
                ...$variables,
                'after' => $cursor,
            ]);
            $connection = $result['data'][$rootKey] ?? null;

            if (! is_array($connection)
                || ! is_array($connection['edges'] ?? null)
                || ! is_array($connection['pageInfo'] ?? null)
                || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify pagination returned an unexpected response shape.');
            }

            foreach ($connection['edges'] as $edge) {
                if (! is_array($edge)) {
                    throw new ShopifyGraphqlException([], 'Shopify pagination returned an unexpected response shape.');
                }

                $edges[] = $edge;
            }

            $pageInfo = $connection['pageInfo'];
            $hasNextPage = $pageInfo['hasNextPage'];
            $cursor = is_string($pageInfo['endCursor'] ?? null)
                ? $pageInfo['endCursor']
                : null;

            if ($hasNextPage && $cursor === null) {
                throw new ShopifyGraphqlException([], 'Shopify pagination returned an unexpected response shape.');
            }

            $pages++;
        } while ($hasNextPage && $cursor !== null && $pages < $maxPages);

        return [
            'edges' => $edges,
            'pages' => $pages,
            'truncated' => $hasNextPage,
        ];
    }

    private function request(Store $store): PendingRequest
    {
        $accessToken = (string) $store->shopify_access_token;

        if ($accessToken === '') {
            throw new InvalidArgumentException('The Shopify access token is missing.');
        }

        return Http::baseUrl($this->baseUrl($store))
            ->acceptJson()
            ->asJson()
            ->withHeader('X-Shopify-Access-Token', $accessToken)
            ->withUserAgent('ShopifyOps/2.0')
            ->connectTimeout(3)
            ->timeout(15);
    }

    private function baseUrl(Store $store): string
    {
        $shop = (string) $store->shopify_store;

        if (preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $shop) !== 1) {
            throw new InvalidArgumentException('The Shopify store identifier is invalid.');
        }

        return "https://{$shop}.myshopify.com/admin/api/".self::API_VERSION;
    }

    private function resourcePath(string $resource): string
    {
        if ($resource === ''
            || str_starts_with($resource, '/')
            || str_contains($resource, '..')
            || str_contains($resource, '://')
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]*\z/', $resource) !== 1) {
            throw new InvalidArgumentException('The Shopify resource must be a relative path.');
        }

        return $resource;
    }

    private function isTransientFailure(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->status() === 429 || $exception->response->serverError());
    }
}
