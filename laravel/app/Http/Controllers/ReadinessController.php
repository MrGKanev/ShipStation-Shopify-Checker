<?php

namespace App\Http\Controllers;

use App\Application\Health\CheckReadiness;
use Illuminate\Http\JsonResponse;

class ReadinessController extends Controller
{
    public function __invoke(CheckReadiness $readiness): JsonResponse
    {
        $result = $readiness->handle();

        return response()->json(['status' => $result['ready'] ? 'ready' : 'not_ready', 'checks' => $result['checks']], $result['ready'] ? 200 : 503);
    }
}
