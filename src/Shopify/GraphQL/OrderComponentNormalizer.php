<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Normalizes nested Order component payloads from Shopify Admin GraphQL.
 */
class OrderComponentNormalizer
{
    public static function orderNumberFromName(string $name): int|string
    {
        $number = ltrim(trim($name), '#');
        return ctype_digit($number) ? (int)$number : $number;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function normalizeAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'first_name'    => $address['firstName'] ?? '',
            'last_name'     => $address['lastName'] ?? '',
            'name'          => $address['name'] ?? '',
            'company'       => $address['company'] ?? null,
            'address1'      => $address['address1'] ?? '',
            'address2'      => $address['address2'] ?? '',
            'city'          => $address['city'] ?? '',
            'province'      => $address['province'] ?? '',
            'province_code' => $address['provinceCode'] ?? '',
            'country'       => $address['country'] ?? '',
            'country_code'  => $address['countryCodeV2'] ?? '',
            'zip'           => $address['zip'] ?? '',
            'phone'         => $address['phone'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeLineItem(array $lineItem): array
    {
        $normalized = [
            'id'                   => Ids::legacyId(null, $lineItem['id'] ?? null),
            'title'                => $lineItem['title'] ?? $lineItem['name'] ?? '',
            'name'                 => $lineItem['name'] ?? $lineItem['title'] ?? '',
            'sku'                  => $lineItem['sku'] ?? '',
            'quantity'             => (int)($lineItem['quantity'] ?? 0),
            'variant_title'        => $lineItem['variantTitle'] ?? null,
            'price'                => $lineItem['originalUnitPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $lineItem['id'] ?? '',
        ];

        if (array_key_exists('unfulfilledQuantity', $lineItem)) {
            $normalized['fulfillable_quantity'] = (int)($lineItem['unfulfilledQuantity'] ?? 0);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeShippingLine(array $shippingLine): array
    {
        return [
            'id'                   => Ids::legacyId(null, $shippingLine['id'] ?? null),
            'title'                => $shippingLine['title'] ?? '',
            'code'                 => $shippingLine['code'] ?? '',
            'price'                => $shippingLine['originalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $shippingLine['id'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeFulfillment(array $fulfillment): array
    {
        $trackingInfo = array_values(array_filter(
            (array)($fulfillment['trackingInfo'] ?? []),
            fn($tracking) => is_array($tracking)
        ));
        $firstTracking = $trackingInfo[0] ?? [];

        $lineItems = [];
        foreach (($fulfillment['fulfillmentLineItems']['edges'] ?? []) as $edge) {
            $node = $edge['node'] ?? [];
            if (!is_array($node)) {
                continue;
            }

            $lineItem = self::normalizeLineItem((array)($node['lineItem'] ?? []));
            $lineItem['quantity'] = (int)($node['quantity'] ?? $lineItem['quantity'] ?? 0);
            $lineItems[] = $lineItem;
        }

        return [
            'id'                   => Ids::legacyId($fulfillment['legacyResourceId'] ?? null, $fulfillment['id'] ?? null),
            'admin_graphql_api_id' => $fulfillment['id'] ?? '',
            'created_at'           => $fulfillment['createdAt'] ?? '',
            'status'               => strtolower((string)($fulfillment['status'] ?? '')),
            'display_status'       => strtolower((string)($fulfillment['displayStatus'] ?? '')),
            'shipment_status'      => strtolower((string)($fulfillment['displayStatus'] ?? '')),
            'tracking_company'     => $firstTracking['company'] ?? '',
            'tracking_number'      => $firstTracking['number'] ?? '',
            'tracking_url'         => $firstTracking['url'] ?? '',
            'tracking_numbers'     => array_values(array_filter(array_map(fn($tracking) => $tracking['number'] ?? '', $trackingInfo))),
            'tracking_urls'        => array_values(array_filter(array_map(fn($tracking) => $tracking['url'] ?? '', $trackingInfo))),
            'line_items'           => $lineItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeRefund(array $refund): array
    {
        $refundLineItems = [];
        foreach (($refund['refundLineItems']['nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }

            $lineItem = (array)($node['lineItem'] ?? []);
            $refundLineItems[] = [
                'quantity'     => (int)($node['quantity'] ?? 0),
                'subtotal'     => $node['subtotalSet']['shopMoney']['amount'] ?? '0.00',
                'line_item_id' => Ids::legacyId(null, $lineItem['id'] ?? null),
                'line_item'    => self::normalizeLineItem($lineItem),
            ];
        }

        $transactions = [];
        foreach (($refund['transactions']['nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }

            $transactions[] = [
                'id'                   => Ids::legacyId(null, $node['id'] ?? null),
                'kind'                 => strtolower((string)($node['kind'] ?? '')),
                'status'               => strtolower((string)($node['status'] ?? '')),
                'amount'               => $node['amountSet']['shopMoney']['amount'] ?? '0.00',
                'admin_graphql_api_id' => $node['id'] ?? '',
            ];
        }

        return [
            'id'                   => Ids::legacyId($refund['legacyResourceId'] ?? null, $refund['id'] ?? null),
            'admin_graphql_api_id' => $refund['id'] ?? '',
            'created_at'           => $refund['createdAt'] ?? '',
            'note'                 => $refund['note'] ?? '',
            'total_refunded'       => $refund['totalRefundedSet']['shopMoney']['amount'] ?? '0.00',
            'refund_line_items'    => $refundLineItems,
            'transactions'         => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function normalizeDiscountCode(array $discount): ?array
    {
        if (($discount['__typename'] ?? '') !== 'DiscountCodeApplication') {
            return null;
        }

        $code = trim((string)($discount['code'] ?? ''));
        if ($code === '') {
            return null;
        }

        $value = (array)($discount['value'] ?? []);
        $type = match ($value['__typename'] ?? '') {
            'MoneyV2' => 'fixed_amount',
            'PricingPercentageValue' => 'percentage',
            default => strtolower((string)($value['__typename'] ?? '')),
        };

        return [
            'code'             => $code,
            'amount'           => $value['amount'] ?? (isset($value['percentage']) ? (string)$value['percentage'] : ''),
            'type'             => $type,
            'allocation_method' => strtolower((string)($discount['allocationMethod'] ?? '')),
            'target_selection' => strtolower((string)($discount['targetSelection'] ?? '')),
            'target_type'      => strtolower((string)($discount['targetType'] ?? '')),
        ];
    }
}
