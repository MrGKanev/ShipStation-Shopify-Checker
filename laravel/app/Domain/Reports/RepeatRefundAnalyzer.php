<?php

namespace App\Domain\Reports;

class RepeatRefundAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders, int $minimum): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $email = mb_strtolower($this->text($order['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $amount = 0.0;
            foreach (is_array($order['refunds'] ?? null) ? $order['refunds'] : [] as $refund) {
                if (! is_array($refund)) {
                    continue;
                }
                foreach (is_array($refund['transactions'] ?? null) ? $refund['transactions'] : [] as $transaction) {
                    if (is_array($transaction) && ($transaction['kind'] ?? '') === 'refund' && ($transaction['status'] ?? '') === 'success' && is_numeric($transaction['amount'] ?? null)) {
                        $amount += (float) $transaction['amount'];
                    }
                }
            }
            $id = $this->text($order['id'] ?? '');
            $groups[$email][] = ['order_number' => $this->text($order['name'] ?? ''), 'shopify_id' => ctype_digit($id) ? $id : '', 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'refunded_amount' => $amount];
        }
        $rows = [];
        foreach ($groups as $email => $group) {
            if (count($group) < $minimum) {
                continue;
            }
            usort($group, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
            $rows[] = ['email' => $email, 'refund_count' => count($group), 'total_refunded' => array_sum(array_column($group, 'refunded_amount')), 'orders' => $group];
        }
        usort($rows, fn (array $a, array $b): int => $b['refund_count'] <=> $a['refund_count']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
