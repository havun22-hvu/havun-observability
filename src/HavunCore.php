<?php

namespace Havun\Observability;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only door to the HavunCore database. A site must keep working when HavunCore is down,
 * slow or locked: every write has short timeouts, never throws, and after one failure the
 * breaker keeps HavunCore closed for `breaker_seconds` so no request waits on it again.
 */
final class HavunCore
{
    /** Re-entrancy guard: a failing insert may log, and logging may report again. */
    private static bool $writing = false;

    public static function writing(): bool
    {
        return self::$writing;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return bool false when the rows were not written (breaker open or the insert failed)
     */
    public static function insert(string $table, array $rows): bool
    {
        if ($rows === [] || self::isOpen()) {
            return $rows === [];
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
        $file = self::breakerFile();

        return is_file($file) && (now()->getTimestamp() - (int) @filemtime($file)) < (int) config('observability.breaker_seconds', 60);
    }

    private static function open(): void
    {
        @touch(self::breakerFile());
    }

    private static function breakerFile(): string
    {
        return storage_path('framework/observability-breaker');
    }
}
