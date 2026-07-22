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
            $context = [
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->user_id,
                'ip' => $request->ip(),
                'duration_ms' => $durationMs,
                'status_code' => $response->getStatusCode(),
                'timestamp' => now()->toIso8601String(),
            ];

            if ($request->is('api/v1/auth/*') || $request->is('api/v1/*/login') || $request->is('api/v1/*/register')) {
                $context['auth_identifier_masked'] = $this->maskedIdentifier(
                    (string) ($request->input('phone') ?: $request->input('email') ?: '')
                );
            }

            Log::warning('Slow API request detected.', $context);
        }

        return $response;
    }

    private function maskedIdentifier(string $identifier): ?string
    {
        if ($identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            [$name, $domain] = array_pad(explode('@', $identifier, 2), 2, '');

            return substr($name, 0, 2).'****@'.$domain;
        }

        if (strlen($identifier) <= 6) {
            return substr($identifier, 0, 1).'****';
        }

        return substr($identifier, 0, 3).'****'.substr($identifier, -3);
    }
}
