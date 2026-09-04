<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Customer-oriented Shopify order insights.
 */
class CustomerOrderInsights
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return array{orders: array, customer: array|null, totalSpent: float, currency: string, truncated: bool}
     */
    public function lookupCustomer(string $email, int $maxPages = 20): array
    {
        $safeEmail = addslashes(strtolower(trim($email)));
        $orders    = [];
        $customer  = null;

        $template = <<<GQL
        {
          orders(first: 250, sortKey: CREATED_AT, reverse: true, query: "email:\"{$safeEmail}\""{{AFTER}}) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                name
                displayFinancialStatus
                displayFulfillmentStatus
                cancelledAt
                createdAt
                email
                tags
                totalPriceSet { shopMoney { amount currencyCode } }
                customer {
                  id
                  firstName
                  lastName
                  createdAt
                  verifiedEmail
                }
              }
            }
          }
        }
        GQL;

        ['truncated' => $truncated] = $this->client->paginateGraphQL(
            $template,
            'orders',
            function (array $edges) use (&$orders, &$customer) {
                foreach ($edges as $e) {
                    $node = $e['node'];
                    if ($customer === null && isset($node['customer'])) {
                        $customer = $node['customer'];
                    }
                    $orders[] = $node;
                }
            },
            $maxPages
        );

        $totalSpent = 0.0;
        $currency   = 'USD';
        foreach ($orders as $o) {
            $totalSpent += (float) ($o['totalPriceSet']['shopMoney']['amount'] ?? 0);
            $currency    = $o['totalPriceSet']['shopMoney']['currencyCode'] ?? $currency;
        }

        return compact('orders', 'customer', 'totalSpent', 'currency') + ['truncated' => $truncated];
    }
}
