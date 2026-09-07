<?php

namespace App\Domain\Reports;

class EmailCheckAnalyzer
{
    /** @var list<string> */
    private const array DisposableDomains = [
        'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwam.com', 'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la', 'guerrillamail.info', 'trashmail.com', 'trashmail.net', 'trashmail.org', 'dispostable.com', 'maildrop.cc', 'spamgourmet.com', 'spamgourmet.net', 'mailnull.com', 'spamcorner.com', '10minutemail.com', '10minutemail.net', 'fakeinbox.com', 'mailnesia.com', 'discard.email', 'spamspot.com', 'mytemp.email', 'temp-mail.org', 'getnada.com', 'tempr.email',
    ];

    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $email = mb_strtolower($this->text($order['email'] ?? ''));
            $issues = $this->issues($email);
            if ($issues === []) {
                continue;
            }
            $id = $this->text($order['id'] ?? '');
            $rows[] = ['id' => ctype_digit($id) ? $id : '', 'number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'issues' => $issues, 'severity' => in_array('critical', array_column($issues, 'level'), true) ? 'critical' : 'warning'];
        }
        usort($rows, fn (array $a, array $b): int => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));

        return $rows;
    }

    /** @return list<array{level: 'critical'|'warning', message: string}> */
    private function issues(string $email): array
    {
        if ($email === '') {
            return [['level' => 'critical', 'message' => 'No email address on order']];
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [['level' => 'critical', 'message' => 'Invalid email format']];
        }
        $separator = mb_strrpos($email, '@');
        $domain = mb_substr($email, $separator + 1);
        $local = mb_substr($email, 0, $separator);
        $issues = [];
        if (in_array($domain, self::DisposableDomains, true)) {
            $issues[] = ['level' => 'critical', 'message' => "Disposable / temporary email domain ({$domain})"];
        }
        if (mb_strlen($local) <= 2) {
            $issues[] = ['level' => 'warning', 'message' => 'Very short local part - may be a test address'];
        }
        if (preg_match('/^(test|noemail|no-?reply|none|null|fake|dummy|xxx|aaa|zzz)\b/i', $local) === 1) {
            $issues[] = ['level' => 'warning', 'message' => 'Email looks like a placeholder'];
        }
        if (preg_match('/(.)\1{4,}/', $local) === 1) {
            $issues[] = ['level' => 'warning', 'message' => 'Email has repeated characters - may be keyboard mashing'];
        }

        return $issues;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
