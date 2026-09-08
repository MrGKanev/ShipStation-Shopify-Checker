<?php

namespace App\Domain\Reports;

class ProductCompletenessAnalyzer
{
    /** @param list<array<string, mixed>> $products @return list<array<string, mixed>> */
    public function analyze(array $products): array
    {
        $rows = [];

        foreach ($products as $product) {
            $issues = [];
            $images = is_array($product['images']['nodes'] ?? null) ? $product['images']['nodes'] : [];
            $hasImage = collect($images)->contains(fn (mixed $image): bool => is_array($image) && $this->text($image['id'] ?? '') !== '');

            if (! $hasImage) {
                $issues[] = ['level' => 'warning', 'message' => 'No product images'];
            }

            $description = html_entity_decode(strip_tags($this->text($product['descriptionHtml'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (trim(preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $description) ?? '') === '') {
                $issues[] = ['level' => 'warning', 'message' => 'No description'];
            }

            $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
            $variantCount = count($variants);
            $missingSkuCount = count(array_filter($variants, fn (mixed $variant): bool => ! is_array($variant) || $this->text($variant['sku'] ?? '') === ''));

            if ($variantCount === 0) {
                $issues[] = ['level' => 'critical', 'message' => 'No variants'];
            } elseif ($missingSkuCount > 0) {
                $noun = $variantCount === 1 ? 'variant' : 'variants';
                $issues[] = ['level' => 'critical', 'message' => "{$missingSkuCount} of {$variantCount} {$noun} missing SKU"];
            }

            if ($issues === []) {
                continue;
            }

            $id = $this->text($product['legacyResourceId'] ?? '');
            $rows[] = [
                'id' => ctype_digit($id) ? $id : '',
                'title' => $this->text($product['title'] ?? ''),
                'vendor' => $this->text($product['vendor'] ?? ''),
                'type' => $this->text($product['productType'] ?? ''),
                'images' => $hasImage ? 1 : 0,
                'variants' => $variantCount,
                'issues' => $issues,
                'severity' => in_array('critical', array_column($issues, 'level'), true) ? 'critical' : 'warning',
            ];
        }

        usort($rows, fn (array $left, array $right): int => [($left['severity'] === 'critical' ? 0 : 1), mb_strtolower($left['title']), $left['id']] <=> [($right['severity'] === 'critical' ? 0 : 1), mb_strtolower($right['title']), $right['id']]);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
