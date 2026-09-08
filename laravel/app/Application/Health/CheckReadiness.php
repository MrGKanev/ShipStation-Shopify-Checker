<?php

namespace App\Application\Health;

use Illuminate\Support\Facades\DB;
use Throwable;

class CheckReadiness
{
    /** @return array{ready: bool, checks: array{database: bool, queue: bool}} */
    public function handle(): array
    {
        try {
            DB::select('select 1');
            $database = true;
        } catch (Throwable) {
            $database = false;
        }
        $checks = ['database' => $database, 'queue' => is_string(config('queue.default')) && trim((string) config('queue.default')) !== ''];

        return ['ready' => ! in_array(false, $checks, true), 'checks' => $checks];
    }
}
