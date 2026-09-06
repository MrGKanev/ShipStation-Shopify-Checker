<?php

namespace App\Domain\Orders;

class TrackingFeedBuilder
{
    /** @param list<array<string, mixed>> $orders @param list<array<string, mixed>> $shipments */
    public function build(string $number, array $orders, array $shipments): array
    {
        $order = $orders[0] ?? null;
        if ($order === null) {
            return ['number' => $number, 'found' => false, 'shipments' => []];
        }

        $orderId = $this->scalar($order['orderId'] ?? '');
        $status = $this->scalar($order['orderStatus'] ?? '');
        $rows = $shipments === [] ? [[]] : $shipments;

        return [
            'number' => $number,
            'found' => true,
            'shipments' => array_map(fn (array $shipment): array => $this->shipment(
                $shipment,
                $orderId,
                $status,
            ), $rows),
        ];
    }

    /** @param array<string, mixed> $shipment */
    private function shipment(array $shipment, string $fallbackOrderId, string $fallbackStatus): array
    {
        $carrier = $this->scalar($shipment['carrierCode'] ?? '');
        $tracking = $this->scalar($shipment['trackingNumber'] ?? '');
        $orderId = $this->scalar($shipment['orderId'] ?? $fallbackOrderId);
        $baseUrl = [
            'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
            'stamps_com' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
            'fedex' => 'https://www.fedex.com/fedextrack/?tracknumbers=',
            'ups' => 'https://www.ups.com/track?tracknum=',
            'dhl' => 'https://www.dhl.com/en/express/tracking.html?AWB=',
            'ontrac' => 'https://www.ontrac.com/tracking/?number=',
            'lasership' => 'https://www.lasership.com/track/',
        ][strtolower($carrier)] ?? null;

        return [
            'orderId' => $orderId,
            'orderStatus' => $this->scalar($shipment['orderStatus'] ?? $fallbackStatus),
            'carrierCode' => $carrier,
            'serviceCode' => $this->scalar($shipment['serviceCode'] ?? ''),
            'trackingNumber' => $tracking,
            'shipDate' => substr($this->scalar($shipment['shipDate'] ?? ''), 0, 10),
            'trackingUrl' => $baseUrl !== null && $tracking !== '' ? $baseUrl.urlencode($tracking) : null,
            'ssUrl' => ctype_digit($orderId) ? 'https://app.shipstation.com/#!/orders/order-details/'.$orderId : null,
        ];
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
