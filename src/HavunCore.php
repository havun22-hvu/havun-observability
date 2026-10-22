<?php

namespace Havun\Observability;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only door to the HavunCore database. A site must keep working when HavunCore is down,
 * slow or locked: during a request nothing is written until the response has gone out
 * (RequestMetrics::terminate flushes), every write has short timeouts and never throws, and
 * after one failure the breaker keeps HavunCore closed for `breaker_seconds`.
 */
final class HavunCore
{
    /** Re-entrancy guard: a failing insert may log, and logging may report again. */
    private static bool $writing = false;

    /** Set by RequestMetrics while an HTTP request is being handled. */
    private static bool $inRequest = false;

    /** In-process copy of the breaker, for when the breaker file cannot be written. */
    private static ?int $openedAt = null;

    public static function writing(): bool
    {
        return self::$writing;
    }

    public static function inRequest(): bool
    {
        return self::$inRequest;
    }

    public static function setInRequest(bool $inRequest): void
    {
        self::$inRequest = $inRequest;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return bool false when the rows were not written (breaker open or the insert failed)
     */
    public static function insert(string $table, array $rows): bool
    {
        if ($rows === []) {
            return true;
        }
        if (self::isOpen()) {
            return false;
        }

        self::$writing = true;
        try {
            DB::connection((string) config('observability.connection'))->table($table)->insert($rows);

            return true;
        } catch (Throwable) {
            self::open();

            return false;
        } finally {
            self::$writing = false;
        }
    }

    public static function isOpen(): bool
    {
        $now = now()->getTimestamp();
        $period = (int) config('observability.breaker_seconds', 60);

        if (self::$openedAt !== null && $now - self::$openedAt < $period) {
            return true;
        }

        $file = self::breakerFile();
        clearstatcache(true, $file);

        return is_file($file) && $now - (int) @filemtime($file) < $period;
    }

    /** Test helper: close the breaker and forget the request state. */
    public static function reset(): void
    {
        self::$openedAt = null;
        self::$inRequest = false;
        @unlink(self::breakerFile());
    }

    private static function open(): void
    {
        self::$openedAt = now()->getTimestamp();
        @touch(self::breakerFile(), self::$openedAt);
    }

    private static function breakerFile(): string
    {
        return storage_path('framework/observability-breaker');
    }
}
