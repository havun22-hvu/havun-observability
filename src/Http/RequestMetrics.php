<?php

namespace Havun\Observability\Http;

use Closure;
use Havun\Observability\HavunCore;
use Havun\Observability\SafePath;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One request_metrics row per request, written in terminate(): after the response has gone to the
 * visitor, so a slow or absent HavunCore never delays a page. No IP, no path secrets.
 */
class RequestMetrics
{
    private float $start = 0.0;

    public function handle(Request $request, Closure $next): Response
    {
        $this->start = microtime(true);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldRecord($request)) {
            return;
        }

        HavunCore::insert('request_metrics', [[
            'project' => (string) config('observability.project'),
            'method' => $request->method(),
            'path' => SafePath::path($request),
            'route_name' => $request->route()?->getName(),
            'status_code' => $response->getStatusCode(),
            'response_time_ms' => (int) round((microtime(true) - $this->start) * 1000),
            'ip_address' => null,
            'tenant' => null,
            'user_agent' => Str::limit((string) $request->userAgent(), 497),
            'memory_usage_kb' => (int) round(memory_get_peak_usage(true) / 1024),
            'created_at' => now()->toDateTimeString(),
        ]]);
    }

    private function shouldRecord(Request $request): bool
    {
        if (! config('observability.enabled', true) || $this->start === 0.0) {
            return false;
        }

        $rate = (float) config('observability.sampling_rate', 1.0);
        if ($rate < 1.0 && mt_rand(1, 10000) / 10000 > $rate) {
            return false;
        }

        return ! Str::is((array) config('observability.excluded_paths', []), $request->path());
    }
}
