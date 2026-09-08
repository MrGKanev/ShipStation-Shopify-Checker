@extends('layouts.app')
@section('content')
<div class="flex flex-col gap-6"><section><h1 class="text-3xl font-bold">Action Log</h1><p class="text-slate-500">Administrative changes, newest first.</p></section><div class="overflow-x-auto rounded-xl border"><table class="min-w-full text-left"><thead><tr><th class="p-3">When</th><th>Actor</th><th>Action</th><th>Target</th><th>Changes</th></tr></thead><tbody>
@forelse($activities as $activity)<tr><td class="p-3">{{ $activity->created_at?->toDateTimeString() }}</td><td>{{ $activity->causer?->email ?? 'System' }}</td><td>{{ $activity->description }}</td><td>{{ class_basename((string) $activity->subject_type) }} #{{ $activity->subject_id }}</td><td><pre class="max-w-xl whitespace-pre-wrap text-xs">{{ json_encode(['changes' => $activity->attribute_changes, 'context' => $activity->properties], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></td></tr>@empty<tr><td class="p-6 text-center" colspan="5">No administrative actions recorded.</td></tr>@endforelse
</tbody></table></div>{{ $activities->links() }}</div>
@endsection
