<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Shopify Payments dispute (chargeback) lookups.
 *
 * Requires the read_shopify_payments_disputes access scope - returns an
 * empty result set (not an error) if the store isn't on Shopify Payments
 * or the scope isn't granted, since Shopify simply returns no disputes.
 */
class DisputeLookup
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Fetches disputes matching a search-syntax status filter. Defaults to
     * the two open/actionable statuses - NEEDS_RESPONSE (has a hard
     * evidenceDueBy deadline) and UNDER_REVIEW (evidence submitted,
     * awaiting the card network's decision). Resolved statuses (WON, LOST,
     * ACCEPTED, PREVENTED) are excluded by default.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchDisputes(string $statusFilter = 'status:NEEDS_RESPONSE OR status:UNDER_REVIEW'): array
    {
        $query = <<<'GQL'
        query FetchDisputes($query: String, $after: String) {
          disputes(first: 100, after: $after, query: $query) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                legacyResourceId
                status
                initiatedAt
                evidenceDueBy
                amount { amount currencyCode }
                reasonDetails { reason networkReasonCode }
                order { id legacyResourceId name }
              }
            }
          }
        }
        GQL;

        $disputes = [];
        $this->client->paginateGraphQLVariables(
            $query,
            'disputes',
            ['query' => $statusFilter],
            function (array $edges) use (&$disputes) {
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? null;
                    if (is_array($node)) {
                        $disputes[] = self::normalizeDispute($node);
                    }
                }
            },
            20
        );

        return $disputes;
    }

    /**
     * @param  array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function normalizeDispute(array $node): array
    {
        $order = (array)($node['order'] ?? []);
        $reasonDetails = (array)($node['reasonDetails'] ?? []);

        return [
            'id'                   => Ids::legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null),
            'admin_graphql_api_id' => $node['id'] ?? '',
            'status'               => strtolower((string)($node['status'] ?? '')),
            'reason'               => strtolower((string)($reasonDetails['reason'] ?? '')),
            'network_reason_code'  => $reasonDetails['networkReasonCode'] ?? null,
            'initiated_at'         => $node['initiatedAt'] ?? '',
            'evidence_due_by'      => $node['evidenceDueBy'] ?? null,
            'amount'               => $node['amount']['amount'] ?? '0.00',
            'currency'             => $node['amount']['currencyCode'] ?? '',
            'order_id'             => Ids::legacyId($order['legacyResourceId'] ?? null, $order['id'] ?? null),
            'order_name'           => $order['name'] ?? '',
        ];
    }
}
