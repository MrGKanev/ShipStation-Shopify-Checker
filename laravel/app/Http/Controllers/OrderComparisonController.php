<?php

namespace App\Http\Controllers;

use App\Application\Orders\CompareOrders;
use App\Application\Orders\OrderComparisonResult;
use App\Http\Requests\OrderComparisonRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class OrderComparisonController extends Controller
{
    public function __invoke(OrderComparisonRequest $request, CompareOrders $compareOrders): View
    {
        $numberA = $request->validated('order_a');
        $numberB = $request->validated('order_b');
        $result = null;
        $comparisonFailed = false;

        if (is_string($numberA) && is_string($numberB)) {
            /** @var Store $activeStore */
            $activeStore = $request->attributes->get('activeStore');
            $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

            try {
                $result = $compareOrders->handle($store, $numberA, $numberB);
            } catch (Throwable $exception) {
                $comparisonFailed = true;
                Log::warning('Order comparison failed.', [
                    'exception_type' => $exception::class,
                    'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                ]);
            }
        }

        return view('orders.compare', [
            'numberA' => $numberA,
            'numberB' => $numberB,
            'result' => $result instanceof OrderComparisonResult ? $result : null,
            'comparisonFailed' => $comparisonFailed,
        ]);
    }
}
