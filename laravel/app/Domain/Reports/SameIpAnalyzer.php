<?php

namespace App\Domain\Reports;

class SameIpAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $ip = $this->text($order['client_ip'] ?? '');
            $email = mb_strtolower($this->text($order['email'] ?? ''));
            if ($ip === '' || $email === '') {
                continue;
            }
            $groups[$ip] ??= ['emails' => [], 'orders' => []];
            $groups[$ip]['emails'][$email] = true;
            $id = $this->text($order['id'] ?? '');
            $groups[$ip]['orders'][] = ['id' => ctype_digit($id) ? $id : '', 'number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'currency' => strtoupper($this->text($order['currency'] ?? '')), 'fulfillment' => $this->text($order['fulfillment_status'] ?? '')];
        }
        $rows = [];
        foreach ($groups as $ip => $group) {
            if (count($group['emails']) < 2) {
                continue;
            }
            $rows[] = ['ip' => $ip, 'email_count' => count($group['emails']), 'order_count' => count($group['orders']), 'emails' => array_keys($group['emails']), 'orders' => $group['orders']];
        }
        usort($rows, fn (array $a, array $b): int => $b['email_count'] <=> $a['email_count'] ?: $b['order_count'] <=> $a['order_count']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
