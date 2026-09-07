@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Risk report</p><h1 class="mt-1 text-3xl font-bold">Fraud Risk Report</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Score paid orders using customer, address, payment, tag, and Shopify risk signals. Only medium and high risk orders are shown.</p></section>
        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.fraud-risk.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div><label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label><input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>
            @endforeach
            <div class="flex items-end"><button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Run report</button></div>
        </form>
        @if ($configurationError)<div class="rounded-xl bg-amber-50 p-4 text-amber-800 dark:bg-amber-950 dark:text-amber-200">Shopify credentials are incomplete for the active store.</div>@endif
        @if ($reportFailed)<div class="rounded-xl bg-red-50 p-4 text-red-800 dark:bg-red-950 dark:text-red-200">The report could not be completed. Check Shopify and try again.</div>@endif
        @if ($result)
            <section class="flex flex-col gap-4"><h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} flagged</h2>
                @if ($result->truncated)<div class="rounded-xl bg-amber-50 p-4 text-amber-800 dark:bg-amber-950 dark:text-amber-200">Results are incomplete: orders truncated after {{ $result->pages }} pages.</div>@endif
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800"><thead><tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Payment</th><th class="px-4 py-3">Risk</th></tr></thead><tbody>
                    @forelse ($result->rows as $row)<tr class="align-top"><td class="px-4 py-3">@if($row['id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['number'] }}</a>@else{{ $row['number'] }}@endif</td><td class="px-4 py-3">{{ $row['created_at'] }}</td><td class="px-4 py-3">{{ $row['email'] }}</td><td class="px-4 py-3">{{ number_format($row['total'], 2) }} {{ $row['currency'] }}</td><td class="px-4 py-3">{{ str_replace('_', ' ', $row['financial']) }}</td><td class="px-4 py-3"><span class="font-semibold">{{ ucfirst($row['risk']['level']) }} · {{ $row['risk']['score'] }}</span><ul class="mt-1 list-disc pl-5 text-slate-500 dark:text-slate-400">@foreach($row['risk']['signals'] as $signal)<li>{{ $signal }}</li>@endforeach</ul></td></tr>
                    @empty<tr><td class="px-4 py-8 text-center text-slate-500" colspan="6">No medium or high risk orders were found in this range.</td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endif
    </div>
@endsection
