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

class EmailNotifierTest extends TestCase
{
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
