<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckApiHealth;
use App\Application\Health\SendTestEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTestEmailRequest;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ApiHealthController extends Controller
{
    public function show(SendTestEmail $sendTestEmail): View
    {
        return $this->view(null, $sendTestEmail);
    }

    public function check(Request $request, CheckApiHealth $checkApiHealth, SendTestEmail $sendTestEmail): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

        return $this->view($checkApiHealth->handle($store), $sendTestEmail);
    }

    public function sendTestEmail(SendTestEmailRequest $request, SendTestEmail $sendTestEmail): View
    {
        $sent = false;

        try {
            $sendTestEmail->handle((string) $request->validated('email'));
            $sent = true;
        } catch (Throwable $exception) {
            Log::warning('Test email delivery failed.', ['exception_type' => $exception::class]);
        }

        return $this->view(null, $sendTestEmail, $sent ? 'sent' : 'failed');
    }

    /** @param array<string, mixed>|null $health */
    private function view(?array $health, SendTestEmail $sendTestEmail, ?string $mailResult = null): View
    {
        return view('admin.api-health', ['health' => $health, 'mailConfiguration' => $sendTestEmail->configuration(), 'mailResult' => $mailResult]);
    }
}
