<?php

namespace App\Domain\Orders;

use Carbon\CarbonImmutable;

class PackingSlipBuilder
{
    /** @param array<string, mixed> $order @return array<string, mixed> */
    public function build(array $order): array
    {
        $shipTo = is_array($order['shipTo'] ?? null) ? $order['shipTo'] : [];
        $advanced = is_array($order['advancedOptions'] ?? null) ? $order['advancedOptions'] : [];
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];

        return [
            'orderNumber' => $this->text($order['orderNumber'] ?? ''),
            'orderDate' => $this->date($order['orderDate'] ?? null),
            'shipByDate' => $this->date($order['shipByDate'] ?? null),
            'customerUsername' => $this->text($order['customerUsername'] ?? ''),
            'shipTo' => collect(['name', 'company', 'street1', 'street2', 'city', 'state', 'postalCode', 'country'])->mapWithKeys(fn (string $key): array => [$key => $this->text($shipTo[$key] ?? '')])->all(),
            'items' => collect($items)->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): array => [
                'name' => $this->text($item['name'] ?? ''),
                'quantity' => is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0,
                'options' => $this->options($item['options'] ?? []),
            ])->values()->all(),
            'notes' => collect([$order['internalNotes'] ?? null, $order['customerNotes'] ?? null, $advanced['customField1'] ?? null, $advanced['customField2'] ?? null, $advanced['customField3'] ?? null])
                ->flatMap(fn (mixed $note): array => preg_split('/<br\s*\/?>/i', $this->text($note), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                ->map(fn (string $note): string => trim($note))->filter()->values()->all(),
        ];
    }

    /** @return list<array{name: string, value: string, highlighted: bool}> */
    private function options(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }
        $hidden = ['has gpo', 'gpo product group', 'gpo parent product group', 'gpo field name', 'gpo options', 'gpo addon products', 'fulfillment_status'];

        return collect($options)->filter(fn (mixed $option): bool => is_array($option))
            ->reject(fn (array $option): bool => in_array(strtolower(trim($this->text($option['name'] ?? ''))), $hidden, true))
            ->map(function (array $option): array {
                $value = $this->text($option['value'] ?? '');
                $decoded = json_decode($value, true);
                $isList = is_array($decoded) && array_is_list($decoded) && collect($decoded)->every(fn (mixed $entry): bool => is_scalar($entry) || $entry === null);

                return ['name' => $this->text($option['name'] ?? ''), 'value' => $isList ? collect($decoded)->map(fn (mixed $entry): string => $this->text($entry))->implode(', ') : $value, 'highlighted' => $isList];
            })->values()->all();
    }

    private function date(mixed $value): string
    {
        try {
            $text = $this->text($value);

            return $text === '' ? '' : CarbonImmutable::parse($text)->format('n/j/Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
