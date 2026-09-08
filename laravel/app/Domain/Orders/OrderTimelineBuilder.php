<?php

namespace App\Domain\Orders;

use DateTimeImmutable;
use Throwable;

class OrderTimelineBuilder
{
    /**
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $shipStationOrders
     * @param  list<array<string, mixed>>  $shipStationShipments
     * @return list<array{timestamp: string, formatted_at: string, type: string, source: string, title: string, detail: string, tracking: string, url: string}>
     */
    public function build(array $order, array $events, array $shipStationOrders, array $shipStationShipments): array
    {
        $items = [];
        $sequence = 0;
        $append = function (mixed $timestamp, string $type, string $source, string $title, string $detail = '', string $tracking = '', string $url = '') use (&$items, &$sequence): void {
            if (! is_scalar($timestamp) || trim((string) $timestamp) === '' || strtotime((string) $timestamp) === false) {
                return;
            }

            $items[] = [
                'timestamp' => trim((string) $timestamp),
                'formatted_at' => $this->formatTimestamp((string) $timestamp),
                'type' => $type,
                'source' => $source,
                'title' => $title,
                'detail' => $detail,
                'tracking' => $tracking,
                'url' => $this->safeUrl($url),
                '_sequence' => $sequence++,
            ];
        };

        $append($order['created_at'] ?? null, 'order_placed', 'shopify', 'Order placed', trim((string) ($order['email'] ?? '')));

        if (in_array($order['financial_status'] ?? '', ['paid', 'partially_paid'], true)) {
            $append(
                $order['processed_at'] ?? null,
                'payment',
                'shopify',
                'Payment captured',
                $this->formatMoney((float) ($order['total_price'] ?? 0), (string) ($order['currency'] ?? 'USD')),
            );
        }

        foreach ($order['fulfillments'] ?? [] as $fulfillment) {
            if (! is_array($fulfillment)) {
                continue;
            }

            $lineItems = is_array($fulfillment['line_items'] ?? null) ? $fulfillment['line_items'] : [];
            $itemCount = array_sum(array_map(
                fn (mixed $lineItem): int => is_array($lineItem) ? max(0, (int) ($lineItem['quantity'] ?? 1)) : 0,
                $lineItems,
            ));
            $carrier = trim((string) ($fulfillment['tracking_company'] ?? ''));
            $tracking = trim((string) ($fulfillment['tracking_number'] ?? ''));
            $detail = $itemCount.' item'.($itemCount === 1 ? '' : 's');

            if ($carrier !== '') {
                $detail .= ' · '.$carrier;
            }

            $append(
                $fulfillment['created_at'] ?? null,
                'fulfillment',
                'shopify',
                'Fulfillment created',
                $detail,
                $tracking,
                $tracking === '' ? '' : (string) ($fulfillment['tracking_url'] ?? ''),
            );
        }

        foreach ($order['refunds'] ?? [] as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $amount = 0.0;

            foreach ($refund['transactions'] ?? [] as $transaction) {
                if (is_array($transaction)
                    && ($transaction['kind'] ?? '') === 'refund'
                    && ($transaction['status'] ?? '') === 'success') {
                    $amount += (float) ($transaction['amount'] ?? 0);
                }
            }

            $details = [];

            if ($amount > 0) {
                $details[] = $this->formatMoney($amount, (string) ($order['currency'] ?? 'USD'));
            }

            if (is_scalar($refund['note'] ?? null) && trim((string) $refund['note']) !== '') {
                $details[] = trim((string) $refund['note']);
            }

            $append($refund['created_at'] ?? null, 'refund', 'shopify', 'Refund processed', implode(' · ', $details));
        }

        $reason = trim((string) ($order['cancel_reason'] ?? ''));
        $append(
            $order['cancelled_at'] ?? null,
            'cancelled',
            'shopify',
            'Order cancelled',
            $reason === '' ? '' : ucfirst(str_replace('_', ' ', $reason)),
        );
        $append($order['closed_at'] ?? null, 'closed', 'shopify', 'Order closed');

        $skippedVerbs = ['placed', 'confirmed', 'fulfillment_created', 'fulfillment_success', 'fulfillment_shipped', 'closed', 'cancelled'];

        foreach ($events as $event) {
            $verb = (string) ($event['verb'] ?? '');

            if (in_array($verb, $skippedVerbs, true)) {
                continue;
            }

            $title = trim((string) ($event['message'] ?? ''));
            $append(
                $event['created_at'] ?? null,
                'shopify_event',
                'shopify',
                $title !== '' ? $title : ucfirst(str_replace('_', ' ', $verb)),
            );
        }

        foreach ($shipStationOrders as $shipStationOrder) {
            $orderId = $shipStationOrder['orderId'] ?? null;
            $status = trim((string) ($shipStationOrder['orderStatus'] ?? 'unknown'));
            $createdAt = is_scalar($shipStationOrder['createDate'] ?? null)
                && trim((string) $shipStationOrder['createDate']) !== ''
                    ? $shipStationOrder['createDate']
                    : ($shipStationOrder['orderDate'] ?? null);
            $append(
                $createdAt,
                'shipstation_order',
                'shipstation',
                'ShipStation: '.ucfirst(str_replace('_', ' ', $status)),
                is_scalar($orderId) && (string) $orderId !== '' ? 'SS ID '.$orderId : '',
                '',
                is_scalar($orderId) && (string) $orderId !== ''
                    ? 'https://app.shipstation.com/#!/orders/order-details/'.rawurlencode((string) $orderId)
                    : '',
            );
        }

        foreach ($shipStationShipments as $shipment) {
            $carrier = mb_strtoupper(trim((string) ($shipment['carrierCode'] ?? '')));
            $tracking = trim((string) ($shipment['trackingNumber'] ?? ''));
            $append(
                $shipment['shipDate'] ?? null,
                'shipstation_shipment',
                'shipstation',
                'Shipped via ShipStation',
                implode(' · ', array_filter([$carrier, $tracking], fn (string $value): bool => $value !== '')),
                $tracking,
            );
        }

        usort($items, function (array $a, array $b): int {
            $timestampOrder = strtotime($b['timestamp']) <=> strtotime($a['timestamp']);

            return $timestampOrder !== 0 ? $timestampOrder : $a['_sequence'] <=> $b['_sequence'];
        });

        return array_map(function (array $item): array {
            unset($item['_sequence']);

            return $item;
        }, $items);
    }

    private function formatTimestamp(string $timestamp): string
    {
        try {
            return (new DateTimeImmutable($timestamp))->format('Y-m-d H:i');
        } catch (Throwable) {
            return '';
        }
    }

    private function safeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(mb_strtolower((string) $scheme), ['http', 'https'], true) ? $url : '';
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $currency = mb_strtoupper(trim($currency));
        $formattedAmount = number_format($amount, 2, '.', '');

        return match ($currency) {
            '', 'USD' => '$'.$formattedAmount,
            'EUR' => '€'.$formattedAmount,
            'GBP' => '£'.$formattedAmount,
            default => $formattedAmount.' '.$currency,
        };
    }
}
