@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Administration</p>
                <h1 class="text-3xl font-bold">Users</h1>
            </div>

            <a class="rounded-lg bg-indigo-600 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-indigo-500" href="{{ route('admin.users.create') }}">
                Add user
            </a>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">User</th>
                            <th class="px-5 py-3 font-semibold">Role</th>
                            <th class="px-5 py-3 font-semibold">Stores</th>
                            <th class="px-5 py-3 text-right font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse ($users as $user)
                            <tr>
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ $user->name }}</p>
                                    <p class="text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                                </td>
                                <td class="px-5 py-4 capitalize">{{ $user->role->value }}</td>
                                <td class="px-5 py-4">{{ $user->stores_count }}</td>
                                <td class="px-5 py-4 text-right">
                                    <a class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400" href="{{ route('admin.users.edit', $user) }}">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-5 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">No users configured.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="border-t border-slate-200 px-5 py-4 dark:border-slate-800">{{ $users->links() }}</div>
            @endif
        </div>
    </div>
@endsection
