<?php

// Havun observability — what a site sends to HavunCore. Publish with
// `php artisan vendor:publish --tag=observability-config` only to change a default.
return [
    'enabled' => env('OBSERVABILITY_ENABLED', true),

    // Key in HavunCore error_logs / request_metrics / slow_queries, e.g. judotoernooi.
    'project' => env('OBSERVABILITY_PROJECT', env('APP_NAME')),

    // Connection to the HavunCore database. Missing → built from the default connection
    // with database `havuncore`. Either way it gets the short timeouts below.
    'connection' => 'havuncore',

    // The site never waits on HavunCore: connect and lock waits stop after this many seconds.
    'timeout_seconds' => 2,

    // After a failed write HavunCore is left alone for this long; errors go to the spool.
    'breaker_seconds' => 60,

    // Route parameters whose value is a secret: replaced by {name} in every stored path.
    'secret_route_parameters' => ['token', 'code', 'qrCode'],

    'slow_query_threshold_ms' => env('SLOW_QUERY_THRESHOLD_MS', 100),
    'sampling_rate' => env('OBSERVABILITY_SAMPLING_RATE', 1.0),
    'excluded_paths' => ['up', 'health'],
    'trace_max_length' => 5000,

    // Messages that are infrastructure noise, not an error of the site.
    'skip_messages' => ['EADDRINUSE', 'ECONNREFUSED', 'disk full', 'sock permission'],
];
