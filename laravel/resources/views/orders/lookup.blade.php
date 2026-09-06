@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section class="flex flex-col gap-2">
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Read-only workflow</p>
            <h1 class="text-3xl font-bold">Order lookup</h1>
            <p class="text-slate-500 dark:text-slate-400">Search the active store in Shopify and ShipStation without changing either system.</p>
        </section>

        <form class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5 sm:flex-row sm:items-end dark:border-slate-800 dark:bg-slate-900" method="GET" action="{{ route('orders.lookup') }}">
            <div class="flex grow flex-col gap-2">
                <label class="text-sm font-medium" for="order_number">Order number</label>
                <input
                    class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950"
                    id="order_number"
                    name="order_number"
                    value="{{ old('order_number', $orderNumber) }}"
                    placeholder="#65075"
                    maxlength="64"
                >
                @error('order_number')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Search</button>
        </form>

        @if ($lookupFailed)
            <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert">
                The order lookup could not be completed. Check the store integrations and try again.
            </div>
        @endif

        @if ($result !== null)
            <section class="grid gap-5 xl:grid-cols-2">
                <div class="flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-bold">Shopify</h2>
                        <span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold dark:bg-slate-800">{{ count($result->shopifyOrders) }} found</span>
                    </div>

                    @forelse ($result->shopifyOrders as $order)
                        <article class="grid gap-3 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="text-lg font-semibold">{{ $order['name'] ?? ('#'.$result->orderNumber) }}</h3>
                                <span class="text-sm font-medium capitalize text-slate-500 dark:text-slate-400">{{ $order['financial_status'] ?? 'unknown' }}</span>
                            </div>
                            <p class="text-sm text-slate-600 dark:text-slate-300">{{ $order['email'] ?? 'No email' }}</p>
                            <div class="flex flex-wrap gap-4 text-sm">
                                <span>Total: {{ $order['total_price'] ?? '0.00' }}</span>
                                <span>Fulfillment: {{ $order['fulfillment_status'] ?? 'unfulfilled' }}</span>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-white p-5 text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">No Shopify order found.</div>
                    @endforelse
                </div>

                <div class="flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-bold">ShipStation</h2>
                        @if ($result->shipStationConfigured)
                            <span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold dark:bg-slate-800">{{ count($result->shipStationOrders) }} found</span>
                        @endif
                    </div>

                    @if (! $result->shipStationConfigured)
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">ShipStation credentials are not configured for this store.</div>
                    @else
                        @forelse ($result->shipStationOrders as $order)
                            <article class="grid gap-3 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                <div class="flex items-start justify-between gap-3">
                                    <h3 class="text-lg font-semibold">#{{ $order['order_number'] ?: $result->orderNumber }}</h3>
                                    <span class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $order['status'] }}</span>
                                </div>
                                <p class="text-sm text-slate-600 dark:text-slate-300">{{ $order['customer_email'] ?? 'No email' }}</p>
                                <p class="text-sm">Total: {{ $order['total'] ?? '—' }}</p>
                            </article>
                        @empty
                            <div class="rounded-xl border border-slate-200 bg-white p-5 text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">No ShipStation order found.</div>
                        @endforelse

                        @if ($result->shipStationShipments !== [])
                            <div class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                <h3 class="font-semibold">Shipments</h3>
                                @foreach ($result->shipStationShipments as $shipment)
                                    <p class="text-sm text-slate-600 dark:text-slate-300">
                                        {{ $shipment['carrierCode'] ?? 'Carrier' }} · {{ $shipment['trackingNumber'] ?? 'No tracking number' }}
                                    </p>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <div>
                    <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Cross-platform checks</p>
                    <h2 class="text-2xl font-bold">Detailed comparison</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Items compare Shopify ordered quantities with ShipStation order items. Statuses remain separate and only established unsafe combinations are flagged.</p>
                </div>

                @if ($result->comparisonState === 'not_configured')
                    <div class="rounded-xl border border-slate-200 bg-white p-5 text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">Configure ShipStation to enable detailed comparison.</div>
                @elseif ($result->comparisonState === 'shopify_missing')
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Comparison unavailable because the order was not found in Shopify.</div>
                @elseif ($result->comparisonState === 'shipstation_missing')
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Comparison unavailable because the order was not found in ShipStation.</div>
                @elseif ($result->comparisonState === 'ambiguous')
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">Multiple matching records were found. No record was selected automatically.</div>
                @elseif ($result->comparison !== null)
                    @foreach ($result->comparison['warnings'] as $warning)
                        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert">
                            {{ $warning['message'] }}
                        </div>
                    @endforeach

                    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead>
                                <tr class="text-left">
                                    <th class="px-4 py-3 font-semibold">Field</th>
                                    <th class="px-4 py-3 font-semibold">Shopify</th>
                                    <th class="px-4 py-3 font-semibold">ShipStation</th>
                                    <th class="px-4 py-3 font-semibold">Result</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                @foreach ($result->comparison['fields'] as $field)
                                    <tr @class(['bg-amber-50 dark:bg-amber-950/30' => in_array($field['state'], ['different', 'missing'], true)])>
                                        <td class="px-4 py-3 font-medium">{{ $field['label'] }}</td>
                                        <td class="px-4 py-3">{{ $field['shopify'] }}</td>
                                        <td class="px-4 py-3">{{ $field['shipstation'] }}</td>
                                        <td class="px-4 py-3 capitalize">{{ $field['state'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h3 class="font-semibold">Shopify ordered SKU quantities</h3>
                            <div class="mt-3 flex flex-col gap-1 text-sm">
                                @forelse ($result->comparison['items']['shopify'] as $sku => $quantity)
                                    <p><span class="font-mono">{{ $sku }}</span> · {{ $quantity }}</p>
                                @empty
                                    <p class="text-slate-500 dark:text-slate-400">No comparable SKU values.</p>
                                @endforelse
                            </div>
                        </article>
                        <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h3 class="font-semibold">ShipStation order SKU quantities</h3>
                            <div class="mt-3 flex flex-col gap-1 text-sm">
                                @forelse ($result->comparison['items']['shipstation'] as $sku => $quantity)
                                    <p><span class="font-mono">{{ $sku }}</span> · {{ $quantity }}</p>
                                @empty
                                    <p class="text-slate-500 dark:text-slate-400">No comparable SKU values.</p>
                                @endforelse
                            </div>
                        </article>
                    </div>

                    @if ($result->comparison['items']['state'] === 'different')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950">
                                <h3 class="font-semibold text-red-800 dark:text-red-200">Missing from ShipStation</h3>
                                @forelse ($result->comparison['items']['missing'] as $sku => $quantity)
                                    <p class="mt-2 text-sm text-red-700 dark:text-red-300"><span class="font-mono">{{ $sku }}</span> · {{ $quantity }}</p>
                                @empty
                                    <p class="mt-2 text-sm text-red-700 dark:text-red-300">None</p>
                                @endforelse
                            </div>
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950">
                                <h3 class="font-semibold text-amber-800 dark:text-amber-200">Extra in ShipStation</h3>
                                @forelse ($result->comparison['items']['extra'] as $sku => $quantity)
                                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300"><span class="font-mono">{{ $sku }}</span> · {{ $quantity }}</p>
                                @empty
                                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">None</p>
                                @endforelse
                            </div>
                        </div>
                    @else
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">SKU quantities match.</div>
                    @endif
                @endif
            </section>
        @endif
    </div>
@endsection
