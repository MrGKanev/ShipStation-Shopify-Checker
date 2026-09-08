@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section>
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Administration</p>
            <h1 class="mt-1 text-3xl font-bold">API Health</h1>
            <p class="mt-2 text-slate-500 dark:text-slate-400">Run lightweight live checks for the active store's Shopify and ShipStation connections.</p>
        </section>

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('admin.api-health.check') }}">
            @csrf
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">The check reads one small response from each provider. It does not change orders or settings.</p>
            <button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Run health check</button>
        </form>

        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold">SMTP delivery</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Mailer: {{ $mailConfiguration['mailer'] ?: 'not set' }} · From: {{ $mailConfiguration['from_address'] ?: 'invalid' }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $mailConfiguration['configured'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200' }}">{{ $mailConfiguration['configured'] ? 'Configured' : 'Needs configuration' }}</span>
            </div>

            @if ($mailResult === 'sent')<p class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Test email sent successfully.</p>@endif
            @if ($mailResult === 'failed')<p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">Test email could not be sent. Check the SMTP configuration and application log.</p>@endif

            <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start" method="POST" action="{{ route('admin.api-health.test-email') }}">
                @csrf
                <div class="flex-1">
                    <label class="text-sm font-medium" for="email">Recipient</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="email" name="email" type="email" value="{{ old('email', auth()->user()->email) }}" maxlength="255" required>
                    @error('email')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <button class="mt-7 rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled(! $mailConfiguration['configured'])>Send test email</button>
            </form>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold">Slack delivery</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Incoming webhook: {{ $slackConfiguration['endpoint'] ?: 'not configured' }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $slackConfiguration['configured'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200' }}">{{ $slackConfiguration['configured'] ? 'Configured' : 'Needs configuration' }}</span>
            </div>

            @if ($slackResult === 'sent')<p class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Test Slack notification sent successfully.</p>@endif
            @if ($slackResult === 'failed')<p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">Test Slack notification could not be sent. Check the webhook configuration and application log.</p>@endif

            <form class="mt-4" method="POST" action="{{ route('admin.api-health.test-slack') }}">
                @csrf
                <button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled(! $slackConfiguration['configured'])>Send test notification</button>
            </form>
        </section>

        @if ($health)
            <p class="text-sm text-slate-500 dark:text-slate-400">Checked at {{ $health['checked_at'] }}</p>
            <div class="grid gap-5 lg:grid-cols-2">
                @foreach (['shopify' => 'Shopify', 'shipstation' => 'ShipStation'] as $key => $label)
                    @php($result = $health[$key])
                    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-center justify-between gap-4">
                            <h2 class="text-xl font-bold">{{ $label }}</h2>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $result['ok'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' }}">{{ $result['ok'] ? 'Healthy' : 'Needs attention' }}</span>
                        </div>
                        @if ($result['error'])<p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">{{ $result['error'] }}</p>@endif
                        <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-5 gap-y-2 text-sm">
                            <dt class="text-slate-500">Configured</dt><dd>{{ $result['configured'] ? 'Yes' : 'No' }}</dd>
                            <dt class="text-slate-500">Latency</dt><dd>{{ $result['latency_ms'] === null ? '—' : $result['latency_ms'].' ms' }}</dd>
                            @if ($key === 'shopify')
                                <dt class="text-slate-500">Shop</dt><dd>{{ $result['shop_name'] ?: '—' }}</dd>
                                <dt class="text-slate-500">Requested API version</dt><dd>{{ $result['requested_version'] ?: '—' }}</dd>
                                <dt class="text-slate-500">Returned API version</dt><dd class="{{ $result['version_matches'] ? '' : 'text-red-600 dark:text-red-400' }}">{{ $result['returned_version'] ?: 'Missing' }}</dd>
                                <dt class="text-slate-500">Scopes</dt><dd>{{ $result['scopes'] === [] ? '—' : implode(', ', $result['scopes']) }}</dd>
                                <dt class="text-slate-500">Missing scopes</dt><dd class="{{ $result['missing_scopes'] === [] ? '' : 'text-red-600 dark:text-red-400' }}">{{ $result['missing_scopes'] === [] ? 'None' : implode(', ', $result['missing_scopes']) }}</dd>
                            @endif
                        </dl>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endsection
