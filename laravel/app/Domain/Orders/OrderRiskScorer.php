<?php

namespace App\Domain\Orders;

class OrderRiskScorer
{
    /** @var array<string, int> */
    private const array Weights = [
        'Disposable/invalid email' => 30,
        'Billing ≠ shipping country' => 25,
        'Missing phone on high-value order' => 15,
        'PO Box address' => 10,
        'Partially paid' => 10,
        'Fraud/high-risk tag' => 35,
        'Shopify HIGH risk level' => 40,
        'No shipping address' => 20,
    ];

    /** @var list<string> */
    private const array DisposableEmailDomains = [
        'mailinator.com',
        'guerrillamail.com',
        'tempmail.com',
        'throwam.com',
        'yopmail.com',
        'sharklasers.com',
        'guerrillamailblock.com',
        'grr.la',
        'guerrillamail.info',
        'trashmail.com',
        'trashmail.net',
        'trashmail.org',
        'dispostable.com',
        'maildrop.cc',
        'spamgourmet.com',
        'spamgourmet.net',
        'mailnull.com',
        'spamcorner.com',
        '10minutemail.com',
        '10minutemail.net',
        'fakeinbox.com',
        'mailnesia.com',
        'discard.email',
        'spamspot.com',
        'mytemp.email',
        'temp-mail.org',
        'getnada.com',
        'tempr.email',
    ];

    /**
     * @param  array<string, mixed>  $order
     * @return array{score: int, level: 'low'|'medium'|'high', signals: list<string>}
     */
    public function score(array $order): array
    {
        $signals = [];
        $shippingAddress = is_array($order['shipping_address'] ?? null)
            ? $order['shipping_address']
            : null;
        $email = mb_strtolower(trim((string) ($order['email'] ?? '')));

        if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || $this->isDisposableEmail($email))) {
            $signals[] = 'Disposable/invalid email';
        }

        $billingAddress = is_array($order['billing_address'] ?? null)
            ? $order['billing_address']
            : null;

        if ($billingAddress !== null && $shippingAddress !== null) {
            $billingCountry = $this->country($billingAddress);
            $shippingCountry = $this->country($shippingAddress);

            if ($billingCountry !== '' && $shippingCountry !== '' && $billingCountry !== $shippingCountry) {
                $signals[] = 'Billing ≠ shipping country';
            }
        }

        $total = (float) ($order['total_price'] ?? $order['totalPriceSet']['shopMoney']['amount'] ?? 0);
        $phone = $shippingAddress === null ? '' : trim((string) ($shippingAddress['phone'] ?? ''));

        if ($total > 200 && $phone === '') {
            $signals[] = 'Missing phone on high-value order';
        }

        if ($shippingAddress !== null) {
            $addressLine = (string) ($shippingAddress['address1'] ?? $shippingAddress['address_1'] ?? '');

            if (preg_match('/\bP\.?\s*O\.?\s*Box\b/i', $addressLine) === 1) {
                $signals[] = 'PO Box address';
            }
        }

        $financialStatus = mb_strtolower(str_replace(' ', '_', (string) ($order['financial_status'] ?? $order['displayFinancialStatus'] ?? '')));

        if ($financialStatus === 'partially_paid') {
            $signals[] = 'Partially paid';
        }

        if ($this->hasFraudTag($order)) {
            $signals[] = 'Fraud/high-risk tag';
        }

        if (mb_strtoupper(trim((string) ($order['risk_level'] ?? ''))) === 'HIGH') {
            $signals[] = 'Shopify HIGH risk level';
        }

        if (array_key_exists('shipping_address', $order) && $order['shipping_address'] === null) {
            $signals[] = 'No shipping address';
        }

        $score = array_sum(array_map(fn (string $signal): int => self::Weights[$signal], $signals));

        return [
            'score' => $score,
            'level' => match (true) {
                $score >= 51 => 'high',
                $score >= 21 => 'medium',
                default => 'low',
            },
            'signals' => $signals,
        ];
    }

    /** @param array<string, mixed> $address */
    private function country(array $address): string
    {
        return mb_strtoupper(trim((string) ($address['country_code'] ?? $address['countryCodeV2'] ?? $address['country'] ?? '')));
    }

    private function isDisposableEmail(string $email): bool
    {
        $separatorPosition = mb_strrpos($email, '@');

        if ($separatorPosition === false) {
            return false;
        }

        return in_array(mb_substr($email, $separatorPosition + 1), self::DisposableEmailDomains, true);
    }

    /** @param array<string, mixed> $order */
    private function hasFraudTag(array $order): bool
    {
        if (! array_key_exists('tags', $order) || $order['tags'] === null) {
            return false;
        }

        $tags = is_array($order['tags'])
            ? implode(', ', array_map(fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '', $order['tags']))
            : (string) $order['tags'];

        return preg_match('/\b(fraud|high-risk)\b/i', $tags) === 1;
    }
}
