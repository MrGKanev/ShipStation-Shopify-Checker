<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Reusable Shopify Admin GraphQL field fragments.
 */
class FieldFragments
{
    public static function orderCoreFields(): string
    {
        return <<<'GQL'
                id
                legacyResourceId
                name
                createdAt
                cancelledAt
                email
                displayFinancialStatus
                displayFulfillmentStatus
                totalPriceSet { shopMoney { amount currencyCode } }

GQL;
    }

    public static function shippingAddressFields(): string
    {
        return <<<'GQL'
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

GQL;
    }

    public static function billingAddressFields(): string
    {
        return <<<'GQL'
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

GQL;
    }

    public static function shippingLineFields(): string
    {
        return <<<'GQL'
                shippingLines(first: 250) {
                  nodes {
                    id
                    title
                    code
                    originalPriceSet { shopMoney { amount currencyCode } }
                  }
                }

GQL;
    }

    public static function lineItemFields(): string
    {
        return <<<'GQL'
                lineItems(first: 250) {
                  nodes {
                    id
                    title
                    name
                    sku
                    quantity
                    unfulfilledQuantity
                    variantTitle
                    originalUnitPriceSet { shopMoney { amount currencyCode } }
                  }
                }

GQL;
    }

    public static function fulfillmentFields(): string
    {
        return <<<'GQL'
                fulfillments(first: 250) {
                  id
                  legacyResourceId
                  createdAt
                  status
                  displayStatus
                  trackingInfo(first: 10) {
                    company
                    number
                    url
                  }
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

GQL;
    }

    public static function refundFields(): string
    {
        return <<<'GQL'
                refunds {
                  id
                  legacyResourceId
                  createdAt
                  note
                  totalRefundedSet { shopMoney { amount currencyCode } }
                  refundLineItems(first: 250) {
                    nodes {
                      quantity
                      subtotalSet { shopMoney { amount currencyCode } }
                      lineItem {
                        id
                        title
                        name
                        sku
                        quantity
                      }
                    }
                  }
                  transactions(first: 250) {
                    nodes {
                      id
                      kind
                      status
                      amountSet { shopMoney { amount currencyCode } }
                    }
                  }
                }

GQL;
    }

    public static function discountApplicationFields(): string
    {
        return <<<'GQL'
                discountApplications(first: 250) {
                  nodes {
                    __typename
                    allocationMethod
                    targetSelection
                    targetType
                    value {
                      __typename
                      ... on MoneyV2 {
                        amount
                        currencyCode
                      }
                      ... on PricingPercentageValue {
                        percentage
                      }
                    }
                    ... on DiscountCodeApplication {
                      code
                    }
                  }
                }

GQL;
    }

    public static function orderNoteFields(): string
    {
        return <<<'GQL'
                note

GQL;
    }

    public static function orderTagFields(): string
    {
        return <<<'GQL'
                tags

GQL;
    }

    public static function orderCancelReasonFields(): string
    {
        return <<<'GQL'
                cancelReason

GQL;
    }

    public static function eventFields(): string
    {
        return <<<'GQL'
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

GQL;
    }

    public static function totalTaxFields(): string
    {
        return <<<'GQL'
                totalTaxSet { shopMoney { amount currencyCode } }

GQL;
    }

    public static function customerTaxFields(): string
    {
        return <<<'GQL'
                customer {
                  taxExempt
                }

GQL;
    }

    public static function customerConsentFields(): string
    {
        return <<<'GQL'
                customer {
                  emailMarketingConsent { marketingState }
                  smsMarketingConsent { marketingState }
                }

GQL;
    }

    public static function riskFields(): string
    {
        return <<<'GQL'
                risk {
                  recommendation
                  assessments {
                    riskLevel
                    provider { title }
                    facts { description sentiment }
                  }
                }

GQL;
    }

    public static function clientIpFields(): string
    {
        return <<<'GQL'
                clientIp

GQL;
    }
}
