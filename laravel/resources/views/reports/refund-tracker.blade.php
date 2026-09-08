@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
    <section>
        <p class="text-sm font-medium text-indigo-600">Risk report</p>
        <h1 class="text-3xl font-bold">Refunds Tracker</h1>
        <p class="text-slate-500">Refunded Shopify orders cross-checked against ShipStation status.</p>
    </section>

    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-3" method="POST" action="{{ route('reports.refund-tracker.store') }}">
        @csrf
        @foreach(['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
            <div>
                <label for="{{ $field }}">{{ $label }}</label>
                <input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                @error($field)<p class="text-red-600">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run report</button>
    </form>

    @if($shopifyConfigurationError)<div class="rounded-xl bg-amber-50 p-4">Shopify credentials are incomplete for the active store.</div>@endif
    @if($shipStationConfigurationWarning)<div class="rounded-xl bg-amber-50 p-4">ShipStation credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check the integrations and try again.</div>@endif

    @if($result)
        <section class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border p-4"><p class="text-sm text-slate-500">Refunded orders</p><p class="text-2xl font-bold">{{ $result->scanned }}</p></div>
            <div class="rounded-xl border p-4"><p class="text-sm text-slate-500">Still active in ShipStation</p><p class="text-2xl font-bold text-red-600">{{ $result->active }}</p></div>
            <div class="rounded-xl border p-4"><p class="text-sm text-slate-500">Missing in ShipStation</p><p class="text-2xl font-bold text-amber-600">{{ $result->missing }}</p></div>
        </section>
        @if(!$result->hasShipStation)<div class="rounded-xl bg-blue-50 p-4">ShipStation is not configured. Shopify refunds are shown without a cross-check.</div>@endif
        @if($result->truncated)<div class="rounded-xl bg-amber-50 p-4">Results are incomplete: Shopify orders were truncated after {{ $result->pages }} pages.</div>@endif

        <div class="overflow-x-auto rounded-xl border">
            <table class="min-w-full text-left">
                <thead><tr><th class="p-3">Order</th><th>Date</th><th>Email</th><th>Refunded</th><th>ShipStation</th><th>Risk</th></tr></thead>
                <tbody>
                    @forelse($result->rows as $row)
                        <tr>
                            <td class="p-3">{{ $row['order_number'] }}</td>
                            <td>{{ $row['created_at'] }}</td>
                            <td>{{ $row['email'] }}</td>
                            <td>{{ number_format($row['refunded_amount'], 2) }}</td>
                            <td>{{ $row['shipstation_statuses'] === [] ? '—' : implode(', ', $row['shipstation_statuses']) }}</td>
                            <td>{{ ucfirst($row['risk']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-6 text-center" colspan="6">No refunded orders found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
