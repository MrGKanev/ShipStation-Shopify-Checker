@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Gift card report</p><h1 class="mt-1 text-3xl font-bold">Gift cards</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Find enabled cards with remaining balances that expire soon or have never been redeemed.</p></section>
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300"><li>Disabled and fully redeemed cards are excluded.</li><li>A card may be both expiring soon and never redeemed.</li><li>Results are sorted by remaining balance.</li></ul></section>
        <form class="flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.gift-cards.store') }}">
            @csrf
            <div><label class="text-sm font-medium" for="days">Expiring within (days)</label><input class="mt-2 block w-40 rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="days" min="1" max="3650" name="days" step="1" type="number" value="{{ old('days', $days) }}">@error('days')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div>
            <button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Scan gift cards</button>
        </form>
        @if ($configurationError)<div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Shopify credentials are incomplete for the active store.</div>@endif
        @if ($reportFailed)<div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">The report could not be completed. Check Shopify and try again.</div>@endif
        @if ($result)
            <section class="flex flex-col gap-4"><h2 class="text-2xl font-bold">{{ $result->scanned }} gift cards · {{ count($result->rows) }} flagged</h2>
                @if ($result->truncated)<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Results were truncated after {{ $result->pages }} gift card pages.</div>@endif
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800"><thead class="bg-slate-50 dark:bg-slate-950"><tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Balance</th><th class="px-4 py-3">Initial value</th><th class="px-4 py-3">Expires</th><th class="px-4 py-3">Issues</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($result->rows as $row)
                        <tr><td class="px-4 py-3 font-mono">{{ $row['masked_code'] ?: '—' }}</td><td class="px-4 py-3">{{ $row['customer_email'] ?: '—' }}</td><td class="px-4 py-3">{{ number_format($row['balance'], 2) }} {{ $row['currency'] }}</td><td class="px-4 py-3">{{ number_format($row['initial_value'], 2) }} {{ $row['currency'] }}</td><td class="px-4 py-3">{{ $row['expires_on'] ?: 'No expiry' }}</td><td class="px-4 py-3"><div class="flex flex-col gap-1">@foreach($row['reasons'] as $reason)<span class="w-fit rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200">{{ $reason }}</span>@endforeach</div></td></tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="6">All scanned gift cards are fresh, redeemed, disabled, or not close to expiry.</td></tr>
                    @endforelse
                </tbody></table></div>
            </section>
        @endif
    </div>
@endsection
