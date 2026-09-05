<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoreRequest;
use App\Http\Requests\StoreUpdateRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $stores = Store::query()
            ->withCount('users')
            ->orderBy('label')
            ->orderBy('id')
            ->paginate(25);

        return view('admin.stores.index', compact('stores'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('admin.stores.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreStoreRequest $request): RedirectResponse
    {
        $store = DB::transaction(function () use ($request): Store {
            $store = Store::create($request->validated());
            $request->user()->stores()->attach($store);

            return $store;
        });

        return redirect()
            ->route('admin.stores.edit', $store)
            ->with('status', 'Store created.');
    }

    /**
     * Display the specified resource.
     */
    public function edit(Store $store): View
    {
        return view('admin.stores.edit', compact('store'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreUpdateRequest $request, Store $store): RedirectResponse
    {
        $attributes = $request->safe()->except([
            'shopify_access_token',
            'shipstation_api_key',
            'shipstation_api_secret',
        ]);

        foreach (['shopify_access_token', 'shipstation_api_key', 'shipstation_api_secret'] as $credential) {
            if ($request->filled($credential)) {
                $attributes[$credential] = $request->validated($credential);
            }
        }

        $store->update($attributes);

        return back()->with('status', 'Store updated.');
    }
}
