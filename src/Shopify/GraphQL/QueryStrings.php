<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Search query-string builders for Shopify Admin GraphQL connections.
 */
class QueryStrings
{
    public static function orderDateRangeQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:any',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    public static function paidOrdersQuery(string $startDate, string $endDate, bool $unfulfilledOnly = false): string
    {
        $filters = [
            'status:any',
            '(financial_status:paid OR financial_status:partially_paid)',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ];
        if ($unfulfilledOnly) {
            $filters[] = '(fulfillment_status:unfulfilled OR fulfillment_status:partial)';
        }

        return implode(' ', $filters);
    }

    public static function refundedOrdersQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:any',
            '(financial_status:refunded OR financial_status:partially_refunded)',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    public static function partiallyFulfilledOrdersQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'status:open',
            'fulfillment_status:partial',
            '-financial_status:refunded',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    /**
     * Orders fulfilled or partially fulfilled, found by when they were last touched
     * rather than when they were created — fulfilling an order always bumps
     * updated_at, but an order can be created long before it actually ships.
     * No upper bound: a later edit (refund, note, tag) after fulfillment must not
     * push the order out of range.
     */
    public static function fulfillmentUpdatedSinceQuery(string $startDate): string
    {
        return implode(' ', [
            'status:any',
            '(fulfillment_status:fulfilled OR fulfillment_status:partial)',
            'updated_at:>=' . $startDate . 'T00:00:00Z',
        ]);
    }

    /**
     * Refunded or partially-refunded orders, found by when they were last touched
     * rather than when they were created — issuing a refund always bumps
     * updated_at, but an order can be created long before it's refunded.
     * No upper bound: callers filter each refund by its own created_at for an
     * exact date range.
     */
    public static function refundUpdatedSinceQuery(string $startDate): string
    {
        return implode(' ', [
            'status:any',
            '(financial_status:refunded OR financial_status:partially_refunded)',
            'updated_at:>=' . $startDate . 'T00:00:00Z',
        ]);
    }

    public static function orderEventDateRangeQuery(string $startDate, string $endDate): string
    {
        return implode(' ', [
            'subject_type:ORDER',
            'comments:false',
            'created_at:>=' . $startDate . 'T00:00:00Z',
            'created_at:<=' . $endDate   . 'T23:59:59Z',
        ]);
    }

    public static function productStatusGraphQLArg(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '' || $normalized === 'any') {
            return '';
        }

        if (!in_array($normalized, ['active', 'draft', 'archived'], true)) {
            throw new \InvalidArgumentException("Unsupported Shopify product status: {$status}");
        }

        return ', query: "status:' . $normalized . '"';
    }
}
