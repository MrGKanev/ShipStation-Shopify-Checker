<?php
declare(strict_types=1);

use Psr\Log\LoggerInterface;

/**
 * Sends operational summaries via email.
 * Uses PHPMailer when available; falls back to PHP's built-in mail() otherwise.
 */
class EmailNotifier
{
    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $from,
        private readonly string $to,
        private readonly string $secure,
    ) {
        if (trim($to) === '') {
            throw new InvalidArgumentException('Alert email (ALERT_EMAIL) is empty.');
        }
    }

    public static function isConfigured(): bool
    {
        return trim((string) getenv('SMTP_HOST')) !== ''
            && trim((string) getenv('ALERT_EMAIL')) !== '';
    }

    public static function fromEnvironment(): ?self
    {
        if (!self::isConfigured()) {
            return null;
        }
        return new self(
            host:   trim((string) getenv('SMTP_HOST')),
            port:   (int) (getenv('SMTP_PORT') ?: 587),
            user:   trim((string) getenv('SMTP_USER')),
            pass:   trim((string) getenv('SMTP_PASS')),
            from:   trim((string) (getenv('SMTP_FROM') ?: getenv('SMTP_USER'))),
            to:     trim((string) getenv('ALERT_EMAIL')),
            secure: trim((string) (getenv('SMTP_SECURE') ?: 'tls')),
        );
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyAudit(array $summary): void
    {
        [$subject, $body] = self::auditMessage($summary);
        $this->send($subject, $body);
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyScan(array $summary): void
    {
        [$subject, $body] = self::scanMessage($summary);
        $this->send($subject, $body);
    }

    /**
     * Sends an HTML summary with a CSV report attached.
     */
    public function sendReport(string $subject, string $htmlBody, string $filename, string $csvContent): void
    {
        $this->send($subject, $htmlBody, [['filename' => $filename, 'content' => $csvContent, 'mime' => 'text/csv']]);
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyAuditSafely(array $summary, ?LoggerInterface $logger = null): bool
    {
        try {
            $this->notifyAudit($summary);
            return true;
        } catch (Throwable $e) {
            $logger?->warning('Email audit notification failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{string, string} [subject, htmlBody]
     */
    public static function auditMessage(array $summary): array
    {
        $store     = (string) ($summary['store'] ?? 'Unknown store');
        $start     = (string) ($summary['start'] ?? '');
        $end       = (string) ($summary['end'] ?? '');
        $missing   = (int) ($summary['missing_count'] ?? 0);
        $found     = (int) ($summary['found'] ?? 0);
        $skipped   = (int) ($summary['skipped'] ?? 0);
        $ignored   = (int) ($summary['ignored'] ?? 0);
        $totalSs   = (int) ($summary['total_ss'] ?? 0);
        $duration  = isset($summary['duration']) ? (float) $summary['duration'] : null;
        $orders    = array_slice((array) ($summary['missing_orders'] ?? []), 0, 10);
        $moreCount = max(0, $missing - count($orders));

        $statusText = $missing > 0
            ? "{$missing} missing order" . ($missing === 1 ? '' : 's')
            : 'No missing orders';

        $subject = "Shopify Ops audit [{$store}]: {$statusText}";

        $tableData = [
            ['Store',             self::h($store)],
            ['Period',            self::h("{$start} \u{2192} {$end}")],
            ['Missing',           (string) $missing],
            ['Matched',           (string) $found],
            ['Skipped',           (string) $skipped],
            ['Ignored',           (string) $ignored],
            ['ShipStation total', (string) $totalSs],
        ];
        if ($duration !== null) {
            $tableData[] = ['Duration', "{$duration}s"];
        }

        $tableRows = '';
        foreach ($tableData as [$label, $value]) {
            $tableRows .= '<tr>'
                . '<td style="font-weight:bold;padding:4px 12px 4px 0;white-space:nowrap">' . $label . '</td>'
                . '<td style="padding:4px 0">' . $value . '</td>'
                . '</tr>';
        }

        $orderHtml = '';
        if ($orders !== []) {
            $items = '';
            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }
                $name  = self::h((string) ($order['name'] ?? $order['order_number'] ?? '?'));
                $total = isset($order['total_price'])
                    ? ' &mdash; $' . number_format((float) $order['total_price'], 2)
                    : '';
                $items .= "<li>{$name}{$total}</li>";
            }
            if ($moreCount > 0) {
                $items .= "<li>\u{2026}and {$moreCount} more</li>";
            }
            $orderHtml = '<h3 style="margin:16px 0 8px">Missing orders</h3>'
                . '<ul style="margin:0;padding-left:20px">' . $items . '</ul>';
        }

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:sans-serif;color:#111;max-width:600px;margin:0 auto;padding:20px">'
            . '<h2 style="margin-bottom:16px">Shopify Ops audit: ' . self::h($statusText) . '</h2>'
            . '<table style="border-collapse:collapse;width:100%">' . $tableRows . '</table>'
            . $orderHtml
            . '<p style="margin-top:20px;font-size:12px;color:#888">Sent by Shopify Ops</p>'
            . '</body></html>';

        return [$subject, $body];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{string, string} [subject, htmlBody]
     */
    public static function scanMessage(array $summary): array
    {
        $tool    = (string) ($summary['tool'] ?? 'scan');
        $rows    = (int) ($summary['rows_found'] ?? 0);
        $scanned = $summary['scanned'] ?? null;
        $start   = (string) ($summary['start'] ?? '');
        $end     = (string) ($summary['end'] ?? '');

        $subject = "Shopify Ops scan [{$tool}]: {$rows} row" . ($rows === 1 ? '' : 's') . ' found';

        $tableData = [
            ['Tool',       self::h($tool)],
            ['Rows found', (string) $rows],
        ];
        if ($scanned !== null) {
            $tableData[] = ['Scanned', (string) $scanned];
        }
        if ($start || $end) {
            $tableData[] = ['Period', self::h("{$start} \u{2192} {$end}")];
        }

        $tableRows = '';
        foreach ($tableData as [$label, $value]) {
            $tableRows .= '<tr>'
                . '<td style="font-weight:bold;padding:4px 12px 4px 0;white-space:nowrap">' . $label . '</td>'
                . '<td style="padding:4px 0">' . $value . '</td>'
                . '</tr>';
        }

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:sans-serif;color:#111;max-width:600px;margin:0 auto;padding:20px">'
            . '<h2 style="margin-bottom:16px">Shopify Ops scan: ' . self::h($tool) . '</h2>'
            . '<table style="border-collapse:collapse;width:100%">' . $tableRows . '</table>'
            . '<p style="margin-top:20px;font-size:12px;color:#888">Sent by Shopify Ops</p>'
            . '</body></html>';

        return [$subject, $body];
    }

    /**
     * @param array<int, array{filename: string, content: string, mime?: string}> $attachments
     */
    private function send(string $subject, string $htmlBody, array $attachments = []): void
    {
        if ($this->usePhpMailer()) {
            $this->sendViaPHPMailer($subject, $htmlBody, $attachments);
        } else {
            $this->sendViaMail($subject, $htmlBody, $attachments);
        }
    }

    /**
     * Overridable so tests can force a transport regardless of whether the
     * (test-only) PHPMailer stub happens to be loaded elsewhere in the run.
     */
    protected function usePhpMailer(): bool
    {
        return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }

    /**
     * @param array<int, array{filename: string, content: string, mime?: string}> $attachments
     */
    private function sendViaPHPMailer(string $subject, string $htmlBody, array $attachments = []): void
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $this->host;
        $mail->SMTPAuth   = $this->user !== '';
        $mail->Username   = $this->user;
        $mail->Password   = $this->pass;
        $mail->SMTPSecure = strtolower($this->secure) === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $this->port;
        $mail->setFrom($this->from ?: $this->user, 'Shopify Ops');
        $mail->addAddress($this->to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        foreach ($attachments as $attachment) {
            $mail->addStringAttachment($attachment['content'], $attachment['filename'], 'base64', $attachment['mime'] ?? 'application/octet-stream');
        }
        $mail->send();
    }

    /**
     * @param array<int, array{filename: string, content: string, mime?: string}> $attachments
     */
    private function sendViaMail(string $subject, string $htmlBody, array $attachments = []): void
    {
        $from = $this->from ?: $this->user;

        if ($attachments === []) {
            $headers = "From: Shopify Ops <{$from}>\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . 'MIME-Version: 1.0';
            $ok = $this->transportMail($this->to, $subject, $htmlBody, $headers);
        } else {
            $boundary = 'sops-' . bin2hex(random_bytes(16));
            $headers  = "From: Shopify Ops <{$from}>\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
            $ok = $this->transportMail($this->to, $subject, self::buildMultipartBody($boundary, $htmlBody, $attachments), $headers);
        }

        if (!$ok) {
            throw new RuntimeException('mail() returned false — check PHP mail configuration.');
        }
    }

    /**
     * Thin seam over the global mail() so tests can capture the composed message
     * without a real MTA.
     */
    protected function transportMail(string $to, string $subject, string $body, string $headers): bool
    {
        return mail($to, $subject, $body, $headers);
    }

    /**
     * @param array<int, array{filename: string, content: string, mime?: string}> $attachments
     */
    private static function buildMultipartBody(string $boundary, string $htmlBody, array $attachments): string
    {
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n";

        foreach ($attachments as $attachment) {
            $mime = $attachment['mime'] ?? 'application/octet-stream';
            $body .= "--{$boundary}\r\n"
                . "Content-Type: {$mime}; name=\"{$attachment['filename']}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$attachment['filename']}\"\r\n\r\n"
                . chunk_split(base64_encode($attachment['content']))
                . "\r\n";
        }

        return $body . "--{$boundary}--";
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
