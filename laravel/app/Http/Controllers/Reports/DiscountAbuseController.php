<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\DiscountAbuseResult;
use App\Application\Reports\RunDiscountAbuseReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscountAbuseRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class DiscountAbuseController extends Controller
{
    public function create(): View
    {
        return view('reports.discount-abuse', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimumEmails' => 3, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(DiscountAbuseRequest $request, RunDiscountAbuseReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $minimumEmails = (int) $request->validated('minimum_emails');
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate, $minimumEmails);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Discount abuse report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.discount-abuse', ['startDate' => $startDate, 'endDate' => $endDate, 'minimumEmails' => $minimumEmails, 'result' => $result instanceof DiscountAbuseResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
