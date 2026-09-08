<?php

namespace App\Application\Health;

use App\Notifications\SlackTestNotification;
use Illuminate\Support\Facades\Notification;
use LogicException;

class SendTestSlack
{
    /** @return array{configured: bool, endpoint: string} */
    public function configuration(): array
    {
        $webhookUrl = trim((string) config('services.slack.notifications.webhook_url'));
        $host = strtolower((string) parse_url($webhookUrl, PHP_URL_HOST));
        $path = (string) parse_url($webhookUrl, PHP_URL_PATH);
        $configured = str_starts_with($webhookUrl, 'https://')
            && in_array($host, ['hooks.slack.com', 'hooks.slack-gov.com'], true)
            && str_starts_with($path, '/services/');

        return [
            'configured' => $configured,
            'endpoint' => $configured ? $host : '',
        ];
    }

    public function handle(): void
    {
        if (! $this->configuration()['configured']) {
            throw new LogicException('Slack delivery is not configured.');
        }

        Notification::route('slack', (string) config('services.slack.notifications.webhook_url'))
            ->notifyNow(new SlackTestNotification((string) config('app.name'), now()->toDateTimeString()));
    }
}
