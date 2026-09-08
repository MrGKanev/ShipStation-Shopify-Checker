<?php

namespace App\Http\Controllers;

use App\Application\Orders\LookupOrder;
use App\Application\Orders\OrderLookupResult;
use App\Http\Requests\OrderLookupRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class OrderLookupController extends Controller
{
    public function __invoke(OrderLookupRequest $request, LookupOrder $lookupOrder): View
    {
        $orderNumber = $request->validated('order_number');
        $result = null;
        $lookupFailed = false;

        if (is_string($orderNumber) && $orderNumber !== '') {
            /** @var Store $activeStore */
            $activeStore = $request->attributes->get('activeStore');
            $store = $request->user()
                ->stores()
                ->whereKey($activeStore->getKey())
                ->firstOrFail();

            try {
                $result = $lookupOrder->handle($store, $orderNumber);
            } catch (Throwable $exception) {
                $lookupFailed = true;
                Log::warning('Order lookup failed.', [
                    'exception_type' => $exception::class,
                    'status' => $exception instanceof RequestException
                        ? $exception->response->status()
                        : null,
                ]);
            }
        }

        return view('orders.lookup', [
            'orderNumber' => $orderNumber,
            'result' => $result instanceof OrderLookupResult ? $result : null,
            'lookupFailed' => $lookupFailed,
        ]);
    }
}
