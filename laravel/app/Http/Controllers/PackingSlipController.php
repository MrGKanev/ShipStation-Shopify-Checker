<?php

namespace App\Http\Controllers;

use App\Application\Orders\LoadPackingSlip;
use App\Http\Requests\PackingSlipRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use LogicException;
use Throwable;

class PackingSlipController extends Controller
{
    public function create(Request $request): View
    {
        return view('orders.packing-slip', ['orderNumber' => is_string($request->query('order')) ? $request->query('order') : '', 'result' => null, 'lookupFailed' => false, 'configurationError' => false]);
    }

    public function store(PackingSlipRequest $request, LoadPackingSlip $loader): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $number = (string) $request->validated('order_number');
        $result = null;
        $lookupFailed = false;
        $configurationError = false;
        try {
            $result = $loader->handle($store, $number);
        } catch (LogicException) {
            $configurationError = true;
        } catch (Throwable $exception) {
            $lookupFailed = true;
            Log::warning('Packing slip lookup failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null]);
        }

        return view('orders.packing-slip', compact('result', 'lookupFailed', 'configurationError') + ['orderNumber' => $number]);
    }
}
