<?php

namespace App\Http\Controllers\V1;

use App\Http\ErrorCode;
use App\Http\Problem;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController
{
    /**
     * Liveness: the process is up
     *
     * Always answers `{"status":"ok"}` while the process runs. It touches neither the database nor
     * Redis, so an orchestrator can use it to decide whether to restart the process.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: the dependencies answer
     *
     * Checks PostgreSQL and Redis. A failure answers 503 and names each dependency `ok` or `failed`,
     * never its address, version or connection string.
     */
    #[Response(503, description: 'A dependency is unreachable; `checks` names each one `ok` or `failed`.', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
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
