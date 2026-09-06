<?php

namespace App\Integrations\Shopify;

use UnexpectedValueException;

class ShopifyOrderNormalizer
{
    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function normalize(array $node): array
    {
        $name = (string) ($node['name'] ?? '');

        $order = [
            'id' => $this->legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null),
            'order_number' => $this->orderNumber($name),
            'name' => $name,
            'created_at' => $node['createdAt'] ?? '',
            'cancelled_at' => $node['cancelledAt'] ?? null,
            'email' => $node['email'] ?? '',
            'financial_status' => mb_strtolower((string) ($node['displayFinancialStatus'] ?? '')),
            'fulfillment_status' => $this->fulfillmentStatus($node['displayFulfillmentStatus'] ?? null),
            'total_price' => $node['totalPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $node['id'] ?? '',
        ];

        if (array_key_exists('currencyCode', $node['totalPriceSet']['shopMoney'] ?? [])) {
            $order['currency'] = $node['totalPriceSet']['shopMoney']['currencyCode'];
        }

        if (array_key_exists('processedAt', $node)) {
            $order['processed_at'] = $this->nullableString($node['processedAt'], 'processedAt');
        }

        if (array_key_exists('closedAt', $node)) {
            $order['closed_at'] = $this->nullableString($node['closedAt'], 'closedAt');
        }

        if (array_key_exists('cancelReason', $node)) {
            $cancelReason = $this->nullableString($node['cancelReason'], 'cancelReason');
            $order['cancel_reason'] = $cancelReason === null ? null : mb_strtolower($cancelReason);
        }

        if (array_key_exists('risk', $node)) {
            $risk = $node['risk'];

            if ($risk !== null && ! is_array($risk)) {
                throw new UnexpectedValueException('Shopify returned an invalid risk value.');
            }

            $risk = is_array($risk) ? $risk : [];
            $assessments = $risk['assessments'] ?? [];

            if (! is_array($assessments) || ! array_is_list($assessments)) {
                throw new UnexpectedValueException('Shopify returned an invalid risk assessments collection.');
            }

            foreach ($assessments as $assessment) {
                if (! is_array($assessment)) {
                    throw new UnexpectedValueException('Shopify returned an invalid risk assessment.');
                }
            }

            $order['risk_level'] = $this->highestRiskLevel($assessments);
            $order['risk_recommendation'] = $this->nullableString($risk['recommendation'] ?? null, 'risk recommendation') ?? '';
            $order['risk_assessments'] = $assessments;
        }

        if (array_key_exists('shippingAddress', $node)) {
            $order['shipping_address'] = $this->normalizeAddress($node['shippingAddress'] ?? null);
        }

        if (array_key_exists('billingAddress', $node)) {
            $order['billing_address'] = $this->normalizeAddress($node['billingAddress'] ?? null);
        }

        if (array_key_exists('note', $node)) {
            $order['note'] = $this->nullableString($node['note'], 'note') ?? '';
        }

        if (array_key_exists('tags', $node)) {
            $order['tags'] = $this->normalizeTags($node['tags']);
        }

        if (isset($node['lineItems']['nodes']) && is_array($node['lineItems']['nodes'])) {
            $order['line_items'] = array_values(array_map(
                fn (array $lineItem): array => $this->normalizeLineItem($lineItem),
                array_filter($node['lineItems']['nodes'], is_array(...)),
            ));
        }

        if (array_key_exists('fulfillments', $node)) {
            $order['fulfillments'] = $this->normalizeFulfillments($node['fulfillments']);
        }

        if (array_key_exists('refunds', $node)) {
            $order['refunds'] = $this->normalizeRefunds($node['refunds']);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>|null  $address
     * @return array<string, mixed>|null
     */
    private function normalizeAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'first_name' => $address['firstName'] ?? '',
            'last_name' => $address['lastName'] ?? '',
            'name' => $address['name'] ?? '',
            'company' => $address['company'] ?? null,
            'address1' => $address['address1'] ?? '',
            'address2' => $address['address2'] ?? '',
            'city' => $address['city'] ?? '',
            'province' => $address['province'] ?? '',
            'province_code' => $address['provinceCode'] ?? '',
            'country' => $address['country'] ?? '',
            'country_code' => $address['countryCodeV2'] ?? '',
            'zip' => $address['zip'] ?? '',
            'phone' => $address['phone'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $lineItem
     * @return array<string, mixed>
     */
    private function normalizeLineItem(array $lineItem): array
    {
        return [
            'id' => $this->legacyId(null, $lineItem['id'] ?? null),
            'title' => $lineItem['title'] ?? $lineItem['name'] ?? '',
            'name' => $lineItem['name'] ?? $lineItem['title'] ?? '',
            'sku' => $lineItem['sku'] ?? '',
            'quantity' => (int) ($lineItem['quantity'] ?? 0),
            'variant_title' => $lineItem['variantTitle'] ?? null,
            'price' => $lineItem['originalUnitPriceSet']['shopMoney']['amount'] ?? '0.00',
            'admin_graphql_api_id' => $lineItem['id'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     * @return array<string, mixed>
     */
    private function normalizeFulfillment(array $fulfillment): array
    {
        $trackingInfo = array_values(array_filter(
            is_array($fulfillment['trackingInfo'] ?? null) ? $fulfillment['trackingInfo'] : [],
            is_array(...),
        ));
        $firstTracking = $trackingInfo[0] ?? [];

        $lineItems = [];

        if (array_key_exists('fulfillmentLineItems', $fulfillment)) {
            $connection = $fulfillment['fulfillmentLineItems'];

            if (! is_array($connection) || ! is_array($connection['edges'] ?? null)) {
                throw new UnexpectedValueException('Shopify returned an invalid fulfillment line items connection.');
            }

            foreach ($connection['edges'] as $edge) {
                if (! is_array($edge)
                    || ! is_array($edge['node'] ?? null)
                    || ! is_array($edge['node']['lineItem'] ?? null)) {
                    throw new UnexpectedValueException('Shopify returned an invalid fulfillment line item.');
                }

                $lineItem = $this->normalizeLineItem($edge['node']['lineItem']);
                $lineItem['quantity'] = (int) ($edge['node']['quantity'] ?? $lineItem['quantity']);
                $lineItems[] = $lineItem;
            }
        }

        return [
            'id' => $this->legacyId($fulfillment['legacyResourceId'] ?? null, $fulfillment['id'] ?? null),
            'admin_graphql_api_id' => $fulfillment['id'] ?? '',
            'created_at' => $fulfillment['createdAt'] ?? '',
            'status' => mb_strtolower((string) ($fulfillment['status'] ?? '')),
            'display_status' => mb_strtolower((string) ($fulfillment['displayStatus'] ?? '')),
            'shipment_status' => mb_strtolower((string) ($fulfillment['displayStatus'] ?? '')),
            'tracking_company' => $firstTracking['company'] ?? '',
            'tracking_number' => $firstTracking['number'] ?? '',
            'tracking_url' => $firstTracking['url'] ?? '',
            'tracking_numbers' => array_values(array_filter(array_map(
                fn (array $tracking): mixed => $tracking['number'] ?? '',
                $trackingInfo,
            ))),
            'tracking_urls' => array_values(array_filter(array_map(
                fn (array $tracking): mixed => $tracking['url'] ?? '',
                $trackingInfo,
            ))),
            'line_items' => $lineItems,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeFulfillments(mixed $fulfillments): array
    {
        if (! is_array($fulfillments) || ! array_is_list($fulfillments)) {
            throw new UnexpectedValueException('Shopify returned an invalid fulfillments collection.');
        }

        $normalized = [];

        foreach ($fulfillments as $fulfillment) {
            if (! is_array($fulfillment)) {
                throw new UnexpectedValueException('Shopify returned an invalid fulfillment.');
            }

            $normalized[] = $this->normalizeFulfillment($fulfillment);
        }

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRefunds(mixed $refunds): array
    {
        if (! is_array($refunds) || ! array_is_list($refunds)) {
            throw new UnexpectedValueException('Shopify returned an invalid refunds collection.');
        }

        $normalized = [];

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                throw new UnexpectedValueException('Shopify returned an invalid refund.');
            }

            $transactions = $refund['transactions']['nodes'] ?? [];

            if (! is_array($transactions) || ! array_is_list($transactions)) {
                throw new UnexpectedValueException('Shopify returned an invalid refund transactions collection.');
            }

            $normalizedTransactions = [];

            foreach ($transactions as $transaction) {
                if (! is_array($transaction)) {
                    throw new UnexpectedValueException('Shopify returned an invalid refund transaction.');
                }

                $normalizedTransaction = [
                    'id' => $this->legacyId(null, $transaction['id'] ?? null),
                    'kind' => mb_strtolower((string) ($transaction['kind'] ?? '')),
                    'status' => mb_strtolower((string) ($transaction['status'] ?? '')),
                    'amount' => $transaction['amountSet']['shopMoney']['amount'] ?? '0.00',
                    'admin_graphql_api_id' => $transaction['id'] ?? '',
                ];

                if (array_key_exists('currencyCode', $transaction['amountSet']['shopMoney'] ?? [])) {
                    $normalizedTransaction['currency'] = $transaction['amountSet']['shopMoney']['currencyCode'];
                }

                $normalizedTransactions[] = $normalizedTransaction;
            }

            $normalized[] = [
                'id' => $this->legacyId($refund['legacyResourceId'] ?? null, $refund['id'] ?? null),
                'admin_graphql_api_id' => $refund['id'] ?? '',
                'created_at' => $refund['createdAt'] ?? '',
                'note' => $this->nullableString($refund['note'] ?? null, 'refund note') ?? '',
                'total_refunded' => $refund['totalRefundedSet']['shopMoney']['amount'] ?? '0.00',
                'refund_line_items' => [],
                'transactions' => $normalizedTransactions,
            ];
        }

        return $normalized;
    }

    private function legacyId(mixed $legacyResourceId, mixed $graphqlId): int|string
    {
        $id = (string) ($legacyResourceId ?? '');

        if ($id === '' && is_string($graphqlId) && preg_match('~/([0-9]+)(?:\?.*)?$~', $graphqlId, $matches) === 1) {
            $id = $matches[1];
        }

        return ctype_digit($id) ? (int) $id : $id;
    }

    private function orderNumber(string $name): int|string
    {
        $number = ltrim(trim($name), '#');

        return ctype_digit($number) ? (int) $number : $number;
    }

    private function fulfillmentStatus(mixed $status): ?string
    {
        return match ($normalized = mb_strtolower((string) ($status ?? ''))) {
            '', 'unfulfilled' => null,
            'partially_fulfilled' => 'partial',
            default => $normalized,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $assessments
     */
    private function highestRiskLevel(array $assessments): string
    {
        $severity = [
            'HIGH' => 3,
            'MEDIUM' => 2,
            'LOW' => 1,
            'PENDING' => 0,
            'NONE' => 0,
        ];
        $highestLevel = '';
        $highestSeverity = -1;

        foreach ($assessments as $assessment) {
            $level = mb_strtoupper((string) ($assessment['riskLevel'] ?? ''));
            $rank = $severity[$level] ?? -1;

            if ($rank > $highestSeverity) {
                $highestLevel = $level;
                $highestSeverity = $rank;
            }
        }

        return $highestLevel;
    }

    /** @return list<string> */
    private function normalizeTags(mixed $tags): array
    {
        if (! is_array($tags) || ! array_is_list($tags)) {
            throw new UnexpectedValueException('Shopify returned an invalid tags collection.');
        }

        $normalized = [];

        foreach ($tags as $tag) {
            $normalized[] = $this->nullableString($tag, 'tag') ?? '';
        }

        return $normalized;
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException("Shopify returned an invalid {$field} value.");
        }

        return $value;
    }
}
