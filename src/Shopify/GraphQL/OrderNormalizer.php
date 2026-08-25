<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Maps Shopify Admin GraphQL Order payloads into the legacy REST-shaped arrays
 * consumed by the rest of the app.
 */
class OrderNormalizer
{
    /**
     * Maps Admin GraphQL Order nodes into the legacy REST order shape used by the UI and ShipStation push.
     *
     * @return array<string, mixed>
     */
    public static function normalizeOrder(array $node): array
    {
        $id   = Ids::legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null);
        $name = (string)($node['name'] ?? '');

        $order = [
            'id'                   => $id,
            'order_number'         => OrderComponentNormalizer::orderNumberFromName($name),
            'name'                 => $name,
            'created_at'           => $node['createdAt'] ?? '',
            'cancelled_at'         => $node['cancelledAt'] ?? null,
            'email'                => $node['email'] ?? '',
            'financial_status'     => self::normalizeFinancialStatus($node['displayFinancialStatus'] ?? null),
            'fulfillment_status'   => self::normalizeFulfillmentStatus($node['displayFulfillmentStatus'] ?? null),
            'total_price'          => $node['totalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $node['id'] ?? '',
        ];

        if (array_key_exists('totalTaxSet', $node)) {
            $order['total_tax'] = $node['totalTaxSet']['shopMoney']['amount'] ?? '0.00';
        }
        if (array_key_exists('cancelReason', $node)) {
            $reason = $node['cancelReason'] ?? null;
            $order['cancel_reason'] = $reason === null ? null : strtolower((string)$reason);
        }
        if (array_key_exists('note', $node)) {
            $order['note'] = $node['note'] ?? '';
        }
        if (array_key_exists('tags', $node)) {
            $order['tags'] = implode(', ', (array)($node['tags'] ?? []));
        }
        if (array_key_exists('shippingAddress', $node)) {
            $order['shipping_address'] = OrderComponentNormalizer::normalizeAddress($node['shippingAddress'] ?? null);
        }
        if (array_key_exists('billingAddress', $node)) {
            $order['billing_address'] = OrderComponentNormalizer::normalizeAddress($node['billingAddress'] ?? null);
        }
        if (array_key_exists('customer', $node)) {
            $customer = (array)($node['customer'] ?? []);
            if (array_key_exists('taxExempt', $customer)) {
                $order['customer_tax_exempt'] = (bool)($customer['taxExempt'] ?? false);
            }
            if (array_key_exists('emailMarketingConsent', $customer)) {
                $order['customer_email_consent'] = strtolower((string)($customer['emailMarketingConsent']['marketingState'] ?? ''));
            }
            if (array_key_exists('smsMarketingConsent', $customer)) {
                $order['customer_sms_consent'] = strtolower((string)($customer['smsMarketingConsent']['marketingState'] ?? ''));
            }
        }
        if (isset($node['lineItems']['nodes'])) {
            $order['line_items'] = array_map(
                fn($lineItem) => OrderComponentNormalizer::normalizeLineItem($lineItem),
                $node['lineItems']['nodes']
            );
        }
        if (isset($node['shippingLines']['nodes'])) {
            $order['shipping_lines'] = array_map(
                fn($shippingLine) => OrderComponentNormalizer::normalizeShippingLine($shippingLine),
                $node['shippingLines']['nodes']
            );
        }
        if (isset($node['fulfillments'])) {
            $order['fulfillments'] = array_map(
                fn($fulfillment) => OrderComponentNormalizer::normalizeFulfillment($fulfillment),
                (array)$node['fulfillments']
            );
        }
        if (isset($node['refunds'])) {
            $order['refunds'] = array_map(
                fn($refund) => OrderComponentNormalizer::normalizeRefund($refund),
                (array)$node['refunds']
            );
        }
        if (isset($node['discountApplications']['nodes'])) {
            $order['discount_codes'] = array_values(array_filter(array_map(
                fn($discount) => OrderComponentNormalizer::normalizeDiscountCode($discount),
                $node['discountApplications']['nodes']
            )));
        }
        if (array_key_exists('customAttributes', $node)) {
            $order['note_attributes'] = array_map(
                fn($attr) => ['key' => $attr['key'] ?? '', 'value' => $attr['value'] ?? ''],
                (array)($node['customAttributes'] ?? [])
            );
        }
        if (array_key_exists('risk', $node)) {
            $risk        = (array)($node['risk'] ?? []);
            $assessments = (array)($risk['assessments'] ?? []);
            $order['risk_level']          = self::highestRiskLevel($assessments);
            $order['risk_recommendation'] = $risk['recommendation'] ?? '';
            $order['risk_assessments']    = $assessments;
        }
        if (array_key_exists('clientIp', $node)) {
            $order['client_ip'] = $node['clientIp'] ?? '';
        }
        if (array_key_exists('test', $node)) {
            $order['test'] = (bool)($node['test'] ?? false);
        }
        if (array_key_exists('customerJourneySummary', $node)) {
            $order['customer_journey'] = self::normalizeCustomerJourney((array)($node['customerJourneySummary'] ?? []));
        }
        if (array_key_exists('sourceName', $node)) {
            $order['source_name'] = $node['sourceName'] ?? '';
        }
        if (array_key_exists('app', $node)) {
            $order['app_name'] = $node['app']['name'] ?? '';
        }
        if (array_key_exists('currentTotalPriceSet', $node)) {
            $order['current_total_price'] = $node['currentTotalPriceSet']['shopMoney']['amount'] ?? '0.00';
        }
        if (array_key_exists('edited', $node)) {
            $order['edited'] = (bool)($node['edited'] ?? false);
        }
        if (array_key_exists('paymentGatewayNames', $node)) {
            $order['payment_gateway_names'] = (array)($node['paymentGatewayNames'] ?? []);
        }
        if (array_key_exists('poNumber', $node)) {
            $order['po_number'] = $node['poNumber'] ?? '';
        }
        if (array_key_exists('confirmationNumber', $node)) {
            $order['confirmation_number'] = $node['confirmationNumber'] ?? '';
        }
        if (array_key_exists('statusPageUrl', $node)) {
            $order['status_page_url'] = $node['statusPageUrl'] ?? '';
        }
        if (array_key_exists('customerLocale', $node)) {
            $order['customer_locale'] = $node['customerLocale'] ?? '';
        }

        return $order;
    }

    public static function normalizeFinancialStatus(mixed $status): string
    {
        return strtolower((string)($status ?? ''));
    }

    public static function normalizeFulfillmentStatus(mixed $status): ?string
    {
        $normalized = strtolower((string)($status ?? ''));
        return match ($normalized) {
            '', 'unfulfilled' => null,
            'partially_fulfilled' => 'partial',
            default => $normalized,
        };
    }

    /**
     * @param array<string, mixed> $visit
     * @return array<string, mixed>|null
     */
    private static function normalizeVisit(mixed $visit): ?array
    {
        if (!is_array($visit)) {
            return null;
        }
        $utm = (array)($visit['utmParameters'] ?? []);
        return [
            'landing_page' => $visit['landingPage'] ?? '',
            'referrer_url' => $visit['referrerUrl'] ?? '',
            'source'       => $visit['source'] ?? '',
            'utm'          => [
                'source'   => $utm['source'] ?? '',
                'medium'   => $utm['medium'] ?? '',
                'campaign' => $utm['campaign'] ?? '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $journey
     * @return array<string, mixed>
     */
    private static function normalizeCustomerJourney(array $journey): array
    {
        return [
            'days_to_conversion' => $journey['daysToConversion'] ?? null,
            'first_visit'        => self::normalizeVisit($journey['firstVisit'] ?? null),
            'last_visit'         => self::normalizeVisit($journey['lastVisit'] ?? null),
        ];
    }

    /**
     * Picks the most severe risk level across an order's assessments, since
     * RiskScorer only cares whether *any* provider flagged HIGH.
     *
     * @param array<int, array<string, mixed>> $assessments
     */
    private static function highestRiskLevel(array $assessments): string
    {
        $order = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1, 'PENDING' => 0, 'NONE' => 0];
        $best = '';
        $bestRank = -1;
        foreach ($assessments as $assessment) {
            $level = strtoupper((string)($assessment['riskLevel'] ?? ''));
            $rank = $order[$level] ?? -1;
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $level;
            }
        }
        return $best;
    }
}
