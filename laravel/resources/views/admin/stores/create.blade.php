@extends('layouts.app')

@section('content')
    <div class="flex max-w-3xl flex-col gap-6">
        <div>
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Administration · Stores</p>
            <h1 class="text-3xl font-bold">Add store</h1>
        </div>

        <form class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('admin.stores.store') }}">
            @csrf
            @include('admin.stores.partials.form', ['store' => null])
        </form>
    </div>
@endsection
