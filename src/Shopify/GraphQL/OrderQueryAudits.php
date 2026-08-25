<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Query-backed order audit fetchers.
 */
class OrderQueryAudits
{
    public function __construct(private readonly OrderFetcher $orders)
    {
    }

    /**
     * Fetches paid orders in a date range with full shipping address fields for address validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddressScan(string $startDate, string $endDate, bool $unfulfilledOnly = false): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate, $unfulfilledOnly),
            Queries::orderCoreFields()
                . Queries::shippingAddressFields()
                . Queries::shippingLineFields()
        );
    }

    /**
     * Returns Shopify orders with refunded or partially_refunded financial status
     * in the given date range, including refund line details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForHighValue(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate, true),
            Queries::orderCoreFields()
                . Queries::shippingAddressFields()
                . Queries::shippingLineFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchRefundedOrders(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::refundedOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::refundFields(),
            fn(array $node) => in_array(
                Normalizer::normalizeFinancialStatus($node['displayFinancialStatus'] ?? null),
                ['refunded', 'partially_refunded'],
                true
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForCountryMismatch(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::billingAddressFields()
                . Queries::shippingAddressFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchPartiallyFulfilledOrders(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::partiallyFulfilledOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::lineItemFields()
                . Queries::fulfillmentFields(),
            fn(array $node) => Normalizer::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null) === 'partial'
        );
    }

    /**
     * Fetches fulfilled/partially-fulfilled orders by fulfillment activity rather than
     * order creation date, so orders created before $startDate but shipped after it are
     * still included. Callers should filter fulfillments by their own createdAt for an
     * exact date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersFulfilledSince(string $startDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::fulfillmentUpdatedSinceQuery($startDate),
            Queries::orderCoreFields()
                . Queries::fulfillmentFields(),
            fn(array $node) => in_array(
                Normalizer::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null),
                ['fulfilled', 'partial'],
                true
            )
        );
    }

    /**
     * Same order set as fetchOrdersFulfilledSince(), but with shipping-line fields
     * instead of fulfillment fields, for callers that only need to match a shipment
     * to its order (e.g. shipping-margin scans) rather than inspect fulfillments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersFulfilledSinceWithShipping(string $startDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::fulfillmentUpdatedSinceQuery($startDate),
            Queries::orderCoreFields()
                . Queries::shippingLineFields(),
            fn(array $node) => in_array(
                Normalizer::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null),
                ['fulfilled', 'partial'],
                true
            )
        );
    }

    /**
     * Fetches refunded/partially-refunded orders by refund activity (updated_at)
     * rather than order creation date, so orders created before $startDate but
     * refunded after it are still included. Callers should filter each order's
     * refunds by their own createdAt for an exact date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersRefundedSince(string $startDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::refundUpdatedSinceQuery($startDate),
            Queries::orderCoreFields()
                . Queries::refundFields(),
            fn(array $node) => in_array(
                Normalizer::normalizeFinancialStatus($node['displayFinancialStatus'] ?? null),
                ['refunded', 'partially_refunded'],
                true
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersWithNotes(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate, true),
            Queries::orderCoreFields()
                . Queries::orderNoteFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddrDupes(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::shippingAddressFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSla(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::shippingAddressFields()
                . Queries::shippingLineFields()
                . Queries::lineItemFields()
                . Queries::fulfillmentFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchCancelledOrders(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::orderDateRangeQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::orderCancelReasonFields(),
            fn(array $node) => !empty($node['cancelledAt'])
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForDiscountAudit(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::shippingAddressFields()
                . Queries::discountApplicationFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTagPolicy(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::orderTagFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTaxAudit(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::totalTaxFields()
                . Queries::customerTaxFields()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForConsentAudit(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::customerConsentFields()
        );
    }

    /**
     * Fetches paid orders with billing/shipping address, tags, and Shopify's
     * fraud risk assessment for a batch RiskScorer::score() pass.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForFraudRisk(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::shippingAddressFields()
                . Queries::billingAddressFields()
                . Queries::orderTagFields()
                . Queries::riskFields()
        );
    }

    /**
     * Fetches paid orders with the placing customer's client IP, for
     * detecting different customer emails ordering from the same device.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSameIp(string $startDate, string $endDate): array
    {
        return $this->orders->fetchOrdersByQuery(
            Queries::paidOrdersQuery($startDate, $endDate),
            Queries::orderCoreFields()
                . Queries::clientIpFields()
        );
    }
}
