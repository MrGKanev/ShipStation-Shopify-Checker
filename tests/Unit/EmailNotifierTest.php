<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Captures the composed message instead of calling the real mail(), so
 * sendReport()'s full pipeline can be asserted on without a real MTA.
 */
class RecordingEmailNotifier extends EmailNotifier
{
    /** @var array<int, array{to: string, subject: string, body: string, headers: string}> */
    public array $sent = [];

    protected function usePhpMailer(): bool
    {
        return false;
    }

    protected function transportMail(string $to, string $subject, string $body, string $headers): bool
    {
        $this->sent[] = compact('to', 'subject', 'body', 'headers');
        return true;
    }
}

/**
 * Forces the mail()-fallback transport to fail, so notifyAuditSafely()'s
 * catch path can be exercised without a real MTA.
 */
class FailingEmailNotifier extends EmailNotifier
{
    protected function usePhpMailer(): bool
    {
        return false;
    }

    protected function transportMail(string $to, string $subject, string $body, string $headers): bool
    {
        return false;
    }
}

class EmailNotifierTest extends TestCase
{
    private const ENV_VARS = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'ALERT_EMAIL', 'SMTP_SECURE'];

    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $name) {
            $this->previousEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
    }

    // ── isConfigured / fromEnvironment ──────────────────────────────────────

    public function testIsConfiguredFalseWithoutEnvVars(): void
    {
        $this->assertFalse(EmailNotifier::isConfigured());
        $this->assertNull(EmailNotifier::fromEnvironment());
    }

    public function testIsConfiguredTrueWithEnvVars(): void
    {
        putenv('SMTP_HOST=smtp.test');
        putenv('ALERT_EMAIL=ops@test.com');

        $this->assertTrue(EmailNotifier::isConfigured());
        $this->assertInstanceOf(EmailNotifier::class, EmailNotifier::fromEnvironment());
    }

    public function testFromEnvironmentAppliesDefaultsAndFromFallback(): void
    {
        putenv('SMTP_HOST=smtp.test');
        putenv('ALERT_EMAIL=ops@test.com');
        putenv('SMTP_USER=user@test.com');

        $notifier = EmailNotifier::fromEnvironment();
        $this->assertNotNull($notifier);

        $props = new \ReflectionObject($notifier);
        $this->assertSame(587, $props->getProperty('port')->getValue($notifier));
        $this->assertSame('tls', $props->getProperty('secure')->getValue($notifier));
        $this->assertSame('user@test.com', $props->getProperty('from')->getValue($notifier));
    }

    // ── notifyAuditSafely ────────────────────────────────────────────────────

    public function testNotifyAuditSafelyReturnsTrueOnSuccess(): void
    {
        $notifier = new RecordingEmailNotifier('smtp.test', 587, 'user@test.com', 'pw', 'from@test.com', 'ops@test.com', 'tls');

        $result = $notifier->notifyAuditSafely(['store' => 'x']);

        $this->assertTrue($result);
        $this->assertCount(1, $notifier->sent);
    }

    public function testNotifyAuditSafelyReturnsFalseOnFailureWithoutThrowing(): void
    {
        $notifier = new FailingEmailNotifier('smtp.test', 587, 'user@test.com', 'pw', 'from@test.com', 'ops@test.com', 'tls');

        $result = $notifier->notifyAuditSafely(['store' => 'x']);

        $this->assertFalse($result);
    }

    // ── auditMessage ─────────────────────────────────────────────────────────

    public function testAuditMessagePluralAndSingularAndZeroWording(): void
    {
        [$plural]   = EmailNotifier::auditMessage(['store' => 'x', 'missing_count' => 3]);
        [$singular] = EmailNotifier::auditMessage(['store' => 'x', 'missing_count' => 1]);
        [$zero]     = EmailNotifier::auditMessage(['store' => 'x', 'missing_count' => 0]);

        $this->assertSame('Shopify Ops audit [x]: 3 missing orders', $plural);
        $this->assertSame('Shopify Ops audit [x]: 1 missing order', $singular);
        $this->assertSame('Shopify Ops audit [x]: No missing orders', $zero);
    }

    public function testAuditMessageListsUpToTenOrdersWithMoreCount(): void
    {
        $orders = [];
        for ($i = 1; $i <= 15; $i++) {
            $orders[] = ['name' => "#{$i}", 'total_price' => '10.00'];
        }

        [, $body] = EmailNotifier::auditMessage(['store' => 'x', 'missing_count' => 15, 'missing_orders' => $orders]);

        $this->assertSame(10, substr_count($body, '<li>#'));
        $this->assertStringContainsString('and 5 more', $body);
    }

    public function testAuditMessageOmitsOrderSectionWhenNoMissingOrders(): void
    {
        [, $body] = EmailNotifier::auditMessage(['store' => 'x', 'missing_count' => 0, 'missing_orders' => []]);

        $this->assertStringNotContainsString('Missing orders', $body);
    }

    public function testAuditMessageEscapesStoreAndOrderNameInHtmlBody(): void
    {
        [, $body] = EmailNotifier::auditMessage([
            'store'          => '<script>bad</script>',
            'missing_count'  => 1,
            'missing_orders' => [['name' => '<b>evil</b>']],
        ]);

        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<b>evil</b>', $body);
        $this->assertStringContainsString('&lt;b&gt;evil&lt;/b&gt;', $body);
    }

    public function testAuditMessageIncludesDurationFieldWhenPresent(): void
    {
        [, $body] = EmailNotifier::auditMessage(['store' => 'x', 'duration' => 4.2]);

        $this->assertStringContainsString('Duration', $body);
        $this->assertStringContainsString('4.2s', $body);
    }

    public function testAuditMessageOmitsDurationFieldWhenAbsent(): void
    {
        [, $body] = EmailNotifier::auditMessage(['store' => 'x']);

        $this->assertStringNotContainsString('Duration', $body);
    }

    // ── scanMessage ──────────────────────────────────────────────────────────

    public function testScanMessagePluralAndSingularWording(): void
    {
        [$plural]   = EmailNotifier::scanMessage(['tool' => 'scan_sla', 'rows_found' => 3]);
        [$singular] = EmailNotifier::scanMessage(['tool' => 'scan_sla', 'rows_found' => 1]);

        $this->assertSame('Shopify Ops scan [scan_sla]: 3 rows found', $plural);
        $this->assertSame('Shopify Ops scan [scan_sla]: 1 row found', $singular);
    }

    public function testScanMessageOmitsScannedAndPeriodWhenAbsent(): void
    {
        [, $body] = EmailNotifier::scanMessage(['tool' => 'x', 'rows_found' => 0]);

        $this->assertStringNotContainsString('Scanned', $body);
        $this->assertStringNotContainsString('Period', $body);
    }

    public function testScanMessageIncludesScannedAndPeriodWhenPresent(): void
    {
        [, $body] = EmailNotifier::scanMessage([
            'tool' => 'x', 'rows_found' => 0, 'scanned' => 42, 'start' => '2026-06-01', 'end' => '2026-06-19',
        ]);

        $this->assertStringContainsString('Scanned', $body);
        $this->assertStringContainsString('42', $body);
        $this->assertStringContainsString('Period', $body);
        $this->assertStringContainsString('2026-06-01', $body);
    }

    public function testSendReportSendsMultipartMessageWithCsvAttachmentViaMail(): void
    {
        $notifier = new RecordingEmailNotifier('smtp.test', 587, 'user@test.com', 'pw', 'from@test.com', 'ops@test.com', 'tls');

        $notifier->sendReport(
            'Fulfilled Items Report (2026-07-01 \u{2192} 2026-07-31)',
            '<h2>Fulfilled Items Report</h2>',
            'fulfilled_items_2026-07-01_to_2026-07-31.csv',
            "product,quantity\nWidget blue,40\n"
        );

        $this->assertCount(1, $notifier->sent);
        $message = $notifier->sent[0];

        $this->assertSame('ops@test.com', $message['to']);
        $this->assertStringContainsString('Fulfilled Items Report', $message['subject']);
        $this->assertStringContainsString('multipart/mixed', $message['headers']);
        $this->assertStringContainsString('MIME-Version: 1.0', $message['headers']);
        $this->assertStringContainsString('<h2>Fulfilled Items Report</h2>', $message['body']);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="fulfilled_items_2026-07-01_to_2026-07-31.csv"', $message['body']);
        $this->assertStringContainsString(
            base64_encode("product,quantity\nWidget blue,40\n"),
            str_replace("\r\n", '', $message['body'])
        );
    }

    public function testSendViaPhpMailerConfiguresPHPMailerAndAttachment(): void
    {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            require __DIR__ . '/support/PHPMailerStub.php';
        }

        $notifier = new EmailNotifier('smtp.test', 465, 'user@test.com', 'pw', 'from@test.com', 'ops@test.com', 'ssl');

        $ref    = new \ReflectionClass(EmailNotifier::class);
        $method = $ref->getMethod('sendViaPHPMailer');
        $method->invoke($notifier, 'Subject line', '<p>hi</p>', [
            ['filename' => 'report.csv', 'content' => 'a,b', 'mime' => 'text/csv'],
        ]);

        $mail = \PHPMailer\PHPMailer\PHPMailer::$lastInstance;
        $this->assertNotNull($mail);
        $this->assertSame('smtp.test', $mail->Host);
        $this->assertSame(465, $mail->Port);
        $this->assertTrue($mail->SMTPAuth);
        $this->assertSame('user@test.com', $mail->Username);
        $this->assertSame(\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS, $mail->SMTPSecure);
        $this->assertSame('from@test.com', $mail->fromEmail);
        $this->assertSame(['ops@test.com'], $mail->addresses);
        $this->assertTrue($mail->htmlMode);
        $this->assertSame('Subject line', $mail->Subject);
        $this->assertSame('<p>hi</p>', $mail->Body);
        $this->assertSame([['content' => 'a,b', 'filename' => 'report.csv', 'encoding' => 'base64', 'type' => 'text/csv']], $mail->attachments);
        $this->assertTrue($mail->sent);
    }

    private function buildMultipartBody(string $boundary, string $htmlBody, array $attachments): string
    {
        $ref    = new \ReflectionClass(EmailNotifier::class);
        $method = $ref->getMethod('buildMultipartBody');
        return $method->invoke(null, $boundary, $htmlBody, $attachments);
    }

    public function testMultipartBodyIncludesHtmlPartAndAttachment(): void
    {
        $body = $this->buildMultipartBody('BOUND123', '<p>hello</p>', [
            ['filename' => 'report.csv', 'content' => "product,quantity\nWidget,40\n", 'mime' => 'text/csv'],
        ]);

        $this->assertStringContainsString('--BOUND123', $body);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $body);
        $this->assertStringContainsString('<p>hello</p>', $body);
        $this->assertStringContainsString('Content-Type: text/csv; name="report.csv"', $body);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="report.csv"', $body);
        $this->assertStringContainsString(base64_encode("product,quantity\nWidget,40\n"), str_replace("\r\n", '', $body));
        $this->assertStringEndsWith('--BOUND123--', $body);
    }

    public function testMultipartBodyWithNoAttachmentsHasOnlyHtmlPart(): void
    {
        $body = $this->buildMultipartBody('B', '<p>x</p>', []);

        $this->assertSame(1, substr_count($body, '--B' . "\r\n"));
    }
}
