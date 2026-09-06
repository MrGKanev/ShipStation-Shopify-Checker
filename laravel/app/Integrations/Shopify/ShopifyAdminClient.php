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
    public function __construct(private readonly ShopifyOrderNormalizer $orderNormalizer) {}

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
                    cancelledAt
                    email
                    note
                    tags
                    displayFinancialStatus
                    displayFulfillmentStatus
                    totalPriceSet { shopMoney { amount currencyCode } }
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
