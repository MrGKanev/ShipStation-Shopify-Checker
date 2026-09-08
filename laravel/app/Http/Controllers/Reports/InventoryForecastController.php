<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\InventoryForecastResult;
use App\Application\Reports\RunInventoryForecastReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryForecastRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class InventoryForecastController extends Controller
{
    public function create(): View
    {
        return view('reports.inventory-forecast', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(InventoryForecastRequest $request, RunInventoryForecastReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $endDate = now()->toDateString();
        $startDate = now()->subDays(30)->toDateString();
        $result = null;
        $reportFailed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';

        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Inventory forecast report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.inventory-forecast', ['result' => $result instanceof InventoryForecastResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
