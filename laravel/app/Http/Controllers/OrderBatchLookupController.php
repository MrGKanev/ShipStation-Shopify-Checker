<?php

namespace App\Http\Controllers;

use App\Application\Orders\BatchLookupOrders;
use App\Application\Orders\BatchLookupResult;
use App\Http\Requests\OrderBatchLookupRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use LogicException;
use Throwable;

class OrderBatchLookupController extends Controller
{
    public function create(Request $request): View
    {
        return view('orders.spot-check', [
            'ordersInput' => is_string($request->query('prefill')) ? $request->query('prefill') : '',
            'mode' => 'both',
            'result' => null,
            'lookupFailed' => false,
            'configurationError' => false,
        ]);
    }

    public function store(OrderBatchLookupRequest $request, BatchLookupOrders $batchLookupOrders): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $ordersInput = (string) $request->validated('orders');
        $mode = (string) $request->validated('mode');
        $result = null;
        $lookupFailed = false;
        $configurationError = false;

        try {
            $result = $batchLookupOrders->handle($store, $request->orderNumbers(), $mode);
        } catch (LogicException) {
            $configurationError = true;
        } catch (Throwable $exception) {
            $lookupFailed = true;
            Log::warning('Batch order lookup failed.', [
                'exception_type' => $exception::class,
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
            ]);
        }

        return view('orders.spot-check', [
            'ordersInput' => $ordersInput,
            'mode' => $mode,
            'result' => $result instanceof BatchLookupResult ? $result : null,
            'lookupFailed' => $lookupFailed,
            'configurationError' => $configurationError,
        ]);
    }
}
