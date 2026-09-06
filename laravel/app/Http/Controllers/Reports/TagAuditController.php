<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RunTagAuditReport;
use App\Application\Reports\TagAuditResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\TagAuditRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class TagAuditController extends Controller
{
    public function create(): View
    {
        return view('reports.tag-audit', ['startDate' => now()->subDays(90)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(TagAuditRequest $request, RunTagAuditReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $result = null;
        $reportFailed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';

        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate, now()->subDays(90)->toDateString());
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Tag audit report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.tag-audit', ['startDate' => $startDate, 'endDate' => $endDate, 'result' => $result instanceof TagAuditResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
