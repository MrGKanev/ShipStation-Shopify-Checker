<?php

namespace App\Domain\Reports;

class AddressChangeAnalyzer
{
    /** @param list<array<string, mixed>> $events @return array<string, string> */
    public function latestChanges(array $events): array
    {
        $changes = [];
        foreach ($events as $event) {
            if (! $this->isAddressChange($event)) {
                continue;
            }
            $id = $this->text($event['subject_id'] ?? '');
            $changedAt = $this->text($event['created_at'] ?? '');
            if ($id !== '' && (! isset($changes[$id]) || $changedAt > $changes[$id])) {
                $changes[$id] = $changedAt;
            }
        }

        return $changes;
    }

    /** @param array<string, array<string, mixed>> $orders @param array<string, string> $changes @return list<array<string, mixed>> */
    public function rows(array $orders, array $changes): array
    {
        $rows = [];
        foreach ($orders as $id => $order) {
            if (! isset($changes[$id])) {
                continue;
            }
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];
            $createdAt = $this->text($order['created_at'] ?? '');
            $changedAt = $changes[$id];
            $createdTimestamp = strtotime($createdAt);
            $changedTimestamp = strtotime($changedAt);
            $rows[] = [
                'shopify_id' => ctype_digit((string) $id) ? (string) $id : '', 'order_number' => $this->text($order['name'] ?? ''),
                'created_at' => substr($createdAt, 0, 10), 'changed_at' => substr($changedAt, 0, 16),
                'gap_mins' => $createdTimestamp !== false && $changedTimestamp !== false ? max(0, (int) (($changedTimestamp - $createdTimestamp) / 60)) : 0,
                'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0,
                'financial' => $this->text($order['financial_status'] ?? ''), 'fulfillment' => $this->text($order['fulfillment_status'] ?? ''),
                'addr_name' => trim($this->text($address['first_name'] ?? '').' '.$this->text($address['last_name'] ?? '')),
                'addr_line' => implode(', ', array_filter([$this->text($address['address1'] ?? ''), $this->text($address['city'] ?? ''), $this->text($address['province_code'] ?? ''), $this->text($address['zip'] ?? ''), $this->text($address['country_code'] ?? '')])),
            ];
        }
        usort($rows, fn (array $left, array $right): int => strcmp($right['changed_at'], $left['changed_at']));

        return $rows;
    }

    /** @param array<string, mixed> $event */
    private function isAddressChange(array $event): bool
    {
        $haystack = mb_strtolower(trim($this->text($event['verb'] ?? '').' '.$this->text($event['action'] ?? '').' '.$this->text($event['message'] ?? '')));

        return str_contains($haystack, 'shipping address') || str_contains($haystack, 'address was') || str_contains($haystack, 'shipping_address');
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
