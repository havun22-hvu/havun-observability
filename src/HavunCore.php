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

    /**
     * Where the spool and the breaker live: config `state_path`, default storage/app/observability.
     * Parallel test processes each set their own, or one process's breaker silences the others.
     */
    public static function statePath(string $file): string
    {
        $dir = config('observability.state_path') ?: self::defaultStateDir();

        return rtrim((string) $dir, '/\\').DIRECTORY_SEPARATOR.$file;
    }

    /**
     * A folder that git-ignores itself: a site whose storage/app is not ignored showed the breaker
     * flag as a dirty checkout (JudoToernooi, 23-10-2026). A spool left in storage/app by 1.0.x
     * moves along, so no pending error is lost.
     */
    private static function defaultStateDir(): string
    {
        $dir = storage_path('app/observability');
        if (! is_file($dir.'/.gitignore')) {
            @mkdir($dir, 0775, true);
            @file_put_contents($dir.'/.gitignore', "*\n");
            $oud = storage_path('app/error-spool.jsonl');
            if (is_file($oud) && ! is_file($dir.'/error-spool.jsonl')) {
                @rename($oud, $dir.'/error-spool.jsonl');
            }
            @unlink(storage_path('app/observability-breaker'));
        }

        return $dir;
    }

    private static function breakerFile(): string
    {
        return HavunCore::statePath('observability-breaker');
    }
}
