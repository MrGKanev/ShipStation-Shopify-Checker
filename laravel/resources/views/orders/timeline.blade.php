@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section class="flex flex-col gap-2">
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Read-only workflow</p>
            <h1 class="text-3xl font-bold">Order timeline</h1>
            <p class="text-slate-500 dark:text-slate-400">Review Shopify and ShipStation activity in one chronological view, with operational and order-risk signals.</p>
        </section>

        <form class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:flex-row sm:items-end dark:border-slate-800 dark:bg-slate-900" method="GET" action="{{ route('orders.timeline') }}">
            <div class="flex flex-1 flex-col gap-2">
                <label class="text-sm font-medium" for="order_number">Order number</label>
                <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="order_number" name="order_number" value="{{ old('order_number', $orderNumber) }}" placeholder="#100042" maxlength="64">
                @error('order_number')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <button class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">Load timeline</button>
        </form>

        @if ($timelineFailed)
            <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert">
                The order timeline could not be completed. Check the store integrations and try again.
            </div>
        @endif

        @if ($result !== null && $result->state === 'not_found')
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200" role="status">
                No Shopify order was found for #{{ $result->orderNumber }}.
            </div>
        @elseif ($result !== null && $result->state === 'ambiguous')
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200" role="alert">
                Shopify returned {{ $result->shopifyMatchCount }} matches for #{{ $result->orderNumber }}. No ambiguous order was selected automatically.
            </div>
        @elseif ($result !== null && $result->state === 'ready')
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Order</p>
                    <p class="mt-2 text-lg font-semibold">{{ $result->order['name'] ?? '#'.$result->orderNumber }}</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $result->order['email'] ?? 'No email' }}</p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Risk score</p>
                    <p class="mt-2 text-lg font-semibold">{{ $result->riskScore['score'] }} · {{ ucfirst($result->riskScore['level']) }}</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ count($result->riskScore['signals']) }} detected {{ count($result->riskScore['signals']) === 1 ? 'signal' : 'signals' }}</p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Time to ship</p>
                    <p class="mt-2 text-lg font-semibold">{{ $result->timeToShip === null ? 'Not shipped' : $result->timeToShip.' days' }}</p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-sm text-slate-500 dark:text-slate-400">ShipStation</p>
                    <p class="mt-2 text-lg font-semibold">{{ $result->shipStationConfigured ? count($result->shipStationOrders).' order matches' : 'Not configured' }}</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ count($result->shipStationShipments) }} shipments</p>
                </article>
            </section>

            @if ($result->riskScore['signals'] !== [] || $result->operationalRisks !== [])
                <section class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-lg font-semibold">Order-risk signals</h2>
                        @if ($result->riskScore['signals'] === [])
                            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No order-risk signals detected.</p>
                        @else
                            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm">
                                @foreach ($result->riskScore['signals'] as $signal)
                                    <li>{{ $signal }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-lg font-semibold">Operational risks</h2>
                        @if ($result->operationalRisks === [])
                            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No operational risks detected.</p>
                        @else
                            <ul class="mt-3 space-y-2 text-sm">
                                @foreach ($result->operationalRisks as $risk)
                                    <li @class([
                                        'rounded-lg border px-3 py-2',
                                        'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200' => $risk['level'] === 'danger',
                                        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200' => $risk['level'] === 'warn',
                                        'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200' => $risk['level'] === 'info',
                                    ])>{{ $risk['message'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            @endif

            <section class="flex flex-col gap-4">
                <div>
                    <h2 class="text-2xl font-bold">Activity</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Newest activity first · {{ count($result->timeline) }} entries</p>
                </div>

                @forelse ($result->timeline as $item)
                    <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-col justify-between gap-2 sm:flex-row">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold">{{ $item['title'] }}</h3>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium uppercase text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $item['source'] }}</span>
                                </div>
                                @if ($item['detail'] !== '')
                                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $item['detail'] }}</p>
                                @endif
                                @if ($item['tracking'] !== '')
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Tracking: {{ $item['tracking'] }}</p>
                                @endif
                            </div>
                            <time class="text-sm text-slate-500 dark:text-slate-400" datetime="{{ $item['timestamp'] }}">{{ $item['formatted_at'] }}</time>
                        </div>

                        @if ($item['url'] !== '')
                            <a class="mt-3 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer">Open details</a>
                        @endif
                    </article>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-white p-5 text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
                        No timestamped activity is available for this order.
                    </div>
                @endforelse
            </section>
        @endif
    </div>
@endsection
