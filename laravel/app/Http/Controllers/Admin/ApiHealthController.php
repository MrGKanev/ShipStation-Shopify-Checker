<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckApiHealth;
use App\Application\Health\SendTestEmail;
use App\Application\Health\SendTestSlack;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTestEmailRequest;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ApiHealthController extends Controller
{
    public function show(SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack): View
    {
        return $this->view(null, $sendTestEmail, $sendTestSlack);
    }

    public function check(Request $request, CheckApiHealth $checkApiHealth, SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

        return $this->view($checkApiHealth->handle($store), $sendTestEmail, $sendTestSlack);
    }

    public function sendTestEmail(SendTestEmailRequest $request, SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack): View
    {
        $sent = false;

        try {
            $sendTestEmail->handle((string) $request->validated('email'));
            $sent = true;
        } catch (Throwable $exception) {
            Log::warning('Test email delivery failed.', ['exception_type' => $exception::class]);
        }

        return $this->view(null, $sendTestEmail, $sendTestSlack, $sent ? 'sent' : 'failed');
    }

    public function sendTestSlack(SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack): View
    {
        $sent = false;

        try {
            $sendTestSlack->handle();
            $sent = true;
        } catch (Throwable $exception) {
            Log::warning('Test Slack delivery failed.', ['exception_type' => $exception::class]);
        }

        return $this->view(null, $sendTestEmail, $sendTestSlack, slackResult: $sent ? 'sent' : 'failed');
    }

    /** @param array<string, mixed>|null $health */
    private function view(?array $health, SendTestEmail $sendTestEmail, SendTestSlack $sendTestSlack, ?string $mailResult = null, ?string $slackResult = null): View
    {
        return view('admin.api-health', [
            'health' => $health,
            'mailConfiguration' => $sendTestEmail->configuration(),
            'mailResult' => $mailResult,
            'slackConfiguration' => $sendTestSlack->configuration(),
            'slackResult' => $slackResult,
        ]);
    }
}
