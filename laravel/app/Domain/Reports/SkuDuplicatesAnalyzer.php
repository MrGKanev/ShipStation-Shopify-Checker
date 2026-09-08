<?php

namespace App\Domain\Reports;

class SkuDuplicatesAnalyzer
{
    /** @param list<array<string, mixed>> $products @return array{rows: list<array<string, mixed>>, totalVariants: int} */
    public function analyze(array $products): array
    {
        $groups = [];
        $totalVariants = 0;
        foreach ($products as $product) {
            foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
                $totalVariants++;
                $sku = $this->text($variant['sku'] ?? null);
                if ($sku === '') {
                    continue;
                }
                $id = $this->text($product['legacyResourceId'] ?? null);
                $key = 'sku:'.$sku;
                $groups[$key] ??= ['sku' => $sku, 'count' => 0, 'variants' => []];
                $groups[$key]['count']++;
                $groups[$key]['variants'][] = [
                    'product_id' => ctype_digit($id) ? $id : '',
                    'product_title' => $this->text($product['title'] ?? null),
                    'product_status' => strtolower($this->text($product['status'] ?? null)),
                    'variant_title' => $this->text($variant['title'] ?? null),
                ];
            }
        }
        $rows = array_values(array_filter($groups, fn (array $group): bool => $group['count'] > 1));
        usort($rows, fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return ['rows' => $rows, 'totalVariants' => $totalVariants];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
