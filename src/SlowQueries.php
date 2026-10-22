<?php

namespace Havun\Observability;

use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/** Slow queries as SQL templates (bindings carry e-mail addresses, names, tokens). */
class SlowQueries
{
    /** HavunCore's own tables: a slow write there must not report itself. */
    private const OWN_TABLES = ['request_metrics', 'error_logs', 'slow_queries', 'metrics_aggregated'];

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    public function onQuery(QueryExecuted $query): void
    {
        try {
            if ($query->time < (float) config('observability.slow_query_threshold_ms', 100) || HavunCore::writing()) {
                return;
            }
            foreach (self::OWN_TABLES as $table) {
                if (str_contains($query->sql, $table)) {
                    return;
                }
            }

            $row = [
                'project' => (string) config('observability.project'),
                'query' => $query->sql,
                'time_ms' => $query->time,
                'connection' => $query->connectionName,
                'request_path' => HavunCore::inRequest() ? SafePath::path(request()) : null,
                'created_at' => now()->toDateTimeString(),
            ];

            // A request writes after the response; a worker or command has no "after" and writes now.
            if (HavunCore::inRequest()) {
                $this->pending[] = $row;
            } else {
                HavunCore::insert('slow_queries', [$row]);
            }
        } catch (Throwable) {
        }
    }

    public function flush(): void
    {
        $rows = $this->pending;
        $this->pending = [];
        HavunCore::insert('slow_queries', $rows);
    }
}
