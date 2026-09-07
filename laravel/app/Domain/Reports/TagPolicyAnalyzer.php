<?php

namespace App\Domain\Reports;

class TagPolicyAnalyzer
{
    /** @param array<string, mixed> $config */
    public function hasRules(array $config): bool
    {
        return $this->rules($config, 'required') !== [] || $this->rules($config, 'forbidden') !== [];
    }

    /** @param list<array<string, mixed>> $orders @param array<string, mixed> $config @return list<array<string, mixed>> */
    public function analyze(array $orders, array $config): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $tags = $this->tags($order['tags'] ?? []);
            $lookup = array_fill_keys(array_map('mb_strtolower', $tags), true);
            $violations = [];
            foreach ($this->rules($config, 'required') as $rule) {
                $when = array_map('mb_strtolower', $this->tags($rule['when'] ?? []));
                $mustHave = array_map('mb_strtolower', $this->tags($rule['must_have'] ?? []));
                if ($when === [] || $mustHave === [] || array_diff($when, array_keys($lookup)) !== []) {
                    continue;
                }
                $missing = array_values(array_diff($mustHave, array_keys($lookup)));
                if ($missing !== []) {
                    $violations[] = ['type' => 'required', 'name' => $this->text($rule['name'] ?? '') ?: 'Required tag policy', 'detail' => 'Missing: '.implode(', ', $missing)];
                }
            }
            foreach ($this->rules($config, 'forbidden') as $rule) {
                $forbidden = array_map('mb_strtolower', $this->tags($rule['tags'] ?? []));
                if (count($forbidden) >= 2 && array_diff($forbidden, array_keys($lookup)) === []) {
                    $violations[] = ['type' => 'forbidden', 'name' => $this->text($rule['name'] ?? '') ?: 'Forbidden tag combination', 'detail' => 'Combination: '.implode(', ', $forbidden)];
                }
            }
            if ($violations !== []) {
                $id = $this->text($order['id'] ?? '');
                $rows[] = ['shopify_id' => ctype_digit($id) ? $id : '', 'order_number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'currency' => strtoupper($this->text($order['currency'] ?? '')), 'financial' => $this->text($order['financial_status'] ?? ''), 'fulfillment' => $this->text($order['fulfillment_status'] ?? ''), 'tags' => $tags, 'violations' => $violations];
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $rows;
    }

    /** @return list<string> */
    private function tags(mixed $value): array
    {
        $values = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);

        return array_values(array_filter(array_map(fn (mixed $tag): string => $this->text($tag), $values), fn (string $tag): bool => $tag !== ''));
    }

    /** @param array<string, mixed> $config @return list<array<string, mixed>> */
    private function rules(array $config, string $key): array
    {
        $rules = $config[$key] ?? [];
        if (! is_array($rules) || ! array_is_list($rules)) {
            return [];
        }

        return array_values(array_filter($rules, 'is_array'));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
