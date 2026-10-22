<?php

namespace Havun\Observability;

use Illuminate\Http\Request;
use Throwable;

/**
 * The request path without secrets: query string dropped, the value of a secret route
 * parameter (config `secret_route_parameters`) replaced by `{name}`. A request no route matched
 * has no parameters to go by: there every token-like segment becomes `{token}`. Slugs stay.
 */
final class SafePath
{
    /** A segment of 16+ URL-safe characters with a digit: a token, not a slug. */
    private const TOKEN_LIKE = '/^(?=.*\d)[A-Za-z0-9_\-.~%]{16,}$/';

    /** Path without leading slash, e.g. "inloglink/{token}". */
    public static function path(?Request $request): string
    {
        if (! $request) {
            return '';
        }

        try {
            $path = rawurldecode($request->path());
            $secrets = self::secretValues($request);

            if ($secrets === null) {
                return implode('/', array_map(
                    fn (string $s): string => preg_match(self::TOKEN_LIKE, $s) ? '{token}' : $s,
                    explode('/', $path)
                ));
            }

            // Per segment, so a short secret that also occurs inside a slug leaves the slug alone.
            $segments = explode('/', $path);
            foreach ($segments as $i => $segment) {
                if (isset($secrets[$segment])) {
                    $segments[$i] = '{'.$secrets[$segment].'}';
                }
            }
            $path = implode('/', $segments);

            // A secret that spans segments (a `.*` parameter) is replaced whole.
            foreach ($secrets as $value => $name) {
                if (str_contains((string) $value, '/')) {
                    $path = str_replace((string) $value, '{'.$name.'}', $path);
                }
            }

            return $path;
        } catch (Throwable) {
            return '';
        }
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

    /** @return array<string, string>|null raw value => parameter name; null when no route matched */
    private static function secretValues(Request $request): ?array
    {
        $route = $request->route();
        if (! is_object($route)) {
            return null;
        }

        $names = (array) config('observability.secret_route_parameters', []);
        $secrets = [];
        foreach ((array) $route->originalParameters() as $name => $value) {
            if (in_array($name, $names, true) && is_string($value) && $value !== '') {
                $secrets[rawurldecode($value)] = $name;
            }
        }

        return $secrets;
    }
}
