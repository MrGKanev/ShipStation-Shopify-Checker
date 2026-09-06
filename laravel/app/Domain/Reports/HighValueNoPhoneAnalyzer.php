<?php

namespace App\Domain\Reports;

class HighValueNoPhoneAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders, float $minimum, string $currency): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];
            $phone = $this->text($address['phone'] ?? '');
            $total = is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0;
            $orderCurrency = strtoupper($this->text($order['currency'] ?? ''));

            if ($phone !== '' || $total < $minimum || $orderCurrency !== $currency || $this->text($order['cancelled_at'] ?? '') !== '') {
                continue;
            }

            $id = $this->text($order['id'] ?? '');
            $rows[] = [
                'id' => ctype_digit($id) ? $id : '',
                'number' => $this->text($order['name'] ?? ''),
                'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10),
                'email' => $this->text($order['email'] ?? ''),
                'total' => $total,
                'currency' => $orderCurrency,
                'recipient' => trim($this->text($address['first_name'] ?? '').' '.$this->text($address['last_name'] ?? '')),
                'address' => implode(', ', array_filter(array_map(fn (string $key): string => $this->text($address[$key] ?? ''), ['address1', 'address2', 'city', 'province', 'zip', 'country']))),
            ];
        }

        usort($rows, fn (array $left, array $right): int => [$right['total'], $right['created_at'], $right['number']] <=> [$left['total'], $left['created_at'], $left['number']]);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
