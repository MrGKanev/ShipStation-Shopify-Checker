@extends('layouts.guest')

@section('content')
    <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">Shopify Ops</p>
            <h1 class="text-2xl font-bold">Sign in</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Use your operations account to continue.</p>
        </div>

        <form class="mt-8 flex flex-col gap-5" method="POST" action="{{ route('login.store') }}">
            @csrf

            <div class="flex flex-col gap-2">
                <label class="text-sm font-medium" for="email">Email</label>
                <input
                    class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950"
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    required
                    autofocus
                >
                @error('email')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label class="text-sm font-medium" for="password">Password</label>
                <input
                    class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >
                @error('password')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <button class="rounded-lg bg-indigo-600 px-4 py-2.5 font-semibold text-white hover:bg-indigo-500" type="submit">
                Sign in
            </button>
        </form>
    </section>
@endsection
