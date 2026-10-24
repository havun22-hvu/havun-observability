<?php

namespace Havun\Observability;

use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

/**
 * Writes errors to HavunCore `error_logs`, where they are grouped and pushed to Henk
 * (HavunCore docs/kb/reference/foutmeldingen.md). Nothing personal leaves this class: no query
 * string, no path secrets, no IP, no query bindings, no e-mail address. HavunCore unreachable →
 * the row goes to a local spool that `observability:flush-spool` sends on.
 */
class Reporter
{
    private const REPORTED_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    private const SPOOL_MAX_LINES = 1000;

    private const SPOOL_MAX_BYTES = 5 * 1024 * 1024;

    /** Exceptions already written: the handler both reports and logs each one. */
    private WeakMap $reported;

    private ?string $lastReference = null;

    /** @var list<array<string, mixed>> rows of this request, written after the response */
    private array $pending = [];

    public function __construct()
    {
        $this->reported = new WeakMap;
    }

    public function report(Throwable $e): void
    {
        try {
            $this->reportException($e);
        } catch (Throwable) {
            // Reporting must never become an error of the site.
        }
    }

    private function reportException(Throwable $e): void
    {
        if (! $this->enabled() || isset($this->reported[$e])) {
            return;
        }
        $this->reported[$e] = true;

        foreach ((array) config('observability.skip_messages', []) as $pattern) {
            if (str_contains($e->getMessage(), (string) $pattern)) {
                return;
            }
        }

        $this->record([
            'exception_class' => get_class($e),
            'message' => $this->messageOf($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->scrub(mb_substr($this->traceOf($e), 0, (int) config('observability.trace_max_length', 5000))),
            'severity' => $this->severityOf($e),
        ]);
    }

    /**
     * Log bridge: a `Log::error` in a catch block reaches error_logs as well. With an exception in
     * the context it is reported as that exception, otherwise as `Log::<level>` at the call site.
     * The context itself is never stored.
     */
    public function onMessageLogged(MessageLogged $event): void
    {
        if (HavunCore::writing() || ! $this->enabled() || ! in_array($event->level, self::REPORTED_LEVELS, true)) {
            return;
        }

        try {
            $this->reportLog($event);
        } catch (Throwable) {
        }
    }

    private function reportLog(MessageLogged $event): void
    {
        if (($event->context['exception'] ?? null) instanceof Throwable) {
            $this->report($event->context['exception']);

            return;
        }

        [$file, $line] = $this->callSite();

        $this->record([
            'exception_class' => "Log::{$event->level}",
            'message' => mb_substr((string) $event->message, 0, 65535),
            'file' => $file,
            'line' => $line,
            'trace' => null,
            'severity' => $event->level === 'error' ? 'error' : 'critical',
        ]);
    }

    /**
     * One error_logs row. Sites use this for their own kinds (a self-report, a business alert,
     * a browser error); pass only fields without personal data — `message` is scrubbed anyway.
     *
     * @param  array<string, mixed>  $row  at least exception_class; file/line feed the fingerprint
     */
    public function record(array $row): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $this->recordRow($row);
        } catch (Throwable) {
        }
    }

    /** Writes the rows of this request; RequestMetrics::terminate calls it after the response. */
    public function flushPending(): void
    {
        $rows = $this->pending;
        $this->pending = [];
        if ($rows !== [] && ! HavunCore::insert('error_logs', $rows)) {
            array_walk($rows, fn (array $row) => $this->spool($row));
        }
    }

    /** @param array<string, mixed> $row */
    private function recordRow(array $row): void
    {
        $request = app()->bound('request') ? request() : null;
        $row['message'] = $this->scrub((string) ($row['message'] ?? ''));
        $row += [
            'file' => null,
            'line' => null,
            'trace' => null,
            'severity' => 'error',
            'project' => (string) config('observability.project'),
            'url' => SafePath::url($request),
            'method' => $request?->method(),
            'ip_address' => null,
            'request_id' => Context::get('request_id'),
            'fingerprint' => hash('sha256', $row['exception_class'].($row['file'] ?? '').($row['line'] ?? '')),
            // Strings, so a spooled row inserts the same way later.
            'last_occurred_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];

        $this->lastReference = self::reference($row['fingerprint']);

        // A visitor never waits: during a request the row goes out after the response.
        if (HavunCore::inRequest()) {
            $this->pending[] = $row;

            return;
        }

        if (! HavunCore::insert('error_logs', [$row])) {
            $this->spool($row);
        }
    }

    /** Short code for the error page: a customer quotes it, HavunCore finds the group by fingerprint. */
    public static function reference(string $fingerprint): string
    {
        return strtoupper(substr($fingerprint, 0, 8));
    }

    /** Reference of the last error recorded in this request, or null. */
    public function lastReference(): ?string
    {
        return $this->lastReference;
    }

    /** Masks personal data in free text: e-mail addresses, IP addresses and URL query strings. */
    public function scrub(string $text): string
    {
        $text = (string) preg_replace('/[\w.+-]+(?:@|%40)[\w-]+(?:\.[\w-]+)+/u', '[e-mail]', $text);
        $text = (string) preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $text);
        // Full IPv6 only: shorter forms collide with times (08:27:01) and Class::method.
        $text = (string) preg_replace('/\b(?:[0-9a-f]{1,4}:){4,7}[0-9a-f]{1,4}\b/i', '[ip]', $text);
        // Relative URLs too: "/login?token=x" keeps "/login".
        $text = (string) preg_replace('#((?:https?://|/)[^\s?\#]*)[?\#][^\s)]+#', '$1', $text);

        return $text;
    }

    /** The trace without argument values, whatever `zend.exception_ignore_args` says. */
    private function traceOf(Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $i => $frame) {
            $lines[] = sprintf('#%d %s(%s): %s%s%s()', $i, $frame['file'] ?? '[internal]', $frame['line'] ?? '?',
                $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
        }

        return implode("
", $lines);
    }

    /**
     * Sends spooled rows on; stops at the first failure and keeps the newest unsent rows.
     *
     * @return array{sent: int, left: int}
     */
    public function flushSpool(): array
    {
        $path = $this->spoolPath();
        if (! is_file($path) || HavunCore::isOpen()) {
            return ['sent' => 0, 'left' => is_file($path) ? count(file($path, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES) ?: []) : 0];
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            return ['sent' => 0, 'left' => 0]; // another flush runs, or the file is unreadable
        }
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($handle))));

        $sent = 0;
        foreach ($lines as $line) {
            if (! HavunCore::insert('error_logs', [(array) json_decode($line, true)])) {
                break;
            }
            $sent++;
        }

        $left = array_slice(array_slice($lines, $sent), -self::SPOOL_MAX_LINES);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $left ? implode("\n", $left)."\n" : '');
        flock($handle, LOCK_UN);
        fclose($handle);

        if (! $left) {
            @unlink($path);
        }

        return ['sent' => $sent, 'left' => count($left)];
    }

    /** @param array<string, mixed> $row */
    private function spool(array $row): void
    {
        $path = $this->spoolPath();

        try {
            if (is_file($path) && filesize($path) > self::SPOOL_MAX_BYTES) {
                return; // a long outage must not fill the disk
            }
            file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
        }
    }

    private function spoolPath(): string
    {
        return HavunCore::statePath('error-spool.jsonl');
    }

    private function messageOf(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            // The driver message quotes the offending value ("Duplicate entry 'x'"); the SQL
            // template keeps the placeholders, never the bindings.
            $driver = (string) preg_replace("/'[^']*'/", '?', (string) $e->getPrevious()?->getMessage());

            return $this->scrub("{$driver} (SQL: {$e->getSql()})");
        }

        return $this->scrub(mb_substr($e->getMessage(), 0, 65535));
    }

    private function severityOf(Throwable $e): string
    {
        return match (true) {
            $e instanceof \Error => 'critical',
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => 'warning',
            default => 'error',
        };
    }

    private function enabled(): bool
    {
        return (bool) config('observability.enabled', true);
    }

    /** @return array{0: ?string, 1: ?int} first frame outside vendor/: where Log::error was called */
    private function callSite(): array
    {
        $vendor = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file && ! str_contains($file, $vendor) && ! str_starts_with($file, __DIR__)) {
                return [$file, $frame['line'] ?? null];
            }
        }

        return [null, null];
    }
}
