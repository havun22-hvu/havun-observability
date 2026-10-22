<?php

namespace Havun\Observability;

use Havun\Observability\Console\FlushSpool;
use Havun\Observability\Http\RequestMetrics;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Wires everything; a site only installs the package and sets OBSERVABILITY_PROJECT.
 * Errors, `Log::error`, slow queries and request metrics go to HavunCore without personal data,
 * and the site never waits on HavunCore (HavunCore::insert, RequestMetrics::terminate).
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /** HavunCore's own tables: a slow write there must not report itself. */
    private const OWN_TABLES = ['request_metrics', 'error_logs', 'slow_queries', 'metrics_aggregated'];

    /** @var list<array<string, mixed>> slow queries of this request, written after the response */
    private array $slowQueries = [];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observability.php', 'observability');
        $this->app->singleton(Reporter::class);
        // One instance per request: terminate() must see the start time handle() set.
        $this->app->scoped(RequestMetrics::class);
        $this->configureConnection();
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/observability.php' => config_path('observability.php')], 'observability-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FlushSpool::class]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('observability:flush-spool')->everyFiveMinutes()->withoutOverlapping();
            });
        }

        if (! config('observability.enabled', true)) {
            return;
        }

        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e): void {
            $this->app->make(Reporter::class)->report($e);
        });
        Event::listen(MessageLogged::class, [Reporter::class, 'onMessageLogged']);

        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(RequestMetrics::class);
        }

        $this->listenForSlowQueries();
    }

    /**
     * The HavunCore connection with short connect and lock timeouts, so a locked or unreachable
     * HavunCore database costs a request at most `timeout_seconds` once per breaker period.
     */
    private function configureConnection(): void
    {
        $name = (string) config('observability.connection', 'havuncore');
        $timeout = (int) config('observability.timeout_seconds', 2);
        $base = config("database.connections.{$name}")
            ?? array_merge((array) config('database.connections.'.config('database.default')), ['database' => 'havuncore']);

        if (($base['driver'] ?? null) === 'mysql' || ($base['driver'] ?? null) === 'mariadb') {
            $base['options'] = ($base['options'] ?? []) + [
                \PDO::ATTR_TIMEOUT => $timeout,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION innodb_lock_wait_timeout={$timeout}, lock_wait_timeout={$timeout}",
            ];
        }

        config(["database.connections.{$name}" => $base]);
    }

    private function listenForSlowQueries(): void
    {
        $threshold = (float) config('observability.slow_query_threshold_ms', 100);

        DB::listen(function (QueryExecuted $query) use ($threshold): void {
            if ($query->time < $threshold || HavunCore::writing()) {
                return;
            }
            foreach (self::OWN_TABLES as $table) {
                if (str_contains($query->sql, $table)) {
                    return;
                }
            }

            // A routed HTTP request writes after the response; a worker or command has no
            // "after the response" (terminating never fires in a worker) and writes now.
            $inRequest = $this->app->bound('request') && request()->route() !== null;

            $row = [
                'project' => (string) config('observability.project'),
                // Template only: bindings carry e-mail addresses, names and tokens.
                'query' => $query->sql,
                'time_ms' => $query->time,
                'connection' => $query->connectionName,
                'request_path' => $inRequest ? SafePath::path(request()) : null,
                'created_at' => now()->toDateTimeString(),
            ];

            if (! $inRequest) {
                HavunCore::insert('slow_queries', [$row]);

                return;
            }
            if ($this->slowQueries === []) {
                $this->app->terminating(function (): void {
                    HavunCore::insert('slow_queries', $this->slowQueries);
                    $this->slowQueries = [];
                });
            }
            $this->slowQueries[] = $row;
        });
    }
}
