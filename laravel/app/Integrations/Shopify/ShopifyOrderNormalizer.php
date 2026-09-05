<?php

namespace App\Integrations\Shopify;

class ShopifyOrderNormalizer
{
    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function normalize(array $node): array
    {
        $name = (string) ($node['name'] ?? '');

        return [
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
}
