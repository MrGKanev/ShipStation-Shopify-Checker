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

        if (isset($node['fulfillments']) && is_array($node['fulfillments'])) {
            $order['fulfillments'] = array_values(array_map(
                fn (array $fulfillment): array => $this->normalizeFulfillment($fulfillment),
                array_filter($node['fulfillments'], is_array(...)),
            ));
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
        ];
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
