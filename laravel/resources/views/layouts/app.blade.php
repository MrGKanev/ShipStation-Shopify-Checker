<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-100 text-slate-950 antialiased dark:bg-slate-950 dark:text-slate-100">
        <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
                <div class="flex items-center gap-4">
                    <a class="text-lg font-semibold" href="{{ route('dashboard') }}">{{ config('app.name') }}</a>
                    <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200">
                        Laravel rewrite
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="text-slate-500 dark:text-slate-400">
                        {{ auth()->user()->name }} · {{ auth()->user()->role->value }}
                    </span>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-lg border border-slate-300 px-3 py-2 font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" type="submit">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <div class="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[16rem_minmax(0,1fr)] lg:px-8">
            <aside class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Stores</p>

                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($availableStores as $store)
                        <form method="POST" action="{{ route('stores.active', $store) }}">
                            @csrf
                            <button
                                class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium {{ $store->is($activeStore) ? 'bg-indigo-600 text-white' : 'hover:bg-slate-100 dark:hover:bg-slate-800' }}"
                                type="submit"
                            >
                                {{ $store->label }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </aside>

            <main>
                @yield('content')
            </main>
        </div>
    </body>
</html>
