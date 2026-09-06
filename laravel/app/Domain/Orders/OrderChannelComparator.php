<?php

namespace App\Domain\Orders;

class OrderChannelComparator
{
    /**
     * @param  array<string, mixed>  $shopifyOrder
     * @param  array<string, mixed>  $shipStationOrder
     * @return array{
     *     fields: list<array{key: string, label: string, shopify: string, shipstation: string, state: string}>,
     *     items: array{shopify: array<string, int>, shipstation: array<string, int>, missing: array<string, int>, extra: array<string, int>, state: string},
     *     warnings: list<array{code: string, severity: string, message: string}>
     * }
     */
    public function compare(array $shopifyOrder, array $shipStationOrder): array
    {
        $shopifyAddress = $this->shopifyAddress($shopifyOrder['shipping_address'] ?? null);
        $shipStationAddress = $this->shipStationAddress($shipStationOrder['shipping_address'] ?? null);

        $fields = [
            $this->field('customer_email', 'Customer email', $shopifyOrder['email'] ?? null, $shipStationOrder['customer_email'] ?? null),
            $this->moneyField('total', 'Order total', $shopifyOrder['total_price'] ?? null, $shipStationOrder['total'] ?? null),
        ];

        foreach ($this->addressLabels() as $key => $label) {
            $fields[] = $this->field('shipping_'.$key, 'Shipping '.$label, $shopifyAddress[$key], $shipStationAddress[$key]);
        }

        $fields[] = [
            'key' => 'fulfillment_status',
            'label' => 'Fulfillment / order status',
            'shopify' => $this->display($shopifyOrder['fulfillment_status'] ?? 'unfulfilled'),
            'shipstation' => $this->display($shipStationOrder['status'] ?? null),
            'state' => 'review',
        ];

        $shopifyItems = $this->skuQuantities($shopifyOrder['line_items'] ?? []);
        $shipStationItems = $this->skuQuantities($shipStationOrder['items'] ?? []);
        $missing = $this->positiveDifference($shopifyItems, $shipStationItems);
        $extra = $this->positiveDifference($shipStationItems, $shopifyItems);

        return [
            'fields' => $fields,
            'items' => [
                'shopify' => $shopifyItems,
                'shipstation' => $shipStationItems,
                'missing' => $missing,
                'extra' => $extra,
                'state' => $missing === [] && $extra === [] ? 'match' : 'different',
            ],
            'warnings' => $this->warnings($shopifyOrder, $shipStationOrder),
        ];
    }

    /** @return array<string, string> */
    private function addressLabels(): array
    {
        return [
            'name' => 'name',
            'company' => 'company',
            'street_1' => 'address line 1',
            'street_2' => 'address line 2',
            'city' => 'city',
            'state' => 'state/province',
            'postal_code' => 'postal code',
            'country' => 'country',
            'phone' => 'phone',
        ];
    }

    /** @return array<string, string> */
    private function shopifyAddress(mixed $address): array
    {
        $address = is_array($address) ? $address : [];
        $firstAndLastName = trim(implode(' ', array_filter([
            $address['first_name'] ?? null,
            $address['last_name'] ?? null,
        ], fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')));

        return [
            'name' => $this->display($firstAndLastName !== '' ? $firstAndLastName : ($address['name'] ?? null)),
            'company' => $this->display($address['company'] ?? null),
            'street_1' => $this->display($address['address1'] ?? null),
            'street_2' => $this->display($address['address2'] ?? null),
            'city' => $this->display($address['city'] ?? null),
            'state' => $this->display($address['province_code'] ?? $address['province'] ?? null),
            'postal_code' => $this->display($address['zip'] ?? null),
            'country' => $this->display($address['country_code'] ?? $address['country'] ?? null),
            'phone' => $this->display($address['phone'] ?? null),
        ];
    }

    /** @return array<string, string> */
    private function shipStationAddress(mixed $address): array
    {
        $address = is_array($address) ? $address : [];

        return [
            'name' => $this->display($address['name'] ?? null),
            'company' => $this->display($address['company'] ?? null),
            'street_1' => $this->display($address['street_1'] ?? null),
            'street_2' => $this->display($address['street_2'] ?? null),
            'city' => $this->display($address['city'] ?? null),
            'state' => $this->display($address['state'] ?? null),
            'postal_code' => $this->display($address['postal_code'] ?? null),
            'country' => $this->display($address['country'] ?? null),
            'phone' => $this->display($address['phone'] ?? null),
        ];
    }

    /** @return array{key: string, label: string, shopify: string, shipstation: string, state: string} */
    private function field(string $key, string $label, mixed $shopify, mixed $shipStation): array
    {
        $shopify = $this->display($shopify);
        $shipStation = $this->display($shipStation);

        return [
            'key' => $key,
            'label' => $label,
            'shopify' => $shopify,
            'shipstation' => $shipStation,
            'state' => $this->state($shopify, $shipStation),
        ];
    }

    /** @return array{key: string, label: string, shopify: string, shipstation: string, state: string} */
    private function moneyField(string $key, string $label, mixed $shopify, mixed $shipStation): array
    {
        $shopify = is_numeric($shopify) ? number_format((float) $shopify, 2, '.', '') : $this->display($shopify);
        $shipStation = is_numeric($shipStation) ? number_format((float) $shipStation, 2, '.', '') : $this->display($shipStation);

        return [
            'key' => $key,
            'label' => $label,
            'shopify' => $shopify,
            'shipstation' => $shipStation,
            'state' => $this->state($shopify, $shipStation),
        ];
    }

    private function state(string $shopify, string $shipStation): string
    {
        if ($shopify === '—' xor $shipStation === '—') {
            return 'missing';
        }

        return $this->canonical($shopify) === $this->canonical($shipStation) ? 'match' : 'different';
    }

    private function display(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '—';
        }

        $display = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $display === '' ? '—' : $display;
    }

    private function canonical(string $value): string
    {
        return mb_strtolower($this->display($value));
    }

    /** @return array<string, int> */
    private function skuQuantities(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $quantities = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = mb_strtolower(trim((string) ($item['sku'] ?? '')));

            if ($sku === '') {
                continue;
            }

            $quantities[$sku] = ($quantities[$sku] ?? 0) + (int) ($item['quantity'] ?? 1);
        }

        ksort($quantities);

        return $quantities;
    }

    /**
     * @param  array<string, int>  $expected
     * @param  array<string, int>  $actual
     * @return array<string, int>
     */
    private function positiveDifference(array $expected, array $actual): array
    {
        $difference = [];

        foreach ($expected as $sku => $quantity) {
            $delta = $quantity - ($actual[$sku] ?? 0);

            if ($delta > 0) {
                $difference[$sku] = $delta;
            }
        }

        return $difference;
    }

    /**
     * @param  array<string, mixed>  $shopifyOrder
     * @param  array<string, mixed>  $shipStationOrder
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function warnings(array $shopifyOrder, array $shipStationOrder): array
    {
        $warnings = [];
        $shopifyFulfillment = (string) ($shopifyOrder['fulfillment_status'] ?? 'unfulfilled');
        $shopifyFinancial = (string) ($shopifyOrder['financial_status'] ?? '');
        $shipStationStatus = (string) ($shipStationOrder['status'] ?? '');

        if ($shipStationStatus === 'shipped' && $shopifyFulfillment !== 'fulfilled') {
            $warnings[] = [
                'code' => 'shipstation_shipped_shopify_unfulfilled',
                'severity' => 'danger',
                'message' => 'ShipStation is shipped while Shopify is not fully fulfilled.',
            ];
        }

        if (in_array($shopifyFinancial, ['refunded', 'partially_refunded'], true)
            && in_array($shipStationStatus, ['awaiting_payment', 'awaiting_shipment', 'on_hold'], true)) {
            $warnings[] = [
                'code' => 'shopify_refunded_shipstation_active',
                'severity' => 'danger',
                'message' => 'Shopify is refunded while ShipStation still has an active order.',
            ];
        }

        return $warnings;
    }
}
