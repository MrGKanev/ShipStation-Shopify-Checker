<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

require_once __DIR__ . '/QueryStrings.php';
require_once __DIR__ . '/FieldFragments.php';

/**
 * Backward-compatible facade for Shopify Admin GraphQL query helpers.
 */
class Queries
{
    public static function orderDateRangeQuery(string $startDate, string $endDate): string
    {
        return QueryStrings::orderDateRangeQuery($startDate, $endDate);
    }

    public static function paidOrdersQuery(string $startDate, string $endDate, bool $unfulfilledOnly = false): string
    {
        return QueryStrings::paidOrdersQuery($startDate, $endDate, $unfulfilledOnly);
    }

    public static function refundedOrdersQuery(string $startDate, string $endDate): string
    {
        return QueryStrings::refundedOrdersQuery($startDate, $endDate);
    }

    public static function partiallyFulfilledOrdersQuery(string $startDate, string $endDate): string
    {
        return QueryStrings::partiallyFulfilledOrdersQuery($startDate, $endDate);
    }

    public static function fulfillmentUpdatedSinceQuery(string $startDate): string
    {
        return QueryStrings::fulfillmentUpdatedSinceQuery($startDate);
    }

    public static function refundUpdatedSinceQuery(string $startDate): string
    {
        return QueryStrings::refundUpdatedSinceQuery($startDate);
    }

    public static function orderEventDateRangeQuery(string $startDate, string $endDate): string
    {
        return QueryStrings::orderEventDateRangeQuery($startDate, $endDate);
    }

    public static function productStatusGraphQLArg(string $status): string
    {
        return QueryStrings::productStatusGraphQLArg($status);
    }

    public static function orderCoreFields(): string
    {
        return FieldFragments::orderCoreFields();
    }

    public static function shippingAddressFields(): string
    {
        return FieldFragments::shippingAddressFields();
    }

    public static function billingAddressFields(): string
    {
        return FieldFragments::billingAddressFields();
    }

    public static function shippingLineFields(): string
    {
        return FieldFragments::shippingLineFields();
    }

    public static function lineItemFields(): string
    {
        return FieldFragments::lineItemFields();
    }

    public static function fulfillmentFields(): string
    {
        return FieldFragments::fulfillmentFields();
    }

    public static function refundFields(): string
    {
        return FieldFragments::refundFields();
    }

    public static function discountApplicationFields(): string
    {
        return FieldFragments::discountApplicationFields();
    }

    public static function orderNoteFields(): string
    {
        return FieldFragments::orderNoteFields();
    }

    public static function orderTagFields(): string
    {
        return FieldFragments::orderTagFields();
    }

    public static function orderCancelReasonFields(): string
    {
        return FieldFragments::orderCancelReasonFields();
    }

    public static function eventFields(): string
    {
        return FieldFragments::eventFields();
    }

    public static function totalTaxFields(): string
    {
        return FieldFragments::totalTaxFields();
    }

    public static function customerTaxFields(): string
    {
        return FieldFragments::customerTaxFields();
    }

    public static function customerConsentFields(): string
    {
        return FieldFragments::customerConsentFields();
    }
}
