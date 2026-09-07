<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RunTaxAuditReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaxAuditRequest;
use App\Models\Store;
use Illuminate\View\View;
use Throwable;

class TaxAuditController extends Controller
{
    public function create(): View
    {
        return view('reports.tax-audit', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'minimum' => 5, 'result' => null, 'reportFailed' => false]);
    }

    public function store(TaxAuditRequest $request, RunTaxAuditReport $report): View
    {
        /** @var Store $active */ $active = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($active->getKey())->firstOrFail();
        $data = $request->validated();
        $result = null;
        $failed = false;
        try {
            $result = $report->handle($store, (string) $data['start_date'], (string) $data['end_date'], (float) $data['minimum']);
        } catch (Throwable) {
            $failed = true;
        }

        return view('reports.tax-audit', ['startDate' => $data['start_date'], 'endDate' => $data['end_date'], 'minimum' => $data['minimum'], 'result' => $result, 'reportFailed' => $failed]);
    }
}
