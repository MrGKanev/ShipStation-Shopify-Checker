<?php

namespace App\Domain\Reports;

class NoteFlagAnalyzer
{
    /** @param list<array<string, mixed>> $orders @param list<string> $keywords @return list<array<string, mixed>> */
    public function analyze(array $orders, array $keywords): array
    {
        $keywords = array_values(array_unique(array_filter(array_map(fn (string $keyword): string => mb_strtolower(trim($keyword)), $keywords))));
        $rows = [];
        foreach ($orders as $order) {
            $note = $this->text($order['note'] ?? '');
            if ($note === '') {
                continue;
            }
            $lowerNote = mb_strtolower($note);
            $matched = array_values(array_filter($keywords, fn (string $keyword): bool => str_contains($lowerNote, $keyword)));
            if ($matched === []) {
                continue;
            }
            $id = $this->text($order['id'] ?? '');
            $rows[] = ['shopify_id' => ctype_digit($id) ? $id : '', 'order_number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'currency' => strtoupper($this->text($order['currency'] ?? '')), 'financial' => $this->text($order['financial_status'] ?? ''), 'fulfillment' => $this->text($order['fulfillment_status'] ?? ''), 'note' => $note, 'matched' => $matched];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
