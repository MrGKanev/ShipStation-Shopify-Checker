<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\ReturnedItemsResult;
use App\Application\Reports\RunReturnedItemsReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReturnedItemsRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReturnedItemsController extends Controller
{
    public function create(): View
    {
        return view('reports.returned-items', $this->viewData());
    }

    public function store(ReturnedItemsRequest $request, RunReturnedItemsReport $report): View
    {
        [$store, $startDate, $endDate] = $this->context($request);
        $configurationError = $this->configurationError($store);
        $result = null;
        $reportFailed = false;

        if (! $configurationError) {
            try {
                $result = $report->handle($store, $startDate, $endDate);
            } catch (Throwable $exception) {
                $reportFailed = true;
                $this->logFailure('Returned items report failed.', $exception, $store);
            }
        }

        return view('reports.returned-items', $this->viewData($startDate, $endDate, $result, $reportFailed, $configurationError));
    }

    public function export(ReturnedItemsRequest $request, RunReturnedItemsReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        [$store, $startDate, $endDate] = $this->context($request);
        if ($this->configurationError($store)) {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }

        try {
            $result = $report->handle($store, $startDate, $endDate);
        } catch (Throwable $exception) {
            $this->logFailure('Returned items CSV export failed.', $exception, $store);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }

        $rows = array_map(fn (array $row): array => [$row['product'], $row['quantity']], $result->rows);

        return $csv->download("returned-items-{$startDate}-to-{$endDate}.csv", ['Product', 'Quantity'], $rows);
    }

    /** @return array{Store, string, string} */
    private function context(ReturnedItemsRequest $request): array
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

        return [$store, (string) $request->validated('start_date'), (string) $request->validated('end_date')];
    }

    private function configurationError(Store $store): bool
    {
        return trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
    }

    private function logFailure(string $message, Throwable $exception, Store $store): void
    {
        Log::warning($message, ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
    }

    /** @return array<string, mixed> */
    private function viewData(?string $startDate = null, ?string $endDate = null, ?ReturnedItemsResult $result = null, bool $reportFailed = false, bool $configurationError = false): array
    {
        return compact('result', 'reportFailed', 'configurationError') + ['startDate' => $startDate ?? now()->subDays(30)->toDateString(), 'endDate' => $endDate ?? now()->toDateString()];
    }
}
