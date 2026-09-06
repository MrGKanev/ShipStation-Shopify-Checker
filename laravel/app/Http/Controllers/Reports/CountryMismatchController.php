<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\CountryMismatchResult;
use App\Application\Reports\RunCountryMismatchReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\CountryMismatchRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class CountryMismatchController extends Controller
{
    public function create(): View
    {
        return view('reports.country-mismatch', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false]);
    }

    public function store(CountryMismatchRequest $request, RunCountryMismatchReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $result = null;
        $reportFailed = false;
        try {
            $result = $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $reportFailed = true;
            Log::warning('Country mismatch report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
        }

        return view('reports.country-mismatch', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof CountryMismatchResult ? $result : null, 'reportFailed' => $reportFailed]);
    }
}
