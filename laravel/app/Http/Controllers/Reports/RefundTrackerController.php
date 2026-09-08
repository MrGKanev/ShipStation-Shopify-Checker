<?php

namespace App\Http\Controllers\Reports;

use App\Application\Reports\RefundTrackerResult;
use App\Application\Reports\RunRefundTrackerReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\RefundTrackerRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class RefundTrackerController extends Controller
{
    public function create(): View
    {
        return view('reports.refund-tracker', $this->viewData());
    }

    public function store(RefundTrackerRequest $request, RunRefundTrackerReport $report): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $startDate = (string) $request->validated('start_date');
        $endDate = (string) $request->validated('end_date');
        $shopifyConfigurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $shipStationConfigurationWarning = (trim((string) $store->shipstation_api_key) === '') !== (trim((string) $store->shipstation_api_secret) === '');
        $result = null;
        $reportFailed = false;

        if (! $shopifyConfigurationError && ! $shipStationConfigurationWarning) {
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Refund tracker report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.refund-tracker', $this->viewData($startDate, $endDate, $result, $reportFailed, $shopifyConfigurationError, $shipStationConfigurationWarning));
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?RefundTrackerResult $result = null, bool $reportFailed = false, bool $shopifyConfigurationError = false, bool $shipStationConfigurationWarning = false): array
    {
        return compact('result', 'reportFailed', 'shopifyConfigurationError', 'shipStationConfigurationWarning') + [
            'startDate' => $startDate ?? now()->subDays(30)->toDateString(),
            'endDate' => $endDate ?? now()->toDateString(),
        ];
    }
}
