<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RunSkuDuplicatesReport;
use App\Application\Reports\SkuDuplicatesResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\SkuDuplicatesRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class SkuDuplicatesController extends Controller
{
    public function create(): View
    {
        return view('reports.sku-duplicates', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(SkuDuplicatesRequest $request, RunSkuDuplicatesReport $report): View
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
                Log::warning('SKU duplicates report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.sku-duplicates', ['result' => $result instanceof SkuDuplicatesResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
