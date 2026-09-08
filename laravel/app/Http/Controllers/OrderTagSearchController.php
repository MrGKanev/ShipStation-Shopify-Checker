<?php

namespace App\Http\Controllers;

use App\Application\Orders\OrderTagSearchResult;
use App\Application\Orders\SearchOrdersByTag;
use App\Http\Requests\OrderTagSearchRequest;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class OrderTagSearchController extends Controller
{
    public function create(Request $request): View
    {
        return view('orders.tag-search', [
            'tag' => is_string($request->query('tag')) ? $request->query('tag') : '',
            'startDate' => '',
            'endDate' => '',
            'result' => null,
            'searchFailed' => false,
            'configurationError' => false,
        ]);
    }

    public function store(OrderTagSearchRequest $request, SearchOrdersByTag $search): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();
        $tag = (string) $request->validated('tag');
        $startDate = $request->validated('start_date');
        $endDate = $request->validated('end_date');
        $result = null;
        $searchFailed = false;
        $configurationError = trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '';

        if (! $configurationError) {
            try {
                $result = $search->handle($store, $tag, $startDate, $endDate);
            } catch (Throwable $exception) {
                $searchFailed = true;
                Log::warning('Order tag search failed.', [
                    'exception_type' => $exception::class,
                    'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                    'store_id' => $store->getKey(),
                ]);
            }
        }

        return view('orders.tag-search', [
            'tag' => $tag,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'result' => $result instanceof OrderTagSearchResult ? $result : null,
            'searchFailed' => $searchFailed,
            'configurationError' => $configurationError,
        ]);
    }
}
