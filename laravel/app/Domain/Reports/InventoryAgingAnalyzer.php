<?php

namespace App\Domain\Reports;

class InventoryAgingAnalyzer
{
    /** @param list<array<string, mixed>> $products @param list<array<string, mixed>> $orders @return array{rows: list<array<string, mixed>>, variants: int} */
    public function analyze(array $products, array $orders): array
    {
        $sales = [];
        foreach ($orders as $order) {
            foreach (is_array($order['line_items'] ?? null) ? $order['line_items'] : [] as $lineItem) {
                $sku = $this->text(is_array($lineItem) ? ($lineItem['sku'] ?? null) : null);
                if ($sku === '') {
                    continue;
                }
                $sales[$sku] ??= ['qty' => 0, 'last_order' => '', 'last_date' => ''];
                $sales[$sku]['qty'] += (int) ($lineItem['quantity'] ?? 1);
                $rawDate = $this->text($order['created_at'] ?? null);
                $date = preg_match('/\A\d{4}-\d{2}-\d{2}/', $rawDate) === 1 ? substr($rawDate, 0, 10) : '';
                if ($date > $sales[$sku]['last_date']) {
                    $sales[$sku]['last_date'] = $date;
                    $sales[$sku]['last_order'] = $this->text($order['name'] ?? null);
                }
            }
        }

        $rows = [];
        $variantCount = 0;
        foreach ($products as $product) {
            foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
                $variantCount++;
                if (! is_array($variant)) {
                    continue;
                }
                $sku = $this->text($variant['sku'] ?? null);
                $tracked = ($variant['inventoryItem']['tracked'] ?? false) === true;
                $policy = mb_strtolower($this->text($variant['inventoryPolicy'] ?? null));
                $stock = (int) ($variant['inventoryQuantity'] ?? 0);
                if ($sku === '' || ! isset($sales[$sku]) || ! $tracked || $policy === 'continue' || $stock > 0) {
                    continue;
                }
                $id = $this->text($product['legacyResourceId'] ?? null);
                $rows[] = ['product_id' => ctype_digit($id) ? $id : '', 'product_title' => $this->text($product['title'] ?? null), 'variant_title' => $this->text($variant['title'] ?? null), 'sku' => $sku, 'stock' => $stock, 'recent_qty' => $sales[$sku]['qty'], 'last_order' => $sales[$sku]['last_order'], 'last_date' => $sales[$sku]['last_date']];
            }
        }
        usort($rows, fn (array $left, array $right): int => $right['recent_qty'] <=> $left['recent_qty']);

        return ['rows' => $rows, 'variants' => $variantCount];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
