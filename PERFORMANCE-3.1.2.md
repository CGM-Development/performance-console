# Performance Console 3.1.2: HTTP call-site and first-request diagnostics

This release builds on 3.1.1. It adds targeted evidence for recurring third-party HTTP 429 responses and costly first-request SQL. **It does not automatically modify AAWP, Bricks, WPCodeBox, Redis or other plugins.**

## New HTTP inspector

Under **Performance → Cache & Redis**, run a signed profile (3–5 requests). The **HTTP call-site inspector** shows the inferred originating PHP callable, relative source file/line, parent WordPress hook (when available), component, request count, blocking count, summed/slowest WordPress HTTP API duration, failed calls, HTTP 429 count, and failures without a response status.

Destination groups use a site-secret HMAC of HTTP method + host + path. No URL, host, path, query string, credentials, request headers, bodies, exception text, or complete stack trace is saved for newly recorded calls. **Calls sharing a target group ID are not necessarily retries:** different query strings can map to the same group. An actual HTTP 429 is a provider response, not an automatic reason to retry.

Each signed request includes no more than 100 HTTP events; the inspector groups up to 40 entries internally and shows 20. The administrator AI export includes a maximum of 15 safe groups. Existing sampled requests do not get new backtrace instrumentation; HTTP source tracing is enabled only for signed deep requests. There is no additional frontend hook for normal traffic.

## First request vs following SQL

For at least three verified requests with SQL timing, the report compares the first SQL total with the median of following runs. It lists up to six normalized SQL pattern fingerprints costing at least 10 ms on run 1 but absent from the later (bounded) top-pattern lists. This may help diagnose Bricks query-loop spikes, but it **does not prove that the database was cold**. All signed requests still bypass full-page cache.

## Workload advisor and exports

Repeated HTTP 429 responses from the same component across at least two verified runs now trigger a high-priority repair recommendation. A sufficiently large first-request SQL spike generates a separate guided finding. Both actions remain manual and non-destructive.

AI export schema **1.3** adds `http_callsite_inspector`, `http_callsites_truncated`, a `target_group_id` to per-call HTTP summaries and `first_vs_following_sql`. Existing SQL call sites remain in the export. All new fields pass a strict allowlist that discards absolute paths and unsafe callables.

## How to test

1. Back up and install this ZIP over Performance Console 3.1.1.
2. Open **Performance → Cache & Redis**. A 3.1.1 MU bootstrap is still sufficient; this release does not modify it.
3. Profile the homepage with **five verified** signed requests.
4. Check the HTTP call-site inspector for AAWP, and confirm whether a 429 is recurring and which PHP function initiates it.
5. Check first-only SQL patterns for Bricks and repeated SQL call sites for WPCodeBox.
6. Export AI JSON, verify `source.version=3.1.2` and `schema_version=1.3`, then compare with the previous export.

All measurements are from intrusive diagnostic requests, not normal visitor load times. Live WordPress integration testing is required before claiming site-level improvements. Do not interpret cache hit/miss counters as Redis network calls.
