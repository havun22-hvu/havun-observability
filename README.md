# havun/observability

Sends errors, `Log::error`, slow queries and request metrics from a Havun site to HavunCore —
without personal data, and without the site ever waiting on HavunCore.

## Rules

- **The site never waits on HavunCore.** Request metrics and slow queries are written after the
  response. Every write has a 2 s connect/lock timeout. One failure opens a breaker: HavunCore is
  left alone for 60 s and errors go to `storage/app/observability/error-spool.jsonl` (a folder that git-ignores itself), sent on by
  `observability:flush-spool` (scheduled every 5 min).
- **Nothing personal leaves the site:** no IP, no query string, no secret route parameter
  (`{token}`, `{code}`, `{qrCode}`), no query bindings, e-mail addresses masked. Log context is
  never stored.

## Install

```bash
composer require havun/observability   # repository: https://github.com/havun22-hvu/havun-observability
```

`.env`: `OBSERVABILITY_PROJECT=<key in HavunCore>`; tests: `OBSERVABILITY_ENABLED=false`, and with
`--parallel` a `state_path` per process (spool + breaker), e.g. `storage_path("app/obs-".ParallelTesting::token())`.
The package registers itself: exception reporting, the log bridge, the metrics middleware, the
slow-query listener and the spool command. Remove the site's own copies of those.

A site's own kinds (self-report, business alert, browser error) go through
`app(Havun\Observability\Reporter::class)->record([...])`. The error page shows
`Reporter::lastReference()` — the first 8 characters of the fingerprint.

Config defaults: `config/observability.php` (`vendor:publish --tag=observability-config`).
HavunCore side: `HavunCore/docs/kb/reference/foutmeldingen.md`.
