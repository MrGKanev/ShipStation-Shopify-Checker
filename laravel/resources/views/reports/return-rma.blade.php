@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section>
        <p class="text-sm font-medium text-indigo-600">Returns report</p>
        <h1 class="text-3xl font-bold">Return / RMA Tracker</h1>
        <p class="text-slate-500">Item-level return details with a per-SKU refund summary.</p>
    </section>

    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-3" method="POST" action="{{ route('reports.return-rma.store') }}">
        @csrf
        @foreach(['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
            <div>
                <label for="{{ $field }}">{{ $label }}</label>
                <input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                @error($field)<p class="text-red-600">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Scan returns</button>
    </form>

    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">Shopify credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check Shopify and try again.</div>@endif

    @if($result)
        <h2 class="text-2xl font-bold">{{ count($result->rows) }} refunds from {{ $result->scanned }} orders</h2>
        @if($result->truncated)<div class="rounded-xl bg-amber-50 p-4">Results are incomplete: Shopify orders were truncated after {{ $result->pages }} pages.</div>@endif
        <div class="overflow-x-auto rounded-xl border">
            <table class="min-w-full text-left">
                <thead><tr><th class="p-3">Order</th><th>Refund date</th><th>Reason</th><th>Items returned</th><th>Refund total</th></tr></thead>
                <tbody>
                    @forelse($result->rows as $row)
                        <tr>
                            <td class="p-3">{{ $row['order_number'] }}</td>
                            <td>{{ $row['refund_date'] }}</td>
                            <td>{{ $row['reason'] === '' ? '—' : $row['reason'] }}</td>
                            <td>
                                @forelse($row['items'] as $item)
                                    <div>{{ $item['quantity'] }}× {{ $item['name'] }} @if($item['sku'] !== '')<span class="font-mono text-slate-500">({{ $item['sku'] }})</span>@endif</div>
                                @empty
                                    <span class="text-slate-500">No line items</span>
                                @endforelse
                            </td>
                            <td>{{ number_format($row['refund_total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-6 text-center" colspan="5">No returns found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($result->skuStats !== [])
            <h2 class="text-2xl font-bold">Return Rate by SKU</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="min-w-full text-left">
                    <thead><tr><th class="p-3">SKU</th><th>Units returned</th><th>Return events</th><th>Revenue refunded</th></tr></thead>
                    <tbody>@foreach($result->skuStats as $stat)<tr><td class="p-3 font-mono">{{ $stat['sku'] }}</td><td>{{ $stat['units'] }}</td><td>{{ $stat['events'] }}</td><td>{{ number_format($stat['revenue'], 2) }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        @endif
    @endif
</div>
@endsection
