<?php

namespace App\Http\Middleware;

use App\Services\Local\LocalModeGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * L0. The one door every local-control route goes through.
 *
 * A failure is a 403 with the failed checks named, and a warning in the log. It
 * is deliberately not a silent no-op: an endpoint that quietly does nothing on
 * production is indistinguishable from one that is broken, and the next person
 * to debug it will "fix" it by removing the gate.
 */
class EnsureLocalMode
{
    public const ALIAS = 'jetgrid.local';

    public function __construct(private readonly LocalModeGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $failed = $this->gate->failures();

        if ($failed === []) {
            return $next($request);
        }

        Log::warning('JetGrid refused a local-control request.', [
            'route' => $request->route()?->getName() ?? $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'failed_checks' => $failed,
        ]);

        return response()->json([
            'refused' => true,
            'reason' => 'Local mode is off. This endpoint controls processes on the machine JetGrid is running on, so it is refused unless all four local-mode checks pass.',
            'failed_checks' => $failed,
        ], 403);
    }
}
