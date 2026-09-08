<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\AddressChangeResult;
use App\Application\Reports\RunAddressChangeReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressChangeRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class AddressChangeController extends Controller
{
    public function create(): View
    {
        return view('reports.address-changes', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'configurationError' => false, 'reportFailed' => false]);
    }

    public function store(AddressChangeRequest $request, RunAddressChangeReport $report): View
    {
        /** @var Store $activeStore */ $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $start, $end);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Address change report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.address-changes', ['startDate' => $start, 'endDate' => $end, 'result' => $result instanceof AddressChangeResult ? $result : null, 'configurationError' => $configurationError, 'reportFailed' => $reportFailed]);
    }
}
