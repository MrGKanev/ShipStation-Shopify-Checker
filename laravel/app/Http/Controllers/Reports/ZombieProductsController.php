<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RunZombieProductsReport;
use App\Application\Reports\ZombieProductsResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\ZombieProductsRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ZombieProductsController extends Controller
{
    public function create(): View
    {
        return view('reports.zombie-products', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(ZombieProductsRequest $request, RunZombieProductsReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $result = null;
        $reportFailed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';

        if (! $configurationError) {
            try {
                $result = $report->handle($store);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Zombie products report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.zombie-products', ['result' => $result instanceof ZombieProductsResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
