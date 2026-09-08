@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Inventory report</p><h1 class="mt-1 text-3xl font-bold">Inventory aging</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Find active, tracked variants at zero or negative stock that still sold during the selected period.</p></section>
        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.inventory-aging.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div><label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label><input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">@error($field)<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div>
            @endforeach
            <div class="flex items-end"><button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Run report</button></div>
        </form>
        @if ($configurationError)<div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Shopify credentials are incomplete for the active store.</div>@endif
        @if ($reportFailed)<div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">The report could not be completed. Check Shopify and try again.</div>@endif
        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->products }} products · {{ $result->variants }} variants · {{ $result->orders }} orders</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ count($result->rows) }} zero-stock recent sellers</p>
                @if ($result->productsTruncated || $result->ordersTruncated)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        Results are incomplete:
                        @if ($result->productsTruncated)
                            product catalogue truncated after {{ $result->productPages }} pages
                        @endif
                        @if ($result->productsTruncated && $result->ordersTruncated)
                            and
                        @endif
                        @if ($result->ordersTruncated)
                            orders truncated after {{ $result->orderPages }} pages
                        @endif
                        .
                    </div>
                @endif
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800"><thead class="bg-slate-50 dark:bg-slate-950"><tr><th class="px-4 py-3">Product / variant</th><th class="px-4 py-3">SKU</th><th class="px-4 py-3">Stock</th><th class="px-4 py-3">Recent quantity</th><th class="px-4 py-3">Last sale</th><th class="px-4 py-3">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($result->rows as $row)
                        <tr><td class="px-4 py-3">@if($row['product_id'])<a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['product_id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['product_title'] ?: 'Untitled product' }}</a>@else<strong>{{ $row['product_title'] ?: 'Untitled product' }}</strong>@endif<div class="text-slate-500">{{ $row['variant_title'] ?: 'Default' }}</div></td><td class="px-4 py-3 font-mono">{{ $row['sku'] }}</td><td class="px-4 py-3">{{ $row['stock'] }}</td><td class="px-4 py-3 font-semibold">{{ $row['recent_qty'] }}</td><td class="px-4 py-3">{{ $row['last_date'] ?: '—' }}<div class="text-slate-500">{{ $row['last_order'] }}</div></td><td class="px-4 py-3">@if($row['last_order'])<a class="text-indigo-600 dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => ltrim($row['last_order'], '#')]) }}">Spot-check</a>@endif</td></tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="6">No active tracked zero-stock variant had recent sales in the selected window.</td></tr>
                    @endforelse
                </tbody></table></div>
            </section>
        @endif
    </div>
@endsection
