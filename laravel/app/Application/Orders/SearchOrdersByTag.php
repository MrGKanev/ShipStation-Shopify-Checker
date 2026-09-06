<?php

namespace App\Application\Orders;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class SearchOrdersByTag
{
    public function __construct(private readonly ShopifyAdminGateway $shopify) {}

    public function handle(Store $store, string $tag, ?string $startDate, ?string $endDate): OrderTagSearchResult
    {
        $result = $this->shopify->searchOrdersByTag($store, $tag, $startDate, $endDate);
        $orders = array_values(array_filter($result['orders'], function (array $order) use ($tag): bool {
            $tags = is_array($order['tags'] ?? null) ? $order['tags'] : [];

            return collect($tags)->contains(fn (mixed $candidate): bool => is_string($candidate) && mb_strtolower($candidate) === mb_strtolower($tag));
        }));
        $orders = array_map(fn (array $order): array => $this->present($order), $orders);

        return new OrderTagSearchResult($tag, $startDate, $endDate, $orders, $result['pages'], $result['truncated']);
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function present(array $order): array
    {
        $candidateId = $this->text($order['id'] ?? '');
        $id = ctype_digit($candidateId) ? $candidateId : '';
        $tags = is_array($order['tags'] ?? null)
            ? array_values(array_filter($order['tags'], is_string(...)))
            : [];

        return [
            'id' => $id,
            'order_number' => $this->text($order['order_number'] ?? ''),
            'name' => $this->text($order['name'] ?? ''),
            'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10),
            'email' => $this->text($order['email'] ?? ''),
            'tags' => $tags,
            'financial_status' => $this->text($order['financial_status'] ?? ''),
            'fulfillment_status' => $this->text($order['fulfillment_status'] ?? ''),
            'total_price' => $this->text($order['total_price'] ?? ''),
            'currency' => $this->text($order['currency'] ?? ''),
        ];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
