@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section class="flex flex-col gap-2">
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Read-only workflow</p>
            <h1 class="text-3xl font-bold">Spot-check orders</h1>
            <p class="text-slate-500 dark:text-slate-400">Look up 1–50 order numbers live in Shopify, ShipStation, or both. Separate numbers with spaces, commas, or new lines.</p>
        </section>

        <form class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('orders.spot-check.store') }}">
            @csrf

            <div class="flex flex-col gap-2">
                <label class="text-sm font-medium" for="orders">Order numbers</label>
                <textarea class="min-h-36 rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="orders" name="orders" placeholder="#100042&#10;#100043&#10;#100044" maxlength="4096">{{ old('orders', $ordersInput) }}</textarea>
                @error('orders')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <fieldset class="flex flex-col gap-2">
                <legend class="text-sm font-medium">Sources</legend>
                <div class="flex flex-wrap gap-4 text-sm">
                    @foreach (['both' => 'Shopify & ShipStation', 'shopify' => 'Shopify only', 'shipstation' => 'ShipStation only'] as $value => $label)
                        <label class="flex items-center gap-2">
                            <input name="mode" type="radio" value="{{ $value }}" @checked(old('mode', $mode) === $value)>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('mode')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </fieldset>

            <div>
                <button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Run spot-check</button>
            </div>
        </form>

        @if ($configurationError)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200" role="alert">
                ShipStation is not configured completely for this store. Choose Shopify only or update the store credentials.
            </div>
        @endif

        @if ($lookupFailed)
            <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert">
                The spot-check could not be completed. Check the selected integrations and try again.
            </div>
        @endif

        @if ($result !== null)
            <section class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-2xl font-bold">Results</h2>
                    <span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold dark:bg-slate-800">{{ count($result->results) }} checked</span>
                    @if (in_array($result->mode, ['both', 'shopify'], true))
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Shopify: {{ $result->shopifyFoundCount }}/{{ count($result->results) }} found</span>
                    @endif
                    @if (in_array($result->mode, ['both', 'shipstation'], true))
                        <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-200">ShipStation: {{ $result->shipStationFoundCount }}/{{ count($result->results) }} found</span>
                    @endif
                </div>

                @foreach ($result->results as $row)
                    <article @class([
                        'rounded-xl border bg-white p-5 dark:bg-slate-900',
                        'border-emerald-300 dark:border-emerald-800' => in_array($row['status'], ['Found', 'Both found'], true),
                        'border-amber-300 dark:border-amber-800' => in_array($row['status'], ['Shopify only', 'ShipStation only'], true),
                        'border-red-300 dark:border-red-900' => $row['status'] === 'Not found',
                    ])>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold">#{{ $row['number'] }}</h3>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold dark:bg-slate-800">{{ $row['status'] }}</span>
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            @if ($row['shopify_orders'] !== null)
                                <div class="flex flex-col gap-2">
                                    <h4 class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Shopify</h4>
                                    @forelse ($row['shopify_orders'] as $order)
                                        <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-950">
                                            <div class="flex flex-wrap items-center gap-2">
                                                @if ($order['url'] !== '')
                                                    <a class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="{{ $order['url'] }}" target="_blank" rel="noopener noreferrer">{{ $order['name'] ?? '#'.$row['number'] }}</a>
                                                @else
                                                    <span class="font-semibold">{{ $order['name'] ?? '#'.$row['number'] }}</span>
                                                @endif
                                                <span>{{ $order['financial_status'] ?? 'unknown' }}</span>
                                                <span>{{ $order['total_price'] ?? '0.00' }} {{ $order['currency'] ?? '' }}</span>
                                            </div>
                                            @if (isset($row['risk_scores'][(string) ($order['id'] ?? '')]))
                                                @php($risk = $row['risk_scores'][(string) $order['id']])
                                                <div class="mt-2 text-xs text-slate-600 dark:text-slate-300">
                                                    Risk: {{ $risk['score'] }} · {{ ucfirst($risk['level']) }}
                                                    @if ($risk['signals'] !== [])
                                                        · {{ implode(', ', $risk['signals']) }}
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-sm text-slate-500 dark:text-slate-400">Not found in Shopify.</p>
                                    @endforelse
                                </div>
                            @endif

                            @if ($row['shipstation_orders'] !== null)
                                <div class="flex flex-col gap-2">
                                    <h4 class="text-sm font-semibold text-blue-700 dark:text-blue-300">ShipStation</h4>
                                    @forelse ($row['shipstation_orders'] as $order)
                                        <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-950">
                                            <div class="flex flex-wrap items-center gap-2">
                                                @if ($order['url'] !== '')
                                                    <a class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="{{ $order['url'] }}" target="_blank" rel="noopener noreferrer">#{{ $order['orderNumber'] ?? $row['number'] }}</a>
                                                @else
                                                    <span class="font-semibold">#{{ $order['orderNumber'] ?? $row['number'] }}</span>
                                                @endif
                                                <span>{{ $order['orderStatus'] ?? 'unknown' }}</span>
                                                <span>{{ array_key_exists('orderTotal', $order) ? $order['orderTotal'] : '—' }}</span>
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-sm text-slate-500 dark:text-slate-400">Not found in ShipStation.</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>

                        <a class="mt-4 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="{{ route('orders.lookup', ['order_number' => $row['number']]) }}">Open detailed comparison</a>
                    </article>
                @endforeach
            </section>
        @endif
    </div>
@endsection
