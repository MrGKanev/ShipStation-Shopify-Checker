<?php

namespace App\Http\Controllers\Reports;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\AddressChangeResult;
use App\Application\Reports\RunAddressChangeReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressChangeRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AddressChangeController extends Controller
{
    public function create(): View
    {
        return view('reports.address-changes', ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->toDateString(), 'result' => null, 'configurationError' => false, 'reportFailed' => false]);
    }

    public function store(AddressChangeRequest $request, RunAddressChangeReport $report): View
    {
        /** @var Store $activeStore */ $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';
        $result = null;
        $reportFailed = false;
        if (! $configurationError) {
            try {
                $result = $report->handle($store, $start, $end);
            } catch (Throwable $exception) {
                $reportFailed = true;
                Log::warning('Address change report failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);
            }
        }

        return view('reports.address-changes', ['startDate' => $start, 'endDate' => $end, 'result' => $result instanceof AddressChangeResult ? $result : null, 'configurationError' => $configurationError, 'reportFailed' => $reportFailed]);
    }

    public function export(AddressChangeRequest $request, RunAddressChangeReport $report, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        /** @var Store $activeStore */ $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        if (trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '') {
            return back()->withErrors(['export' => 'Shopify credentials are incomplete for the active store.']);
        }
        $start = (string) $request->validated('start_date');
        $end = (string) $request->validated('end_date');
        try {
            $result = $report->handle($store, $start, $end);
        } catch (Throwable $exception) {
            Log::warning('Address change CSV export failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof RequestException ? $exception->response->status() : null, 'store_id' => $store->getKey()]);

            return back()->withErrors(['export' => 'The CSV export could not be completed.']);
        }
        $rows = array_map(fn (array $row): array => [$row['order_number'], $row['created_at'], $row['changed_at'], $row['gap_mins'], $row['email'], $row['addr_name'], $row['addr_line'], number_format((float) $row['total'], 2, '.', ''), $row['financial'], $row['fulfillment']], $result->rows);

        return $csv->download("address-changes-{$start}-to-{$end}.csv", ['Order', 'Placed', 'Changed', 'Time gap (minutes)', 'Email', 'Address name', 'Current shipping address', 'Total', 'Financial status', 'Fulfillment status'], $rows);
    }
}
