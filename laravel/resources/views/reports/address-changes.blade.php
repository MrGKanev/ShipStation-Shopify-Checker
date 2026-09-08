@extends('layouts.app')
@section('content')
<div class="flex flex-col gap-6">
    <section><p class="text-sm font-medium text-indigo-600">Risk report</p><h1 class="text-3xl font-bold">Address Changes</h1><p class="text-slate-500">Orders whose shipping address was edited after placement.</p></section>
    <form class="grid gap-4 rounded-xl border p-5 sm:grid-cols-3" method="POST" action="{{ route('reports.address-changes.store') }}">@csrf
        @foreach(['start_date'=>['From',$startDate],'end_date'=>['To',$endDate]] as $field=>[$label,$value])<div><label for="{{ $field }}">{{ $label }}</label><input class="w-full rounded-lg border px-3 py-2" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field,$value) }}">@error($field)<p class="text-red-600">{{ $message }}</p>@enderror</div>@endforeach
        <div class="flex gap-2"><button class="rounded-lg bg-indigo-600 px-5 py-2 text-white">Run report</button><button class="rounded-lg border px-5 py-2" formaction="{{ route('reports.address-changes.export') }}">Download CSV</button></div>
    </form>
    @error('export')<div class="rounded-xl bg-red-50 p-4">{{ $message }}</div>@enderror
    @if($configurationError)<div class="rounded-xl bg-amber-50 p-4">Shopify credentials are incomplete for the active store.</div>@endif
    @if($reportFailed)<div class="rounded-xl bg-red-50 p-4">The report could not be completed. Check Shopify and try again.</div>@endif
    @if($result)
        <h2 class="text-2xl font-bold">{{ count($result->rows) }} orders with address changes</h2>
        @if($result->truncated)<div class="rounded-xl bg-amber-50 p-4">Results are incomplete: events truncated after {{ $result->pages }} pages.</div>@endif
        <div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">Order</th><th>Placed</th><th>Changed</th><th>Time gap</th><th>Email</th><th>Current shipping address</th><th>Total</th><th>Status</th></tr></thead><tbody>
            @forelse($result->rows as $row)<tr><td class="p-3">@if($row['shopify_id'] !== '')<a href="https://admin.shopify.com/store/{{ rawurlencode($activeStore->shopify_store) }}/orders/{{ $row['shopify_id'] }}">{{ $row['order_number'] }}</a>@else{{ $row['order_number'] }}@endif</td><td>{{ $row['created_at'] }}</td><td>{{ $row['changed_at'] }}</td><td>{{ $row['gap_mins'] }} minutes</td><td>{{ $row['email'] }}</td><td><strong>{{ $row['addr_name'] }}</strong><br>{{ $row['addr_line'] }}</td><td>{{ number_format($row['total'], 2) }}</td><td>{{ $row['financial'] }} {{ $row['fulfillment'] }}</td></tr>@empty<tr><td class="p-6 text-center" colspan="8">No address changes found.</td></tr>@endforelse
        </tbody></table></div>
    @endif
</div>
@endsection
