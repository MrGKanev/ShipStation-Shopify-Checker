<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RunTaxAuditReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaxAuditRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class TaxAuditController extends Controller
{
    public function create(): View
    {
        return view('reports.tax-audit', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 5, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(TaxAuditRequest $request, RunTaxAuditReport $report): View
    {
        /** @var Store $active */ $active = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($active->getKey())->firstOrFail();
        $data = $request->validated();
        $result = null;
        $failed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        if (! $configurationError) {
            try {
                $result = $report->handle($store, (string) $data['start_date'], (string) $data['end_date'], (float) $data['minimum']);
            } catch (Throwable $exception) {
                $failed = true;
                Log::warning('Tax audit report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.tax-audit', ['startDate' => $data['start_date'], 'endDate' => $data['end_date'], 'minimum' => $data['minimum'], 'result' => $result, 'reportFailed' => $failed, 'configurationError' => $configurationError]);
    }
}
