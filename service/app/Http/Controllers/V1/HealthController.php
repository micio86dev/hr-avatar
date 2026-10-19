<?php

namespace App\Http\Controllers\V1;

use App\Http\ErrorCode;
use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController
{
    /**
     * Liveness: the process is up. Touches no dependency.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: PostgreSQL and Redis answer. A failure names the dependency, never its address.
     */
    public function ready(Request $request): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'redis' => $this->check('redis', fn () => Redis::connection()->ping()),
        ];

        if (in_array('failed', $checks, true)) {
            return Problem::make($request, ErrorCode::ServiceUnavailable, extra: ['checks' => $checks]);
        }

        return response()->json(['status' => 'ok', 'checks' => $checks]);
    }

    /**
     * The log names the dependency only: an exception message can carry an address.
     */
    private function check(string $dependency, callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            Log::warning('Readiness check failed.', ['dependency' => $dependency]);

            return 'failed';
        }
    }
}
