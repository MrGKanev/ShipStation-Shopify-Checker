<?php

namespace App\Domain\Reports;

class ConsentAuditAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $emailConsent = strtolower($this->text($order['customer_email_consent'] ?? ''));
            if ($emailConsent === 'subscribed') {
                continue;
            }
            $id = $this->text($order['id'] ?? '');
            $rows[] = ['id' => ctype_digit($id) ? $id : '', 'number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'email_consent' => $emailConsent ?: 'unknown', 'sms_consent' => strtolower($this->text($order['customer_sms_consent'] ?? '')) ?: 'unknown', 'financial' => $this->text($order['financial_status'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'currency' => strtoupper($this->text($order['currency'] ?? ''))];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
