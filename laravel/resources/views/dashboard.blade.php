@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section>
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Active store</p>
            <h1 class="mt-1 text-3xl font-bold">{{ $activeStore->label }}</h1>
            <p class="mt-2 text-slate-500 dark:text-slate-400">{{ $activeStore->shopify_store }}.myshopify.com</p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-sm text-slate-500 dark:text-slate-400">Application</p>
                <p class="mt-2 text-lg font-semibold">Laravel foundation active</p>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-sm text-slate-500 dark:text-slate-400">Shopify connection</p>
                <p class="mt-2 text-lg font-semibold">Integration client active</p>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-sm text-slate-500 dark:text-slate-400">Migrated tools</p>
                <p class="mt-2 text-lg font-semibold">9 · Seven order tools and two audit reports</p>
            </article>
        </section>
    </div>
@endsection
