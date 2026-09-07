<?php

namespace App\Application\Health;

use App\Mail\TestEmail;
use Illuminate\Support\Facades\Mail;
use LogicException;

class SendTestEmail
{
    /** @return array{mailer: string, transport: string, configured: bool, from_address: string} */
    public function configuration(): array
    {
        $mailer = trim((string) config('mail.default'));
        $transport = trim((string) config("mail.mailers.{$mailer}.transport"));
        $fromAddress = trim((string) config('mail.from.address'));
        $host = trim((string) config("mail.mailers.{$mailer}.host"));
        $port = (int) config("mail.mailers.{$mailer}.port");
        $username = trim((string) config("mail.mailers.{$mailer}.username"));
        $password = trim((string) config("mail.mailers.{$mailer}.password"));
        $authenticationComplete = ($username === '' && $password === '') || ($username !== '' && $password !== '');

        return [
            'mailer' => $mailer,
            'transport' => $transport,
            'configured' => $transport === 'smtp' && $host !== '' && $port > 0 && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) !== false && $authenticationComplete,
            'from_address' => filter_var($fromAddress, FILTER_VALIDATE_EMAIL) !== false ? $fromAddress : '',
        ];
    }

    public function handle(string $recipient): void
    {
        if (! $this->configuration()['configured']) {
            throw new LogicException('SMTP delivery is not configured.');
        }

        Mail::to($recipient)->send(new TestEmail((string) config('app.name'), now()->toDateTimeString()));
    }
}
