<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class ActionLogController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): View
    {
        $activities = Activity::query()->inLog('administration')->with(['causer', 'subject'])->latest('id')->paginate(50);

        return view('admin.action-log', compact('activities'));
    }
}
