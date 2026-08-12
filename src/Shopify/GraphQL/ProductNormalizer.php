<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Maps Shopify Admin GraphQL Product payloads into the legacy REST product shape.
 */
class ProductNormalizer
{
    /**
     * Maps Admin GraphQL Product nodes into the legacy REST product shape used by the UI.
     *
     * @return array<string, mixed>
     */
    public static function normalizeProduct(array $node): array
    {
        $productId = Ids::legacyId($node['legacyResourceId'] ?? null, $node['id'] ?? null);
        $images    = array_fill(0, max(0, (int)($node['mediaCount']['count'] ?? 0)), []);

        $variants = [];
        foreach (($node['variants']['edges'] ?? []) as $edge) {
            $variant = $edge['node'] ?? [];
            $variants[] = [
                'id'                   => Ids::legacyId($variant['legacyResourceId'] ?? null, $variant['id'] ?? null),
                'product_id'           => $productId,
                'title'                => $variant['title'] ?? '',
                'sku'                  => $variant['sku'] ?? '',
                'barcode'              => $variant['barcode'] ?? null,
                'inventory_quantity'   => (int)($variant['inventoryQuantity'] ?? 0),
                'inventory_policy'     => strtolower((string)($variant['inventoryPolicy'] ?? '')),
                'inventory_management' => ($variant['inventoryItem']['tracked'] ?? false) ? 'shopify' : null,
                'admin_graphql_api_id' => $variant['id'] ?? '',
            ];
        }

        return [
            'id'                   => $productId,
            'title'                => $node['title'] ?? '',
            'status'               => strtolower((string)($node['status'] ?? '')),
            'body_html'            => $node['descriptionHtml'] ?? '',
            'vendor'               => $node['vendor'] ?? '',
            'product_type'         => $node['productType'] ?? '',
            'images'               => $images,
            'variants'             => $variants,
            'published'            => !empty($node['onlineStoreUrl']),
            'seo_title'            => trim((string)($node['seo']['title'] ?? '')),
            'seo_description'      => trim((string)($node['seo']['description'] ?? '')),
            'collection_count'     => count($node['collections']['edges'] ?? []),
            'admin_graphql_api_id' => $node['id'] ?? '',
        ];
    }
}
