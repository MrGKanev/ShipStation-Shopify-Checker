@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Read-only workflow</p><h1 class="text-3xl font-bold">Tag search</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Find orders with an exact Shopify tag. Matching is case-insensitive and the date range is optional.</p></section>
        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('orders.tag-search.store') }}">@csrf
            <div class="sm:col-span-3"><label class="text-sm font-medium" for="tag">Tag</label><input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="tag" name="tag" maxlength="255" value="{{ old('tag', $tag) }}">@error('tag')<p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror</div>
            <div><label class="text-sm font-medium" for="start_date">From (optional)</label><input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="start_date" name="start_date" type="date" value="{{ old('start_date', $startDate) }}">@error('start_date')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="text-sm font-medium" for="end_date">To (optional)</label><input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="end_date" name="end_date" type="date" value="{{ old('end_date', $endDate) }}">@error('end_date')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            <div class="flex items-end"><button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white" type="submit">Search</button></div>
        </form>
        @if($searchFailed)<div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800" role="alert">The tag search could not be completed. Check the Shopify integration and try again.</div>@endif
        @if($configurationError)<div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800" role="alert">Shopify is not configured completely for this store.</div>@endif
        @if($result !== null)<section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3"><h2 class="text-2xl font-bold">Results</h2><span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold dark:bg-slate-800">{{ count($result->orders) }} found</span><span class="text-sm text-slate-500">Tag: {{ $result->tag }}@if($result->startDate || $result->endDate) · {{ $result->startDate ?: '…' }} → {{ $result->endDate ?: '…' }}@endif</span></div>
            @if($result->truncated)<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800" role="status">Results were truncated after {{ $result->pages }} pages. Add a date range to narrow the search.</div>@endif
            @forelse($result->orders as $order)<article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3"><div>@if($order['id'] !== '')<a class="text-lg font-semibold text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $order['id'] }}" target="_blank" rel="noopener noreferrer">{{ $order['name'] }}</a>@else<strong class="text-lg">{{ $order['name'] }}</strong>@endif<p class="text-sm text-slate-500">{{ $order['created_at'] }} · {{ $order['email'] ?: 'No email' }}</p></div><div class="text-right text-sm"><div>{{ $order['financial_status'] ?: 'unknown' }} · {{ $order['fulfillment_status'] ?: 'unfulfilled' }}</div><div>{{ $order['total_price'] }} {{ $order['currency'] }}</div></div></div>
                <div class="mt-3 flex flex-wrap gap-2">@foreach($order['tags'] as $orderTag)<span @class(['rounded-full px-2.5 py-1 text-xs', 'bg-indigo-600 text-white' => mb_strtolower($orderTag) === mb_strtolower($result->tag), 'bg-slate-100 dark:bg-slate-800' => mb_strtolower($orderTag) !== mb_strtolower($result->tag)])>{{ $orderTag }}</span>@endforeach</div>
                <a class="mt-4 inline-flex text-sm font-medium text-indigo-600" href="{{ route('orders.spot-check', ['prefill' => $order['order_number']]) }}">Open in spot-check</a>
            </article>@empty<div class="rounded-xl border border-slate-200 bg-white p-5 text-slate-500">No orders found with this tag.</div>@endforelse
        </section>@endif
    </div>
@endsection
