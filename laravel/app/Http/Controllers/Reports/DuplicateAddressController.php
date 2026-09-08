<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\DuplicateAddressResult;
use App\Application\Reports\RunDuplicateAddressReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DuplicateAddressRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class DuplicateAddressController extends Controller
{
    public function create(): View
    {
        return view('reports.duplicate-addresses', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DuplicateAddressRequest $request, RunDuplicateAddressReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Duplicate address report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.duplicate-addresses', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof DuplicateAddressResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
