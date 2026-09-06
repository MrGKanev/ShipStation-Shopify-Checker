<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\HighValueNoPhoneResult;
use App\Application\Reports\RunHighValueNoPhoneReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\HighValueNoPhoneRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class HighValueNoPhoneController extends Controller
{
    public function create(): View
    {
        return view('reports.high-value-no-phone', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 200, 'currency' => 'USD', 'result' => null, 'reportFailed' => false]);
    }

    public function store(HighValueNoPhoneRequest $request, RunHighValueNoPhoneReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $minimum = (float) $request->validated('minimum');
        $currency = (string) $request->validated('currency');
        $result = null;
        $reportFailed = false;

        try {
            $result = $report->handle($store, $startDate, $endDate, $minimum, $currency);
        } catch (Throwable $exception) {
            $reportFailed = true;
            Log::warning('High-value no-phone report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
        }

        return view('reports.high-value-no-phone', ['startDate' => $startDate, 'endDate' => $endDate, 'minimum' => $minimum, 'currency' => $currency, 'result' => $result instanceof HighValueNoPhoneResult ? $result : null, 'reportFailed' => $reportFailed]);
    }
}
