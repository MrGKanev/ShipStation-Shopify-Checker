<?php

namespace App\Domain\Reports;

class DiscountAbuseAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders, int $minimumEmails): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : null;
            $addressKey = $address === null ? '' : $this->addressKey($address);
            $discounts = is_array($order['discount_codes'] ?? null) ? $order['discount_codes'] : [];
            if ($addressKey === '' || $discounts === []) {
                continue;
            }
            foreach ($discounts as $discount) {
                $code = strtoupper($this->text(is_array($discount) ? ($discount['code'] ?? '') : ''));
                if ($code === '') {
                    continue;
                }
                $key = $code.'|'.$addressKey;
                $groups[$key] ??= ['code' => $code, 'address' => $address, 'emails' => [], 'orders' => [], 'total' => 0.0];
                $email = mb_strtolower($this->text($order['email'] ?? ''));
                if ($email !== '') {
                    $groups[$key]['emails'][$email] = true;
                }
                $total = is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0;
                $id = $this->text($order['id'] ?? '');
                $groups[$key]['total'] += $total;
                $groups[$key]['orders'][] = ['id' => ctype_digit($id) ? $id : '', 'number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'total' => $total, 'currency' => strtoupper($this->text($order['currency'] ?? '')), 'financial' => $this->text($order['financial_status'] ?? ''), 'fulfillment' => $this->text($order['fulfillment_status'] ?? '')];
            }
        }
        $rows = [];
        foreach ($groups as $group) {
            if (count($group['emails']) < $minimumEmails) {
                continue;
            }
            $address = $group['address'];
            $rows[] = ['code' => $group['code'], 'address_line' => $this->addressLine($address), 'address_name' => trim($this->text($address['first_name'] ?? '').' '.$this->text($address['last_name'] ?? '')), 'email_count' => count($group['emails']), 'order_count' => count($group['orders']), 'emails' => array_keys($group['emails']), 'orders' => $group['orders'], 'total' => $group['total']];
        }
        usort($rows, fn (array $a, array $b): int => $b['email_count'] <=> $a['email_count'] ?: $b['order_count'] <=> $a['order_count']);

        return $rows;
    }

    /** @param array<string, mixed> $address */
    private function addressKey(array $address): string
    {
        return mb_strtolower(implode('|', array_filter([$this->text($address['address1'] ?? ''), $this->text($address['city'] ?? ''), $this->text($address['zip'] ?? ''), $this->text($address['country_code'] ?? $address['country'] ?? '')])));
    }

    /** @param array<string, mixed> $address */
    private function addressLine(array $address): string
    {
        return implode(', ', array_filter([$this->text($address['address1'] ?? ''), $this->text($address['city'] ?? ''), $this->text($address['province_code'] ?? ''), $this->text($address['zip'] ?? ''), $this->text($address['country_code'] ?? '')]));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
