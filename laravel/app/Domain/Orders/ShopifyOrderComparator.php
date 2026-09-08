<?php

namespace App\Domain\Orders;

class ShopifyOrderComparator
{
    /**
     * @param  array<string, mixed>|null  $orderA
     * @param  array<string, mixed>|null  $orderB
     * @return array{rows: list<array{label: string, a: string, b: string, different: bool}>, difference_count: int}
     */
    public function compare(?array $orderA, ?array $orderB, string $numberA, string $numberB, bool $shipStationConfigured, ?string $shipStationStatusA, ?string $shipStationStatusB): array
    {
        $rows = [
            $this->row('Order #', $orderA['name'] ?? '#'.$numberA, $orderB['name'] ?? '#'.$numberB),
            $this->row('Date', $this->date($orderA), $this->date($orderB)),
            $this->row('Email', $orderA['email'] ?? null, $orderB['email'] ?? null),
            $this->row('Financial status', $orderA['financial_status'] ?? null, $orderB['financial_status'] ?? null),
            $this->row('Fulfillment status', $orderA === null ? null : ($orderA['fulfillment_status'] ?? 'unfulfilled'), $orderB === null ? null : ($orderB['fulfillment_status'] ?? 'unfulfilled')),
            $this->row('Total', $this->total($orderA), $this->total($orderB)),
            $this->row('Items', $this->items($orderA), $this->items($orderB)),
            $this->row('Ship to', $this->address($orderA['shipping_address'] ?? null), $this->address($orderB['shipping_address'] ?? null)),
            $this->row('Tags', $this->tags($orderA), $this->tags($orderB)),
            $this->row('Note', $orderA['note'] ?? null, $orderB['note'] ?? null),
        ];

        if ($shipStationConfigured) {
            $rows[] = $this->row('ShipStation status', $shipStationStatusA, $shipStationStatusB);
        }

        return [
            'rows' => $rows,
            'difference_count' => count(array_filter($rows, fn (array $row): bool => $row['different'])),
        ];
    }

    /** @return array{label: string, a: string, b: string, different: bool} */
    private function row(string $label, mixed $a, mixed $b): array
    {
        $a = $this->display($a);
        $b = $this->display($b);

        return ['label' => $label, 'a' => $a, 'b' => $b, 'different' => $a !== $b];
    }

    /** @param array<string, mixed>|null $order */
    private function date(?array $order): ?string
    {
        return $order === null ? null : mb_substr((string) ($order['created_at'] ?? ''), 0, 10);
    }

    /** @param array<string, mixed>|null $order */
    private function total(?array $order): ?string
    {
        return $order === null ? null : '$'.number_format((float) ($order['total_price'] ?? 0), 2, '.', '');
    }

    /** @param array<string, mixed>|null $order */
    private function items(?array $order): ?string
    {
        if ($order === null) {
            return null;
        }

        $items = [];

        foreach ($order['line_items'] ?? [] as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $label = (int) ($lineItem['quantity'] ?? 1).'× '.(string) ($lineItem['title'] ?? '');
            $variant = trim((string) ($lineItem['variant_title'] ?? ''));
            $items[] = $variant === '' ? $label : $label.' ('.$variant.')';
        }

        return implode(', ', $items);
    }

    private function address(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        return implode(', ', array_filter([
            trim((string) ($address['first_name'] ?? '').' '.(string) ($address['last_name'] ?? '')),
            $address['address1'] ?? '',
            $address['address2'] ?? '',
            $address['city'] ?? '',
            $address['province_code'] ?? '',
            $address['zip'] ?? '',
            $address['country_code'] ?? '',
        ], fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''));
    }

    /** @param array<string, mixed>|null $order */
    private function tags(?array $order): ?string
    {
        if ($order === null) {
            return null;
        }

        $tags = $order['tags'] ?? [];

        if (is_array($tags)) {
            return implode(', ', array_map(strval(...), $tags));
        }

        return is_scalar($tags) ? (string) $tags : '';
    }

    private function display(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '—';
        }

        $value = trim((string) $value);

        return $value === '' ? '—' : $value;
    }
}
