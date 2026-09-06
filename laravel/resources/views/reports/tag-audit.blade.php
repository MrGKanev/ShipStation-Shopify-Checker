@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section>
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Audit report</p>
            <h1 class="mt-1 text-3xl font-bold">Tag audit</h1>
            <p class="mt-2 text-slate-500 dark:text-slate-400">Inventory every order tag, its usage frequency, and the most recent order carrying it.</p>
        </section>

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.tag-audit.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div class="flex items-end"><button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Run report</button></div>
        </form>

        @if ($configurationError)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Shopify credentials are incomplete for the active store.</div>
        @endif
        @if ($reportFailed)
            <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">The report could not be completed. Check Shopify and try again.</div>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <div>
                    <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->tags) }} unique tags</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $result->startDate }} → {{ $result->endDate }}</p>
                </div>
                @if ($result->truncated)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Results were truncated after {{ $result->pages }} pages. Narrow the date range for a complete inventory.</div>
                @endif

                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950"><tr><th class="px-4 py-3">Tag</th><th class="px-4 py-3">Orders</th><th class="px-4 py-3">Last seen</th><th class="px-4 py-3">Last order</th><th class="px-4 py-3">Search</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @forelse ($result->tags as $row)
                                <tr class="{{ $row['orphan'] ? 'text-slate-400' : '' }}">
                                    <td class="px-4 py-3"><code>{{ $row['tag'] }}</code> @if ($row['orphan'])<span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">orphan</span>@endif</td>
                                    <td class="px-4 py-3">{{ $row['count'] }}</td>
                                    <td class="px-4 py-3">{{ $row['last_date'] ?: '—' }}</td>
                                    <td class="px-4 py-3">{{ $row['last_order'] ?: '—' }}</td>
                                    <td class="px-4 py-3"><a class="font-medium text-indigo-600 dark:text-indigo-400" href="{{ route('orders.tag-search', ['tag' => $row['tag']]) }}">Search</a></td>
                                </tr>
                            @empty
                                <tr><td class="px-4 py-8 text-center text-slate-500" colspan="5">No orders in this date range have tags.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
