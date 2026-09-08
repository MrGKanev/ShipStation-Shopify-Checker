<?php

namespace App\Domain\Reports;

class DuplicateAddressAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : null;
            $email = mb_strtolower($this->text($order['email'] ?? ''));
            if ($address === null || $email === '') {
                continue;
            }
            $parts = [$this->text($address['address1'] ?? ''), $this->text($address['city'] ?? ''), $this->text($address['zip'] ?? ''), $this->text($address['country_code'] ?? $address['country'] ?? '')];
            $key = mb_strtolower(implode('|', $parts));
            if ($key === '|||') {
                continue;
            }
            $groups[$key] ??= ['address' => $address, 'emails' => [], 'orders' => []];
            $groups[$key]['emails'][$email] = true;
            $id = $this->text($order['id'] ?? '');
            $groups[$key]['orders'][] = ['shopify_id' => ctype_digit($id) ? $id : '', 'order_number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'currency' => strtoupper($this->text($order['currency'] ?? '')), 'fulfillment' => $this->text($order['fulfillment_status'] ?? '')];
        }
        $rows = [];
        foreach ($groups as $group) {
            if (count($group['emails']) < 2) {
                continue;
            }
            $address = $group['address'];
            $rows[] = ['address_line' => implode(', ', array_filter([$this->text($address['address1'] ?? ''), $this->text($address['city'] ?? ''), $this->text($address['province_code'] ?? ''), $this->text($address['zip'] ?? ''), $this->text($address['country_code'] ?? '')])), 'address_name' => trim($this->text($address['first_name'] ?? '').' '.$this->text($address['last_name'] ?? '')), 'email_count' => count($group['emails']), 'order_count' => count($group['orders']), 'emails' => array_keys($group['emails']), 'orders' => $group['orders']];
        }
        usort($rows, fn (array $a, array $b): int => $b['email_count'] <=> $a['email_count'] ?: $b['order_count'] <=> $a['order_count']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
