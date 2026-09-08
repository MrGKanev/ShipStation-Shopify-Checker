<?php

namespace App\Integrations\ShipStation;

use UnexpectedValueException;

class ShipStationOrderNormalizer
{
    /**
     * @param  array<string, mixed>  $order
     * @param  array<mixed>  $shipments
     * @return array{
     *     id: int|string|null,
     *     order_number: string,
     *     status: string,
     *     created_at: string|null,
     *     customer_email: string|null,
     *     total: string|null,
     *     shipping_address: array{name: string|null, company: string|null, street_1: string|null, street_2: string|null, city: string|null, state: string|null, postal_code: string|null, country: string|null, phone: string|null},
     *     billing_address: array{name: string|null, company: string|null, street_1: string|null, street_2: string|null, city: string|null, state: string|null, postal_code: string|null, country: string|null, phone: string|null},
     *     items: list<array{id: int|string|null, line_item_key: string|null, sku: string|null, name: string, quantity: int}>,
     *     sku_quantities: array<string, int>,
     *     shipments: list<array{id: int|string|null, order_id: int|string|null, carrier_code: string|null, service_code: string|null, tracking_number: string|null, ship_date: string|null, delivery_date: string|null, voided: bool}>,
     *     fulfillment: array{is_shipped: bool, shipment_count: int, active_shipment_count: int, tracking_numbers: list<string>}
     * }
     */
    public function normalize(array $order, array $shipments = []): array
    {
        $status = $this->status($order['orderStatus'] ?? null);
        $normalizedItems = $this->items($order['items'] ?? null);
        $normalizedShipments = $this->shipments($shipments);

        return [
            'id' => $this->id($order['orderId'] ?? null, 'orderId'),
            'order_number' => $this->string($order['orderNumber'] ?? null, 'orderNumber') ?? '',
            'status' => $status,
            'created_at' => $this->string($order['createDate'] ?? $order['orderDate'] ?? null, 'createDate'),
            'customer_email' => $this->string($order['customerEmail'] ?? null, 'customerEmail'),
            'total' => $this->money($order['orderTotal'] ?? $order['amountPaid'] ?? null),
            'shipping_address' => $this->address($order['shipTo'] ?? null, 'shipTo'),
            'billing_address' => $this->address($order['billTo'] ?? null, 'billTo'),
            'items' => $normalizedItems,
            'sku_quantities' => $this->skuQuantities($normalizedItems),
            'shipments' => $normalizedShipments,
            'fulfillment' => $this->fulfillment($status, $normalizedShipments),
        ];
    }

    /**
     * @return array{name: string|null, company: string|null, street_1: string|null, street_2: string|null, city: string|null, state: string|null, postal_code: string|null, country: string|null, phone: string|null}
     */
    private function address(mixed $address, string $field): array
    {
        if ($address === null) {
            $address = [];
        }

        if (! is_array($address)) {
            throw new UnexpectedValueException("ShipStation returned an invalid {$field} address.");
        }

        return [
            'name' => $this->string($address['name'] ?? null, "{$field}.name"),
            'company' => $this->string($address['company'] ?? null, "{$field}.company"),
            'street_1' => $this->string($address['street1'] ?? null, "{$field}.street1"),
            'street_2' => $this->string($address['street2'] ?? null, "{$field}.street2"),
            'city' => $this->string($address['city'] ?? null, "{$field}.city"),
            'state' => $this->string($address['state'] ?? null, "{$field}.state"),
            'postal_code' => $this->string($address['postalCode'] ?? null, "{$field}.postalCode"),
            'country' => $this->string($address['country'] ?? null, "{$field}.country"),
            'phone' => $this->string($address['phone'] ?? null, "{$field}.phone"),
        ];
    }

    /**
     * @return list<array{id: int|string|null, line_item_key: string|null, sku: string|null, name: string, quantity: int}>
     */
    private function items(mixed $items): array
    {
        if ($items === null) {
            return [];
        }

        if (! is_array($items) || ! array_is_list($items)) {
            throw new UnexpectedValueException('ShipStation returned an invalid items collection.');
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new UnexpectedValueException("ShipStation returned an invalid item at index {$index}.");
            }

            $normalized[] = [
                'id' => $this->id($item['orderItemId'] ?? null, "items.{$index}.orderItemId"),
                'line_item_key' => $this->string($item['lineItemKey'] ?? null, "items.{$index}.lineItemKey"),
                'sku' => $this->string($item['sku'] ?? null, "items.{$index}.sku"),
                'name' => $this->string($item['name'] ?? null, "items.{$index}.name") ?? '',
                'quantity' => $this->quantity($item['quantity'] ?? null, $index),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{id: int|string|null, line_item_key: string|null, sku: string|null, name: string, quantity: int}>  $items
     * @return array<string, int>
     */
    private function skuQuantities(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            if ($item['sku'] === null) {
                continue;
            }

            $sku = mb_strtolower($item['sku']);
            $quantities[$sku] = ($quantities[$sku] ?? 0) + $item['quantity'];
        }

        ksort($quantities);

        return $quantities;
    }

    /**
     * @return list<array{id: int|string|null, order_id: int|string|null, carrier_code: string|null, service_code: string|null, tracking_number: string|null, ship_date: string|null, delivery_date: string|null, voided: bool}>
     */
    private function shipments(mixed $shipments): array
    {
        if (! is_array($shipments) || ! array_is_list($shipments)) {
            throw new UnexpectedValueException('ShipStation returned an invalid shipments collection.');
        }

        $normalized = [];
        $shipmentIndexesById = [];

        foreach ($shipments as $index => $shipment) {
            if (! is_array($shipment)) {
                throw new UnexpectedValueException("ShipStation returned an invalid shipment at index {$index}.");
            }

            $normalizedShipment = [
                'id' => $this->id($shipment['shipmentId'] ?? null, "shipments.{$index}.shipmentId"),
                'order_id' => $this->id($shipment['orderId'] ?? null, "shipments.{$index}.orderId"),
                'carrier_code' => $this->string($shipment['carrierCode'] ?? null, "shipments.{$index}.carrierCode"),
                'service_code' => $this->string($shipment['serviceCode'] ?? null, "shipments.{$index}.serviceCode"),
                'tracking_number' => $this->string($shipment['trackingNumber'] ?? null, "shipments.{$index}.trackingNumber"),
                'ship_date' => $this->string($shipment['shipDate'] ?? null, "shipments.{$index}.shipDate"),
                'delivery_date' => $this->string($shipment['deliveryDate'] ?? null, "shipments.{$index}.deliveryDate"),
                'voided' => $this->boolean($shipment['voided'] ?? null, "shipments.{$index}.voided"),
            ];

            $shipmentId = $normalizedShipment['id'];

            if ($shipmentId === null) {
                $normalized[] = $normalizedShipment;

                continue;
            }

            $shipmentKey = get_debug_type($shipmentId).':'.$shipmentId;

            if (! array_key_exists($shipmentKey, $shipmentIndexesById)) {
                $shipmentIndexesById[$shipmentKey] = count($normalized);
                $normalized[] = $normalizedShipment;

                continue;
            }

            if ($normalized[$shipmentIndexesById[$shipmentKey]] !== $normalizedShipment) {
                throw new UnexpectedValueException("ShipStation returned conflicting shipment records for ID {$shipmentId}.");
            }
        }

        return $normalized;
    }

    /**
     * @param  list<array{id: int|string|null, order_id: int|string|null, carrier_code: string|null, service_code: string|null, tracking_number: string|null, ship_date: string|null, delivery_date: string|null, voided: bool}>  $shipments
     * @return array{is_shipped: bool, shipment_count: int, active_shipment_count: int, tracking_numbers: list<string>}
     */
    private function fulfillment(string $status, array $shipments): array
    {
        $activeShipmentCount = 0;
        $trackingNumbers = [];

        foreach ($shipments as $shipment) {
            if ($shipment['voided']) {
                continue;
            }

            $activeShipmentCount++;

            if ($shipment['tracking_number'] !== null && ! in_array($shipment['tracking_number'], $trackingNumbers, true)) {
                $trackingNumbers[] = $shipment['tracking_number'];
            }
        }

        return [
            'is_shipped' => $status === 'shipped',
            'shipment_count' => count($shipments),
            'active_shipment_count' => $activeShipmentCount,
            'tracking_numbers' => $trackingNumbers,
        ];
    }

    private function status(mixed $status): string
    {
        $normalized = $this->string($status, 'orderStatus');

        if ($normalized === null) {
            return 'unknown';
        }

        return preg_replace('/[\s-]+/u', '_', mb_strtolower($normalized)) ?? 'unknown';
    }

    private function id(mixed $id, string $field): int|string|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (is_int($id)) {
            return $id;
        }

        if (! is_string($id)) {
            throw new UnexpectedValueException("ShipStation returned an invalid {$field} value.");
        }

        $id = trim($id);

        if ($id === '') {
            return null;
        }

        return ctype_digit($id) ? (int) $id : $id;
    }

    private function string(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new UnexpectedValueException("ShipStation returned an invalid {$field} value.");
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function quantity(mixed $quantity, int $index): int
    {
        if ($quantity === null || $quantity === '') {
            return 1;
        }

        if (! is_int($quantity) && (! is_string($quantity) || preg_match('/\A[0-9]+\z/', $quantity) !== 1)) {
            throw new UnexpectedValueException("ShipStation returned an invalid item quantity at index {$index}.");
        }

        $quantity = (int) $quantity;

        if ($quantity < 0) {
            throw new UnexpectedValueException("ShipStation returned an invalid item quantity at index {$index}.");
        }

        return $quantity;
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && (! is_string($value) || ! is_numeric($value))) {
            throw new UnexpectedValueException('ShipStation returned an invalid orderTotal value.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function boolean(mixed $value, string $field): bool
    {
        return match ($value) {
            null, false, 0, '0', 'false' => false,
            true, 1, '1', 'true' => true,
            default => throw new UnexpectedValueException("ShipStation returned an invalid {$field} value."),
        };
    }
}
