<?php

namespace App\Http\Controllers;

use App\Application\Orders\LoadTrackingFeed;
use App\Http\Requests\OrderTrackingRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use LogicException;
use Throwable;

class OrderTrackingController extends Controller
{
    public function create(Request $request): View
    {
        return view('orders.tracking', ['ordersInput' => is_string($request->query('prefill')) ? $request->query('prefill') : '', 'results' => null, 'lookupFailed' => false, 'configurationError' => false]);
    }

    public function store(OrderTrackingRequest $request, LoadTrackingFeed $loader): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $results = null;
        $lookupFailed = false;
        $configurationError = false;

        try {
            $results = $loader->handle($store, $request->orderNumbers());
        } catch (LogicException) {
            $configurationError = true;
        } catch (Throwable $exception) {
            $lookupFailed = true;
            Log::warning('Tracking lookup failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null]);
        }

        return view('orders.tracking', ['ordersInput' => (string) $request->validated('orders'), 'results' => $results, 'lookupFailed' => $lookupFailed, 'configurationError' => $configurationError]);
    }
}
