<?php

namespace App\Http\Controllers;

use App\Application\Orders\LoadOrderTimeline;
use App\Application\Orders\OrderTimelineResult;
use App\Http\Requests\OrderTimelineRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class OrderTimelineController extends Controller
{
    public function __invoke(OrderTimelineRequest $request, LoadOrderTimeline $loadOrderTimeline): View
    {
        $orderNumber = $request->validated('order_number');
        $result = null;
        $timelineFailed = false;

        if (is_string($orderNumber)) {
            /** @var Store $activeStore */
            $activeStore = $request->attributes->get('activeStore');
            $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

            try {
                $result = $loadOrderTimeline->handle($store, $orderNumber);
            } catch (Throwable $exception) {
                $timelineFailed = true;
                Log::warning('Order timeline failed.', [
                    'exception_type' => $exception::class,
                    'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                ]);
            }
        }

        return view('orders.timeline', [
            'orderNumber' => $orderNumber,
            'result' => $result instanceof OrderTimelineResult ? $result : null,
            'timelineFailed' => $timelineFailed,
        ]);
    }
}
