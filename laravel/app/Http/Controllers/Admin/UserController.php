<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $users = User::query()
            ->withCount('stores')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('admin.users.create', [
            'stores' => $this->stores(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(UserStoreRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create($request->safe()->except('store_ids'));
            $user->stores()->attach($request->validated('store_ids'));

            return $user;
        });

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', 'User created.');
    }

    /**
     * Display the specified resource.
     */
    public function edit(User $user): View
    {
        $user->load('stores:id');

        return view('admin.users.edit', [
            'user' => $user,
            'stores' => $this->stores(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        $previousStoreIds = $user->stores()->orderBy('stores.id')->pluck('stores.id')->all();
        DB::transaction(function () use ($request, $user): void {
            $attributes = $request->safe()->except(['password', 'password_confirmation', 'store_ids']);

            if ($request->filled('password')) {
                $attributes['password'] = $request->validated('password');
            }

            $user->update($attributes);
            $user->stores()->sync($request->validated('store_ids'));
        });
        $currentStoreIds = $user->stores()->orderBy('stores.id')->pluck('stores.id')->all();
        if ($previousStoreIds !== $currentStoreIds) {
            activity('administration')->causedBy($request->user())->performedOn($user)->event('store_access_updated')->withProperties(['old_store_ids' => $previousStoreIds, 'store_ids' => $currentStoreIds])->log('User store access updated');
        }

        return back()->with('status', 'User updated.');
    }

    /**
     * @return Collection<int, Store>
     */
    private function stores(): Collection
    {
        return Store::query()
            ->orderBy('label')
            ->orderBy('id')
            ->get(['id', 'label']);
    }
}
