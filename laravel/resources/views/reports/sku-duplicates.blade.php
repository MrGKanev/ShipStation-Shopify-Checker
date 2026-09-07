@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Catalogue report</p><h1 class="mt-1 text-3xl font-bold">SKU duplicates</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Find repeated SKUs across active, draft, and archived products. Blank SKUs are ignored; matching is case-sensitive.</p></section>
        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.sku-duplicates.store') }}">@csrf<button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Scan all products</button></form>
        @if ($configurationError)<div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Shopify credentials are incomplete for the active store.</div>@endif
        @if ($reportFailed)<div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">The report could not be completed. Check Shopify and try again.</div>@endif
        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} duplicate SKUs</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $result->totalVariants }} variants scanned</p>
                @if ($result->truncated)<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Results were truncated after {{ $result->pages }} product pages. The report is not a complete store inventory.</div>@endif
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950"><tr><th class="px-4 py-3">SKU</th><th class="px-4 py-3">Count</th><th class="px-4 py-3">Products / variants</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @forelse ($result->rows as $row)
                                <tr><td class="px-4 py-3 font-mono">{{ $row['sku'] }}</td><td class="px-4 py-3">{{ $row['count'] }}</td><td class="px-4 py-3"><ul class="flex flex-col gap-2">
                                    @foreach ($row['variants'] as $variant)
                                        <li>@if ($variant['product_id'])<a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $variant['product_id'] }}" target="_blank" rel="noopener noreferrer">{{ $variant['product_title'] ?: 'Untitled product' }}</a>@else<strong>{{ $variant['product_title'] ?: 'Untitled product' }}</strong>@endif · {{ $variant['variant_title'] ?: 'Default' }} · {{ $variant['product_status'] }}</li>
                                    @endforeach
                                </ul></td></tr>
                            @empty
                                <tr><td class="px-4 py-8 text-center text-slate-500" colspan="3">No duplicate SKUs found in the scanned products.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
