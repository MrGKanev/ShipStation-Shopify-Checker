<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\DisputeResult;
use App\Application\Reports\RunDisputeReport;
use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class DisputeController extends Controller
{
    public function create(): View
    {
        return view('reports.disputes', ['result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(Request $request, RunDisputeReport $report): View
    {
        abort_unless($request->user()?->can('run-audits'), 403);
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, now()->getTimestamp());
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Dispute report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.disputes', ['result' => $result instanceof DisputeResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
