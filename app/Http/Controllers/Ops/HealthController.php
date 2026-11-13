<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\WorkerNode;
use App\Services\Reports\QueueMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * §6.9 F-6.9-007 — health-check endpoint for load balancers (WP-24).
 *
 * `/health` returns JSON with one entry per dependency and an HTTP status a
 * load balancer can act on: 200 when every REQUIRED check passes, 503 when
 * any fails. Laravel's stock `/up` stays as the cheap liveness probe; this is
 * the readiness probe.
 *
 * Design notes:
 *  - the queue check is informational (`required: false`): a backed-up queue
 *    is not a reason to pull a web node out of rotation;
 *  - the worker check is informational too and reports 0 honestly on a node
 *    with no registry rows rather than failing;
 *  - `?detailed=1` adds counts. Nothing secret (no credentials, no paths) is
 *    ever emitted — the endpoint is unauthenticated by design.
 *  - Site-offline mode (§3.25.1) intentionally makes this endpoint 503 too,
 *    so a maintenance switch drains the load balancer. See docs/install-vps.md.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, QueueMonitorService $queues): JsonResponse
    {
        $detailed = $request->boolean('detailed');

        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'queue' => $this->queue($queues, $detailed),
            'workers' => $this->workers(),
        ];

        $healthy = ! in_array(
            false,
            array_map(
                static fn (array $check): bool => $check['required'] === false || $check['ok'],
                $checks,
            ),
            true,
        );

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool, required: bool, message: ?string} */
    private function database(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true, 'required' => true, 'message' => null];
        } catch (Throwable $exception) {
            return ['ok' => false, 'required' => true, 'message' => class_basename($exception)];
        }
    }

    /** @return array{ok: bool, required: bool, message: ?string} */
    private function cache(): array
    {
        $key = 'health:'.Str::random(8);

        try {
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return ['ok' => $ok, 'required' => true, 'message' => $ok ? null : 'read-back failed'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'required' => true, 'message' => class_basename($exception)];
        }
    }

    /** @return array{ok: bool, required: bool, message: ?string, pending?: int} */
    private function queue(QueueMonitorService $queues, bool $detailed): array
    {
        try {
            if (! $queues->supported()) {
                return [
                    'ok' => true,
                    'required' => false,
                    'message' => 'driver '.(string) config('queue.default').' is not inspectable',
                ];
            }

            $check = ['ok' => true, 'required' => false, 'message' => null];

            if ($detailed) {
                $check['pending'] = $queues->totalPending();
            }

            return $check;
        } catch (Throwable $exception) {
            return ['ok' => false, 'required' => false, 'message' => class_basename($exception)];
        }
    }

    /** @return array{ok: bool, required: bool, message: ?string, alive: int} */
    private function workers(): array
    {
        try {
            $alive = (int) WorkerNode::query()->alive()->count();

            return ['ok' => true, 'required' => false, 'message' => null, 'alive' => $alive];
        } catch (Throwable $exception) {
            return ['ok' => false, 'required' => false, 'message' => class_basename($exception), 'alive' => 0];
        }
    }
}
