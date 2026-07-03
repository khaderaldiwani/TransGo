<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiPerformanceLogger
{
    private const SLOW_REQUEST_THRESHOLD_MS = 3000;

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($durationMs > self::SLOW_REQUEST_THRESHOLD_MS) {
            Log::warning('Slow API request detected.', [
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->user_id,
                'duration_ms' => $durationMs,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return $response;
    }
}
