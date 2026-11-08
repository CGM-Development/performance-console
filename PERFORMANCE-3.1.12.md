# Performance Console 3.1.12: Native Tracking Evidence and Cache Activity Follow-up

## Changes

- **P0 Native Key Tracking:** Records whether the provider returns status-only `Error` or a descriptive result. An administrator can optionally paste **one pre-sanitized error line**, which is parsed in memory and discarded. Only fixed enums for failure category and explicitly identified Redis command are retained. Status-only native errors remain unresolved; optional ACL denials are not treated as causal.
- **P1 Flush investigation:** Passively labels pre-flush attempts as recent (last ten minutes) or historical, recomputing freshness when viewing/exporting an older scan. Legacy caller names without proven source remain unverified. Future events include bounded native/runtime evidence when available; no confirmed Redis flush is claimed.
- **P1 Cache inventory comparisons:** Show observed key differences and an approximate keys-per-hour change when separately triggered scans are at least five minutes apart. No scans or flushes run automatically.
- **UI:** Improved native diagnostic form and group comparison spacing, including mobile layout.
- **AI JSON:** Schema 2.3 with `detail_level`, `flush_freshness`, and `keys_per_hour`, with all raw native error text, credentials, cache keys and object values excluded.

## Test on the live site

1. Back up the plugin; install 3.1.12, ideally on staging first. The MU diagnostic bootstrap remains 2.8.0.
2. Run **Redis, Autoload & Object Cache Pro Diagnostics**. Key Tracking should show *status only* when native Site Health reports only `Error`.
3. If a native Object Cache Pro CLI diagnostic includes a specific error line, review it for credentials, submit only that single line to the optional local classifier, and check the fixed category. Never paste an entire diagnostic dump.
4. Check the flush investigator: historical Keystone events remain historical, not ongoing activity.
5. Run two separate cache-group inventories at least five minutes apart to see key changes/hour. Memory deltas need both runs to include memory measurement.
6. Export schema 2.3, review it, and compare to a standard signed profile. Deep diagnostic timings include instrumentation overhead.

## Limitations

- No Redis ACL, eviction, serializer, connection, plugin or Bricks settings are changed.
- The UI cannot identify a native Key Tracking root cause if Object Cache Pro only reports `Error`. A manually provided error line is administrator evidence, not independently verified native evidence.
- The native Object Cache Pro CLI for the installed major version can provide additional detail (`wp redis diagnostics` for 1.x, `wp objectcache diagnostics` for 2.x).
- Observed group-flush attempts are not verified completions. Cache-group counts are not memory leaks or Redis command timings.
- Live WordPress/Object Cache Pro integration testing must be performed after installation.
