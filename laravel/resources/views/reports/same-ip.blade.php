@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
<section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Risk report</p><h1 class="mt-1 text-3xl font-bold">Same IP, Different Emails</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Find paid orders where two or more distinct customer emails share the exact client IP. Shared networks can be legitimate, so treat clusters as review signals.</p></section>
<form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.same-ip.store') }}">@csrf
@foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])<div><label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label><input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>@endforeach
<div class="flex items-end"><button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white" type="submit">Run report</button></div></form>
@if($configurationError)<div class="rounded-xl bg-amber-50 p-4 text-amber-800">Shopify credentials are incomplete for the active store.</div>@endif
@if($reportFailed)<div class="rounded-xl bg-red-50 p-4 text-red-800">The report could not be completed. Check Shopify and try again.</div>@endif
@if($result)<section class="flex flex-col gap-4"><h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} shared IPs</h2>@if($result->truncated)<div class="rounded-xl bg-amber-50 p-4 text-amber-800">Results are incomplete: orders truncated after {{ $result->pages }} pages.</div>@endif
<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><table class="min-w-full text-left text-sm"><thead><tr><th class="px-4 py-3">Client IP</th><th class="px-4 py-3">Emails</th><th class="px-4 py-3">Orders</th><th class="px-4 py-3">Details</th></tr></thead><tbody>
@forelse($result->rows as $row)<tr class="align-top"><td class="px-4 py-3 font-semibold">{{ $row['ip'] }}</td><td class="px-4 py-3">{{ $row['email_count'] }}<ul>@foreach($row['emails'] as $email)<li>{{ $email }}</li>@endforeach</ul></td><td class="px-4 py-3">{{ $row['order_count'] }}</td><td class="px-4 py-3"><ul>@foreach($row['orders'] as $order)<li>@if($order['id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $order['id'] }}" target="_blank" rel="noopener noreferrer">{{ $order['number'] }}</a>@else{{ $order['number'] }}@endif · {{ $order['created_at'] }} · {{ $order['email'] }} · {{ number_format($order['total'], 2) }} {{ $order['currency'] }}</li>@endforeach</ul></td></tr>
@empty<tr><td class="px-4 py-8 text-center text-slate-500" colspan="4">Every client IP in this range was used by only one customer email.</td></tr>@endforelse
</tbody></table></div></section>@endif
</div>
@endsection
