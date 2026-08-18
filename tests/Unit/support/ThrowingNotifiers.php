<?php
declare(strict_types=1);

/**
 * Simulates a broken Slack webhook (invalid URL, timeout, 500 from Slack) -
 * notification-failure catch blocks must swallow this without failing the
 * scan/audit that triggered the notification.
 */
final class ThrowingSlackNotifier extends SlackNotifier
{
    public function notifyScan(array $summary): void
    {
        throw new RuntimeException('slack boom');
    }

    public function notifyAudit(array $summary): void
    {
        throw new RuntimeException('slack boom');
    }
}

/**
 * Simulates a broken SMTP transport - same swallow-without-failing contract
 * as ThrowingSlackNotifier, for the email leg of notification calls.
 */
final class ThrowingEmailNotifier extends EmailNotifier
{
    public function notifyScan(array $summary, string $toOverride = ''): void
    {
        throw new RuntimeException('email boom');
    }

    public function notifyAudit(array $summary, string $toOverride = ''): void
    {
        throw new RuntimeException('email boom');
    }

    public function sendReport(string $subject, string $htmlBody, string $filename, string $csvContent): void
    {
        throw new RuntimeException('email boom');
    }
}
