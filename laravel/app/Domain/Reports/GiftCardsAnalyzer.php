<?php

namespace App\Domain\Reports;

class GiftCardsAnalyzer
{
    /** @param list<array<string, mixed>> $giftCards @return list<array<string, mixed>> */
    public function analyze(array $giftCards, int $days, int $now): array
    {
        $rows = [];
        foreach ($giftCards as $giftCard) {
            if (($giftCard['enabled'] ?? false) !== true) {
                continue;
            }
            $balance = (float) ($giftCard['balance'] ?? 0);
            if ($balance <= 0) {
                continue;
            }
            $reasons = [];
            $daysUntilExpiry = null;
            $expiresOn = $this->text($giftCard['expires_on'] ?? null);
            if ($expiresOn !== '') {
                $expiryTimestamp = strtotime($expiresOn);
                $daysUntilExpiry = (int) floor(((is_int($expiryTimestamp) ? $expiryTimestamp : 0) - $now) / 86400);
                if ($daysUntilExpiry <= $days) {
                    $reasons[] = $daysUntilExpiry < 0 ? 'Expired' : "Expiring in {$daysUntilExpiry}d";
                }
            }
            $initialValue = (float) ($giftCard['initial_value'] ?? 0);
            if ($balance === $initialValue) {
                $reasons[] = 'Never redeemed';
            }
            if ($reasons === []) {
                continue;
            }
            $rows[] = ['id' => $this->text($giftCard['id'] ?? null), 'masked_code' => $this->text($giftCard['masked_code'] ?? null), 'balance' => $balance, 'initial_value' => $initialValue, 'currency' => $this->text($giftCard['currency'] ?? null), 'expires_on' => $expiresOn, 'days_until_expiry' => $daysUntilExpiry, 'created_at' => substr($this->text($giftCard['created_at'] ?? null), 0, 10), 'customer_email' => $this->text($giftCard['customer_email'] ?? null), 'reasons' => $reasons];
        }
        usort($rows, fn (array $left, array $right): int => $right['balance'] <=> $left['balance']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
