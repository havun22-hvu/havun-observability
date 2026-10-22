<?php

namespace Havun\Observability;

use Illuminate\Http\Request;

/**
 * The request path without secrets: query string dropped, the value of a secret route
 * parameter (config `secret_route_parameters`) replaced by `{name}`. Slugs stay.
 */
final class SafePath
{
    /** Path without leading slash, e.g. "inloglink/{token}". */
    public static function path(?Request $request): string
    {
        if (! $request) {
            return '';
        }

        $secrets = self::secretValues($request);
        $segments = explode('/', $request->path());

        foreach ($segments as $i => $segment) {
            $value = rawurldecode($segment);
            if (isset($secrets[$value])) {
                $segments[$i] = '{'.$secrets[$value].'}';
            }
        }

        return implode('/', $segments);
    }

    /** Full URL without query string and fragment, secrets replaced. */
    public static function url(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        $path = self::path($request);

        return rtrim($request->root(), '/').($path === '/' || $path === '' ? '' : '/'.$path);
    }

    /** @return array<string, string> raw value => parameter name */
    private static function secretValues(Request $request): array
    {
        $route = $request->route();
        if (! is_object($route)) {
            return [];
        }

        $names = (array) config('observability.secret_route_parameters', []);
        $secrets = [];
        foreach ($route->originalParameters() as $name => $value) {
            if (in_array($name, $names, true) && is_string($value) && $value !== '') {
                $secrets[$value] = $name;
            }
        }

        return $secrets;
    }
}
