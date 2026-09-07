@extends('layouts.app')

@section('content')
<div class="flex flex-col gap-6">
<section><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Risk report</p><h1 class="mt-1 text-3xl font-bold">Tag Policy Audit</h1><p class="mt-2 text-slate-500 dark:text-slate-400">Check paid orders against required and forbidden tag combinations.</p></section>
@unless($configured)<div class="rounded-xl bg-amber-50 p-4 text-amber-800">No tag policy is configured. Add required or forbidden rules in <code>config/tag-policy.php</code> to enable this audit.</div>@endunless
<form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.tag-policy.store') }}">@csrf
@foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])<div><label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label><input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>@endforeach
<div class="flex items-end"><button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white disabled:opacity-50" type="submit" @disabled(!$configured)>Run report</button></div></form>
@if($configurationError)<div class="rounded-xl bg-amber-50 p-4 text-amber-800">Shopify credentials are incomplete for the active store.</div>@endif
@if($reportFailed)<div class="rounded-xl bg-red-50 p-4 text-red-800">The report could not be completed. Check Shopify and try again.</div>@endif
@if($result)<section class="flex flex-col gap-4"><h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} policy violations</h2>@if($result->truncated)<div class="rounded-xl bg-amber-50 p-4 text-amber-800">Results are incomplete: orders truncated after {{ $result->pages }} pages.</div>@endif
<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><table class="min-w-full text-left text-sm"><thead><tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Placed</th><th class="px-4 py-3">Violations</th><th class="px-4 py-3">Tags</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Status</th></tr></thead><tbody>
@forelse($result->rows as $row)<tr class="align-top"><td class="px-4 py-3 font-semibold">@if($row['shopify_id'])<a class="text-indigo-600" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['shopify_id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['order_number'] }}</a>@else{{ $row['order_number'] }}@endif</td><td class="px-4 py-3">{{ $row['created_at'] }}</td><td class="px-4 py-3">@foreach($row['violations'] as $violation)<div><span class="font-medium">{{ $violation['name'] }}</span><br><span class="text-slate-500">{{ $violation['detail'] }}</span></div>@endforeach</td><td class="px-4 py-3">{{ implode(', ', $row['tags']) }}</td><td class="px-4 py-3">{{ $row['email'] }}</td><td class="px-4 py-3">{{ $row['financial'] ?: '—' }}@if($row['fulfillment']) · {{ str_replace('_', ' ', $row['fulfillment']) }}@endif</td></tr>
@empty<tr><td class="px-4 py-8 text-center text-slate-500" colspan="6">No scanned orders violated the configured tag policy.</td></tr>@endforelse
</tbody></table></div></section>@endif
</div>
@endsection
