<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RunTagPolicyReport;
use App\Application\Reports\TagPolicyResult;
use App\Domain\Reports\TagPolicyAnalyzer;
use App\Http\Controllers\Controller;
use App\Http\Requests\TagPolicyRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class TagPolicyController extends Controller
{
    public function create(TagPolicyAnalyzer $analyzer): View
    {
        $config = $this->config();

        return view('reports.tag-policy', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'configured' => $analyzer->hasRules($config), 'result' => null, 'reportFailed' => false, 'configurationError' => false]);
    }

    public function store(TagPolicyRequest $request, RunTagPolicyReport $report, TagPolicyAnalyzer $analyzer): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $config = $this->config();
        $configured = $analyzer->hasRules($config);
        $configurationError = $configured && (trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '');
        $result = null;
        $reportFailed = false;
        if ($configured && ! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate, $config);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Tag policy report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.tag-policy', ['startDate' => $startDate, 'endDate' => $endDate, 'configured' => $configured, 'result' => $result instanceof TagPolicyResult ? $result : null, 'reportFailed' => $reportFailed, 'configurationError' => $configurationError]);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = config('tag-policy', []);

        return is_array($config) ? $config : [];
    }
}
