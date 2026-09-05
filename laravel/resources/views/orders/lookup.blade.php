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
                                    <h3 class="text-lg font-semibold">#{{ $order['orderNumber'] ?? $result->orderNumber }}</h3>
                                    <span class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $order['orderStatus'] ?? 'unknown' }}</span>
                                </div>
                                <p class="text-sm text-slate-600 dark:text-slate-300">{{ $order['customerEmail'] ?? 'No email' }}</p>
                                <p class="text-sm">Total: {{ $order['orderTotal'] ?? '0.00' }}</p>
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
        @endif
    </div>
@endsection
