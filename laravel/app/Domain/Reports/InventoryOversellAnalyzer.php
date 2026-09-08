<?php

namespace App\Domain\Reports;

class InventoryOversellAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    public function analyze(array $products, array $orders): array
    {
        $inventory = [];

        foreach ($products as $product) {
            $productId = $this->text($product['legacyResourceId'] ?? null);
            foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $sku = $this->text($variant['sku'] ?? null);
                $tracked = ($variant['inventoryItem']['tracked'] ?? false) === true;
                $continuesSelling = strtolower($this->text($variant['inventoryPolicy'] ?? null)) === 'continue';

                if ($sku === '' || ! $tracked || $continuesSelling) {
                    continue;
                }

                $inventory[$sku] ??= ['stock' => 0, 'variants' => []];
                $inventory[$sku]['stock'] += (int) ($variant['inventoryQuantity'] ?? 0);
                $inventory[$sku]['variants'][] = [
                    'product_id' => ctype_digit($productId) ? $productId : '',
                    'product_title' => $this->text($product['title'] ?? null),
                    'variant_title' => $this->text($variant['title'] ?? null),
                ];
            }
        }

        $awaiting = [];
        foreach ($orders as $order) {
            foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $sku = $this->text($item['sku'] ?? null);
                if ($sku !== '') {
                    $awaiting[$sku] = ($awaiting[$sku] ?? 0) + (int) ($item['quantity'] ?? 1);
                }
            }
        }

        $rows = [];
        foreach ($inventory as $sku => $details) {
            $awaitingQuantity = $awaiting[$sku] ?? 0;
            if ($awaitingQuantity <= $details['stock']) {
                continue;
            }

            $duplicate = count($details['variants']) > 1;
            $variant = $details['variants'][0];
            $rows[] = [
                'sku' => $sku,
                'product_id' => $duplicate ? '' : $variant['product_id'],
                'product_title' => $duplicate ? count($details['variants']).' products share this SKU' : $variant['product_title'],
                'variant_title' => $duplicate ? '' : $variant['variant_title'],
                'stock' => $details['stock'],
                'awaiting' => $awaitingQuantity,
                'shortfall' => $awaitingQuantity - $details['stock'],
                'duplicate_sku' => $duplicate,
            ];
        }

        usort($rows, fn (array $left, array $right): int => $right['shortfall'] <=> $left['shortfall']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
