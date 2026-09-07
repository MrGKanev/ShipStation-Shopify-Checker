<?php

namespace App\Domain\Reports;

use App\Domain\Orders\OrderRiskScorer;

class FraudRiskAnalyzer
{
    public function __construct(private readonly OrderRiskScorer $scorer) {}

    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $risk = $this->scorer->score($order);
            if ($risk['level'] === 'low') {
                continue;
            }
            $id = $this->text($order['id'] ?? '');
            $rows[] = ['id' => ctype_digit($id) ? $id : '', 'number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'currency' => strtoupper($this->text($order['currency'] ?? '')), 'financial' => $this->text($order['financial_status'] ?? ''), 'risk' => $risk];
        }
        usort($rows, fn (array $a, array $b): int => $b['risk']['score'] <=> $a['risk']['score']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
