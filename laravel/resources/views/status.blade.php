@extends('layouts.guest')

@section('content')
<section class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">Shopify Ops</p>
    <div class="mt-3 flex items-center justify-between gap-4"><h1 class="text-3xl font-bold">System status</h1><span class="rounded-full px-3 py-1 text-sm font-semibold {{ $ready ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">{{ $ready ? 'Operational' : 'Needs attention' }}</span></div>
    <p class="mt-2 text-sm text-slate-500">Checked at {{ $checkedAt->toIso8601String() }}</p>
    <dl class="mt-6 divide-y divide-slate-200 dark:divide-slate-800">@foreach($checks as $name => $ok)<div class="flex justify-between py-4"><dt class="capitalize">{{ $name }}</dt><dd class="font-semibold {{ $ok ? 'text-emerald-600' : 'text-red-600' }}">{{ $ok ? 'Operational' : 'Unavailable' }}</dd></div>@endforeach</dl>
</section>
@endsection
