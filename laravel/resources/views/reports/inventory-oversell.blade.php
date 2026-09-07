@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section>
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Inventory report</p>
            <h1 class="mt-1 text-3xl font-bold">Inventory oversell risk</h1>
            <p class="mt-2 text-slate-500 dark:text-slate-400">Compare active Shopify stock with every ShipStation order awaiting shipment and find SKUs that cannot cover current demand.</p>
        </section>

        <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.inventory-oversell.store') }}">
            @csrf
            <button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Scan inventory</button>
        </form>

        @if ($shopifyConfigurationError)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Shopify credentials are incomplete for the active store.</div>
        @endif
        @if ($shipStationConfigurationError)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">ShipStation credentials are incomplete for the active store.</div>
        @endif
        @if ($reportFailed)
            <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">The report could not be completed. Check Shopify and ShipStation, then try again.</div>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->products }} products · {{ $result->awaitingOrders }} awaiting orders</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ count($result->rows) }} SKUs at risk of overselling</p>
                @if ($result->productsTruncated)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Results are incomplete: product catalogue truncated after {{ $result->productPages }} pages.</div>
                @endif

                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950"><tr><th class="px-4 py-3">Product / variant</th><th class="px-4 py-3">SKU</th><th class="px-4 py-3">Stock</th><th class="px-4 py-3">Awaiting</th><th class="px-4 py-3">Shortfall</th><th class="px-4 py-3">Action</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @forelse ($result->rows as $row)
                                <tr>
                                    <td class="px-4 py-3">
                                        @if ($row['product_id'])
                                            <a class="font-semibold text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/products/{{ $row['product_id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['product_title'] ?: 'Untitled product' }}</a>
                                        @else
                                            <strong>{{ $row['product_title'] ?: 'Untitled product' }}</strong>
                                        @endif
                                        @if ($row['variant_title'])<div class="text-slate-500">{{ $row['variant_title'] }}</div>@endif
                                    </td>
                                    <td class="px-4 py-3 font-mono">{{ $row['sku'] }}</td>
                                    <td class="px-4 py-3">{{ $row['stock'] }}</td>
                                    <td class="px-4 py-3">{{ $row['awaiting'] }}</td>
                                    <td class="px-4 py-3 font-bold text-red-600 dark:text-red-400">{{ $row['shortfall'] }}</td>
                                    <td class="px-4 py-3">@if ($row['duplicate_sku'])<a class="text-indigo-600 dark:text-indigo-400" href="{{ route('reports.sku-duplicates') }}">Review duplicates</a>@endif</td>
                                </tr>
                            @empty
                                <tr><td class="px-4 py-8 text-center text-slate-500" colspan="6">Current tracked stock covers every SKU awaiting shipment.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
