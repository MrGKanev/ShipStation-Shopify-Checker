<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Backward-compatible facade for Shopify Admin GraphQL normalizers.
 */
class Normalizer
{
    public static function orderGid(string $orderId): string
    {
        return Ids::orderGid($orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeOrder(array $node): array
    {
        return OrderNormalizer::normalizeOrder($node);
    }

    public static function normalizeFinancialStatus(mixed $status): string
    {
        return OrderNormalizer::normalizeFinancialStatus($status);
    }

    public static function normalizeFulfillmentStatus(mixed $status): ?string
    {
        return OrderNormalizer::normalizeFulfillmentStatus($status);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeEvent(array $event, ?string $fallbackOrderId = null): array
    {
        return EventNormalizer::normalizeEvent($event, $fallbackOrderId);
    }

    public static function isAddressChangeEvent(array $event): bool
    {
        return EventNormalizer::isAddressChangeEvent($event);
    }

    public static function isOrderEditEvent(array $event): bool
    {
        return EventNormalizer::isOrderEditEvent($event);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeMetafield(array $metafield, string $ownerId): array
    {
        return MetafieldNormalizer::normalizeMetafield($metafield, $ownerId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeProduct(array $node): array
    {
        return ProductNormalizer::normalizeProduct($node);
    }
}
