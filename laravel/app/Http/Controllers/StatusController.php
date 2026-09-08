<?php

namespace App\Http\Controllers;

use App\Application\Health\CheckReadiness;
use Illuminate\View\View;

class StatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CheckReadiness $readiness): View
    {
        $result = $readiness->handle();

        return view('status', ['ready' => $result['ready'], 'checks' => $result['checks'], 'checkedAt' => now()]);
    }
}
