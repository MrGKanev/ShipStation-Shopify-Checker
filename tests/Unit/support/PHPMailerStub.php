<?php
declare(strict_types=1);

namespace PHPMailer\PHPMailer;

/**
 * Minimal stand-in for phpmailer/phpmailer, loaded only by EmailNotifierTest.
 * Lets EmailNotifier::sendViaPHPMailer() be exercised for real without
 * pulling in the actual library (never installed in this project - see
 * EmailNotifier::send(), which falls back to mail() when this class is absent).
 */
class PHPMailer
{
    public const string ENCRYPTION_SMTPS   = 'smtps';
    public const string ENCRYPTION_STARTTLS = 'tls';

    public static ?self $lastInstance = null;

    public string $Host       = '';
    public bool   $SMTPAuth   = false;
    public string $Username   = '';
    public string $Password   = '';
    public string $SMTPSecure = '';
    public int    $Port       = 0;
    public string $Subject    = '';
    public string $Body       = '';

    public ?string $fromEmail = null;
    public ?string $fromName  = null;
    /** @var string[] */
    public array $addresses = [];
    public bool $htmlMode = false;
    public bool $sent     = false;
    /** @var array<int, array{content: string, filename: string, encoding: string, type: string}> */
    public array $attachments = [];

    public function __construct(bool $exceptions = false)
    {
        self::$lastInstance = $this;
    }

    public function isSMTP(): void
    {
    }

    public function setFrom(string $email, string $name = ''): bool
    {
        $this->fromEmail = $email;
        $this->fromName  = $name;
        return true;
    }

    public function addAddress(string $address): bool
    {
        $this->addresses[] = $address;
        return true;
    }

    public function isHTML(bool $isHtml = true): void
    {
        $this->htmlMode = $isHtml;
    }

    public function addStringAttachment(string $content, string $filename, string $encoding = 'base64', string $type = 'application/octet-stream'): bool
    {
        $this->attachments[] = compact('content', 'filename', 'encoding', 'type');
        return true;
    }

    public function send(): bool
    {
        $this->sent = true;
        return true;
    }
}
