<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\InventoryOversellResult;
use App\Application\Reports\RunInventoryOversellReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryOversellRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class InventoryOversellController extends Controller
{
    public function create(): View
    {
        return view('reports.inventory-oversell', ['result' => null, 'reportFailed' => false, 'shopifyConfigurationError' => false, 'shipStationConfigurationError' => false]);
    }

    public function store(InventoryOversellRequest $request, RunInventoryOversellReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $result = null;
        $reportFailed = false;
        $shopifyConfigurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $shipStationConfigurationError = trim((string) $store->shipstation_api_key) === '' || trim((string) $store->shipstation_api_secret) === '';

        if (! $shopifyConfigurationError && ! $shipStationConfigurationError) {
            try {
                $result = $report->handle($store);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Inventory oversell report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.inventory-oversell', [
            'result' => $result instanceof InventoryOversellResult ? $result : null,
            'reportFailed' => $reportFailed,
            'shopifyConfigurationError' => $shopifyConfigurationError,
            'shipStationConfigurationError' => $shipStationConfigurationError,
        ]);
    }
}
