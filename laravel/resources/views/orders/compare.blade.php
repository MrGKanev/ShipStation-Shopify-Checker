@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section class="flex flex-col gap-2">
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Read-only workflow</p>
            <h1 class="text-3xl font-bold">Order compare</h1>
            <p class="text-slate-500 dark:text-slate-400">Place two Shopify orders side by side. Different values are highlighted, with ShipStation status included when configured.</p>
        </section>

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end dark:border-slate-800 dark:bg-slate-900" method="GET" action="{{ route('orders.compare') }}">
            <div class="flex flex-col gap-2">
                <label class="text-sm font-medium" for="order_a">Order A</label>
                <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="order_a" name="order_a" value="{{ old('order_a', $numberA) }}" placeholder="#100042" maxlength="64">
                @error('order_a')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label class="text-sm font-medium" for="order_b">Order B</label>
                <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="order_b" name="order_b" value="{{ old('order_b', $numberB) }}" placeholder="#100043" maxlength="64">
                @error('order_b')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Compare</button>
        </form>

        @if ($comparisonFailed)
            <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert">
                The order comparison could not be completed. Check the store integrations and try again.
            </div>
        @endif

        @if ($result !== null)
            <section class="flex flex-col gap-4">
                <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
                    <div>
                        <h2 class="text-2xl font-bold">Comparison</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $result->differenceCount }} {{ $result->differenceCount === 1 ? 'difference' : 'differences' }}</p>
                    </div>
                    @if (! $result->shipStationConfigured)
                        <p class="text-sm text-amber-700 dark:text-amber-300">ShipStation is not configured for this store.</p>
                    @endif
                </div>

                @if ($result->shopifyMatchCountA > 1 || $result->shopifyMatchCountB > 1)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200" role="alert">
                        Multiple Shopify matches were found for at least one order number. No ambiguous record was selected automatically.
                    </div>
                @endif

                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead>
                            <tr class="text-left">
                                <th class="px-4 py-3 font-semibold">Field</th>
                                <th class="px-4 py-3 font-semibold">
                                    #{{ $result->numberA }}
                                    @if ($result->shopifyMatchCountA === 0)
                                        <span class="font-normal text-slate-500">(not found)</span>
                                    @elseif ($result->shopifyMatchCountA > 1)
                                        <span class="font-normal text-amber-700">({{ $result->shopifyMatchCountA }} matches)</span>
                                    @endif
                                </th>
                                <th class="px-4 py-3 font-semibold">
                                    #{{ $result->numberB }}
                                    @if ($result->shopifyMatchCountB === 0)
                                        <span class="font-normal text-slate-500">(not found)</span>
                                    @elseif ($result->shopifyMatchCountB > 1)
                                        <span class="font-normal text-amber-700">({{ $result->shopifyMatchCountB }} matches)</span>
                                    @endif
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach ($result->rows as $row)
                                <tr @class(['bg-amber-50 dark:bg-amber-950/30' => $row['different']])>
                                    <td class="px-4 py-3 font-medium">{{ $row['label'] }}</td>
                                    <td class="px-4 py-3">{{ $row['a'] }}</td>
                                    <td class="px-4 py-3">{{ $row['b'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
