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
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observability.php', 'observability');
        $this->app->singleton(Reporter::class);
        $this->app->singleton(SlowQueries::class);
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

        // The event, not DB::listen: every connection, and no connection resolved at boot.
        Event::listen(QueryExecuted::class, [SlowQueries::class, 'onQuery']);
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

        // MySQL/MariaDB only (every Havun site). ATTR_TIMEOUT is the connect timeout; lock waits
        // get their own. A slow server after connecting only delays the write, which happens
        // after the response.
        if (in_array($base['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $options = (array) ($base['options'] ?? []);
            $init = "SET SESSION innodb_lock_wait_timeout={$timeout}, lock_wait_timeout={$timeout}";
            $options[\PDO::ATTR_TIMEOUT] = $timeout;
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = isset($options[\PDO::MYSQL_ATTR_INIT_COMMAND])
                ? $options[\PDO::MYSQL_ATTR_INIT_COMMAND].'; '.$init
                : $init;
            $base['options'] = $options;
        }

        config(["database.connections.{$name}" => $base]);
    }
}
