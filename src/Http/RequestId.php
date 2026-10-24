<?php

namespace Havun\Observability\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * One ID per request, so an error in HavunCore links to what the customer did in that request.
 * nginx sets it ($request_id, overwriting whatever the client sent); without nginx (local, tests)
 * the package generates one. Laravel Context carries it into every log line and queued job.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('observability.request_id', true)) {
            return $next($request);
        }

        $id = self::valid($request->headers->get(self::HEADER)) ?? bin2hex(random_bytes(16));
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** nginx $request_id format: 32 lowercase hex. Anything else is not ours. */
    public static function valid(?string $id): ?string
    {
        return $id !== null && preg_match('/\A[0-9a-f]{32}\z/', $id) === 1 ? $id : null;
    }
}
