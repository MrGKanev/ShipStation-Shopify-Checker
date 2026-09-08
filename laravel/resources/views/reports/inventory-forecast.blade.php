@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Inventory report</p><h1 class="mt-1 text-3xl font-bold">Inventory forecast</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Estimate days until stock reaches zero from the last 30 days of paid-order sales.</p></section>
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300"><li>Only tracked variants with overselling disabled are included.</li><li>Daily rate equals units sold in 30 days divided by 30.</li><li>Critical means fewer than 7 days; low stock means 7–13 days.</li></ul></section>
        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.inventory-forecast.store') }}">@csrf<button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Run forecast</button></form>
        @if ($configurationError)<div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Shopify credentials are incomplete for the active store.</div>@endif
        @if ($reportFailed)<div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">The forecast could not be completed. Check Shopify and try again.</div>@endif
        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->products }} products · {{ $result->variants }} variants · {{ $result->orders }} orders</h2>
                <div class="flex flex-wrap gap-2 text-sm"><span>{{ $result->startDate }} → {{ $result->endDate }}</span>@if($result->critical)<span class="rounded-full bg-red-100 px-2 py-1 text-red-800 dark:bg-red-950 dark:text-red-200">{{ $result->critical }} critical</span>@endif @if($result->warning)<span class="rounded-full bg-amber-100 px-2 py-1 text-amber-800 dark:bg-amber-950 dark:text-amber-200">{{ $result->warning }} low stock</span>@endif</div>
                @if ($result->productsTruncated || $result->ordersTruncated)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Results are incomplete. @if($result->productsTruncated)Product catalogue stopped after {{ $result->productPages }} pages. @endif @if($result->ordersTruncated)Orders stopped after {{ $result->orderPages }} pages.@endif</div>
                @endif
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800"><thead class="bg-slate-50 dark:bg-slate-950"><tr><th class="px-4 py-3">SKU</th><th class="px-4 py-3">Product / variant</th><th class="px-4 py-3">Stock</th><th class="px-4 py-3">Sold</th><th class="px-4 py-3">Daily rate</th><th class="px-4 py-3">Days to zero</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($result->rows as $row)
                        <tr class="{{ $row['days_to_zero'] !== null && $row['days_to_zero'] < 7 ? 'bg-red-50 dark:bg-red-950/30' : ($row['days_to_zero'] !== null && $row['days_to_zero'] < 14 ? 'bg-amber-50 dark:bg-amber-950/30' : '') }}"><td class="px-4 py-3 font-mono">{{ $row['sku'] ?: '—' }}</td><td class="px-4 py-3">@if($row['product_id'])<a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['product_id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['product_title'] ?: 'Untitled product' }}</a>@else<strong>{{ $row['product_title'] ?: 'Untitled product' }}</strong>@endif<div class="text-slate-500">{{ $row['variant_title'] ?: 'Default' }}</div></td><td class="px-4 py-3">{{ $row['stock'] }}</td><td class="px-4 py-3">{{ $row['sold_30d'] }}</td><td class="px-4 py-3 font-mono">{{ number_format($row['daily_rate'], 2) }}/day</td><td class="px-4 py-3 font-semibold">@if($row['stock'] <= 0)Out of stock @elseif($row['days_to_zero'] !== null){{ $row['days_to_zero'] }} days @else—@endif</td></tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="6">No stock-out risk was detected.</td></tr>
                    @endforelse
                </tbody></table></div>
            </section>
        @endif
    </div>
@endsection
