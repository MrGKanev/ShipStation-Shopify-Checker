@extends('layouts.app')
@section('content')
<style>@media print { header, aside, .print-hidden { display: none !important; } main { width: 100%; } body { background: white; color: black; } }</style>
<div class="flex flex-col gap-6">
    <section class="print-hidden"><p class="text-sm font-medium text-indigo-600">Read-only workflow</p><h1 class="text-3xl font-bold">Packing slip preview</h1></section>
    <form class="print-hidden rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('orders.packing-slip.store') }}">@csrf
        <label class="text-sm font-medium" for="order_number">Order number</label><input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="order_number" name="order_number" value="{{ old('order_number', $orderNumber) }}">
        @error('order_number')<p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
        <button class="mt-4 rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white" type="submit">Preview</button>
    </form>
    @if($configurationError)<div class="print-hidden rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800" role="alert">ShipStation is not configured completely for this store.</div>@endif
    @if($lookupFailed)<div class="print-hidden rounded-xl border border-red-200 bg-red-50 p-5 text-red-800" role="alert">The packing slip could not be loaded. Try again.</div>@endif
    @if($result && $result['status'] === 'not_found')<div class="print-hidden rounded-xl border p-5" role="status">Order #{{ $orderNumber }} was not found in ShipStation.</div>@endif
    @if($result && $result['status'] === 'ambiguous')<div class="print-hidden rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800" role="alert">More than one exact ShipStation order matched. Refine the order number.</div>@endif
    @if($result && $result['status'] === 'ready') @php($slip = $result['slip'])
    <article class="rounded-xl border border-slate-300 bg-white p-8 text-slate-950">
        <div class="flex items-start justify-between"><div><h2 class="text-3xl font-bold">Packing Slip</h2><p class="mt-1 text-lg">Order #{{ $slip['orderNumber'] }}</p></div><button class="print-hidden rounded-lg bg-slate-900 px-4 py-2 text-white" type="button" onclick="window.print()">Print</button></div>
        <div class="mt-8 grid gap-8 sm:grid-cols-2"><section><h3 class="font-bold uppercase">Ship to</h3>@foreach($slip['shipTo'] as $line)<div>{{ $line }}</div>@endforeach</section><section><div>Order date: {{ $slip['orderDate'] ?: '—' }}</div><div>Ship by: {{ $slip['shipByDate'] ?: '—' }}</div><div>Customer: {{ $slip['customerUsername'] ?: '—' }}</div></section></div>
        <table class="mt-8 w-full border-collapse"><thead><tr class="border-b"><th class="py-2 text-left">Description</th><th class="py-2 text-right">Qty</th></tr></thead><tbody>@foreach($slip['items'] as $item)<tr class="border-b align-top"><td class="py-3"><strong>{{ $item['name'] }}</strong>@foreach($item['options'] as $option)<div @class(['text-sm', 'font-semibold text-amber-700' => $option['highlighted']])>{{ $option['name'] }}: {{ $option['value'] }}</div>@endforeach</td><td class="py-3 text-right">{{ $item['quantity'] }}</td></tr>@endforeach</tbody></table>
        @if($slip['notes'] !== [])<section class="mt-8"><h3 class="font-bold uppercase">Notes</h3>@foreach($slip['notes'] as $note)<p class="mt-1">{{ $note }}</p>@endforeach</section>@endif
    </article>@endif
</div>
@endsection
