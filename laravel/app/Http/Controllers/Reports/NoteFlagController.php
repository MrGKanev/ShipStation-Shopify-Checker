<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\NoteFlagResult;
use App\Application\Reports\RunNoteFlagReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\NoteFlagRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class NoteFlagController extends Controller
{
    private const DEFAULT_KEYWORDS = 'urgent, hold, cancel, wrong, error, stop, do not ship, dont ship, wait, attention';

    public function create(): View
    {
        return view('reports.note-flags', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'keywords' => self::DEFAULT_KEYWORDS, 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(NoteFlagRequest $request, RunNoteFlagReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $keywordsRaw = (string) $request->validated('keywords');
        $keywords = array_values(array_filter(array_map(fn (string $keyword): string => mb_strtolower(trim($keyword)), explode(',', $keywordsRaw))));
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate, $keywords);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Note flags report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.note-flags', ['startDate' => $startDate, 'endDate' => $endDate, 'keywords' => $keywordsRaw, 'result' => $result instanceof NoteFlagResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }
}
