<?php

namespace App\Domain\Reports;

class TaxAuditAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders, float $minimum): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $total = is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0;
            $tax = is_numeric($order['total_tax'] ?? null) ? (float) $order['total_tax'] : 0.0;
            if ($total < $minimum || $tax > 0 || ($order['customer_tax_exempt'] ?? false) === true) {
                continue;
            }
            $id = $this->text($order['id'] ?? '');
            $rows[] = ['id' => ctype_digit($id) ? $id : '', 'number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'total' => $total, 'currency' => strtoupper($this->text($order['currency'] ?? '')), 'financial' => $this->text($order['financial_status'] ?? '')];
        }
        usort($rows, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
