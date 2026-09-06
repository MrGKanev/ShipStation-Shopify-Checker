@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Catalogue report</p><h1 class="mt-1 text-3xl font-bold">Product completeness</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Find active products with no usable image, meaningful description, variants, or SKU.</p></section>
        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.product-completeness.store') }}">@csrf<button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Scan active products</button></form>
        @if ($configurationError)<div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Shopify credentials are incomplete for the active store.</div>@endif
        @if ($reportFailed)<div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">The report could not be completed. Check Shopify and try again.</div>@endif
        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} with issues</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $result->critical }} critical · {{ $result->warnings }} warnings</p>
                @if ($result->truncated)<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Results were truncated after {{ $result->pages }} product pages. The report is not a complete store inventory.</div>@endif
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800"><thead class="bg-slate-50 dark:bg-slate-950"><tr><th class="px-4 py-3">Product</th><th class="px-4 py-3">Vendor / type</th><th class="px-4 py-3">Images</th><th class="px-4 py-3">Variants</th><th class="px-4 py-3">Issues</th><th class="px-4 py-3">Severity</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($result->rows as $row)<tr><td class="px-4 py-3">@if ($row['id'])<a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['title'] ?: 'Untitled product' }}</a>@else<strong>{{ $row['title'] ?: 'Untitled product' }}</strong>@endif</td><td class="px-4 py-3">{{ $row['vendor'] ?: '—' }}@if ($row['type']) · {{ $row['type'] }}@endif</td><td class="px-4 py-3">{{ $row['images'] }}</td><td class="px-4 py-3">{{ $row['variants'] }}</td><td class="px-4 py-3"><div class="flex flex-col gap-1">@foreach ($row['issues'] as $issue)<span>{{ $issue['message'] }}</span>@endforeach</div></td><td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium {{ $row['severity'] === 'critical' ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200' }}">{{ $row['severity'] }}</span></td></tr>
                    @empty<tr><td class="px-4 py-8 text-center text-slate-500" colspan="6">All scanned active products are complete.</td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endif
    </div>
@endsection
