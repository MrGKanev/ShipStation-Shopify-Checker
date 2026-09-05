<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveStore
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $availableStores = $request->user()
            ->stores()
            ->orderBy('label')
            ->orderBy('stores.id')
            ->get();

        /** @var Store|null $activeStore */
        $activeStore = $availableStores->firstWhere(
            'id',
            $request->session()->get('active_store_id'),
        ) ?? $availableStores->first();

        abort_if($activeStore === null, 403, 'No store access has been configured for this account.');

        $request->session()->put('active_store_id', $activeStore->getKey());
        $request->attributes->set('activeStore', $activeStore);
        Context::add('store_id', $activeStore->getKey());

        View::share([
            'activeStore' => $activeStore,
            'availableStores' => $availableStores,
        ]);

        return $next($request);
    }
}
