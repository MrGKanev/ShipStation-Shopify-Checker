<?php

namespace App\Http\Controllers\Admin;

use App\Application\Health\CheckApiHealth;
use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiHealthController extends Controller
{
    public function show(): View
    {
        return view('admin.api-health', ['health' => null]);
    }

    public function check(Request $request, CheckApiHealth $checkApiHealth): View
    {
        /** @var Store $activeStore */
        $activeStore = $request->attributes->get('activeStore');
        $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();

        return view('admin.api-health', [
            'health' => $checkApiHealth->handle($store),
        ]);
    }
}
