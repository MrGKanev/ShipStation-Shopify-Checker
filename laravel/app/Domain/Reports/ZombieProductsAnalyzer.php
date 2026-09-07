<?php

namespace App\Domain\Reports;

class ZombieProductsAnalyzer
{
    /** @param list<array<string, mixed>> $products @return list<array<string, mixed>> */
    public function analyze(array $products): array
    {
        $rows = [];
        foreach ($products as $product) {
            $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
            if ($variants === []) {
                $rows[] = $this->row($product, 'no_variants', 'No variants defined', null);

                continue;
            }

            $trackedCount = 0;
            $zeroStockCount = 0;
            $totalStock = 0;
            foreach ($variants as $variant) {
                if (! is_array($variant) || ($variant['inventoryItem']['tracked'] ?? false) !== true || mb_strtolower($this->text($variant['inventoryPolicy'] ?? null)) === 'continue') {
                    continue;
                }
                $trackedCount++;
                $quantity = (int) ($variant['inventoryQuantity'] ?? 0);
                $totalStock += $quantity;
                if ($quantity <= 0) {
                    $zeroStockCount++;
                }
            }

            if ($trackedCount > 0 && $trackedCount === $zeroStockCount) {
                $noun = $trackedCount === 1 ? 'variant' : 'variants';
                $rows[] = $this->row($product, 'zero_stock', "{$trackedCount} tracked {$noun}, all at 0", $totalStock);
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function row(array $product, string $reason, string $detail, ?int $stock): array
    {
        $id = $this->text($product['legacyResourceId'] ?? null);

        return ['id' => ctype_digit($id) ? $id : '', 'title' => $this->text($product['title'] ?? null), 'vendor' => $this->text($product['vendor'] ?? null), 'type' => $this->text($product['productType'] ?? null), 'reason' => $reason, 'detail' => $detail, 'stock' => $stock];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
