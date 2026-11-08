# Performance Console 3.1.9: Frontend incident and Object Cache Pro corrections

## Installation

Update the `performance-console` WordPress plugin over 3.1.8 after a backup, preferably on staging first. MU bootstrap **2.8.0** is unchanged. AI export schema is **2.0**.

## Fixes

- **Frontend incidents:** A ~1.9-second *local HTTP round-trip* is elevated (**high**) but is not automatically a *critical* fault. Critical is now reserved for GETs taking 5 seconds or longer. This timing includes PHP, database, page rendering, local HTTP and any intermediate layers, and cannot by itself identify CDN, DNS or Redis as the cause. Historical incidents retain their stored severity until an exact-route recheck or appropriate scan refreshes them. Recheck now tests one representative route, not all grouped routes.
- **Route-specific verification:** The incident action validates the origin and probes the actual affected URL rather than relying on a shallow scan of several unrelated routes. If a route cannot be confirmed, the incident remains open. An unconfirmed single route moves to *observing*, not resolved, and does not clear other grouped routes.
- **UI spacing:** More consistent incident header/body/footer padding; distinct route-list spacing; readable wrapping for long URLs; aligned disclosure toggle and action buttons; responsive stacked layouts; consistent inset content for detailed diagnostics.
- **OCP attribution:** The original raw signed callback fields are `cache_get_lookups` and `inclusive_ms`, not `cache_get_lookups_during_scope` and `inclusive_php_ms`. Both admin and export now read actual raw measurements; legacy keys are accepted only for backwards compatibility. Null is retained if an actual measurement is unavailable, rather than silently reporting zero. JSON adds `cache_call_attribution.populated_rows` and `coverage`.
- **Direct Redis diagnostics:** The temporary-key SET benchmark now collects supported read-only Redis INFO counters before the write attempt. If a diagnostic SET fails, the export retains the memory snapshot and the sanitized `result_category` (ACL, read-only, auth, etc.). It prioritizes the explicit provider prefix if configured, but the probe keyspace is still only a candidate: a denied SET is not proof WordPress's Object Cache Pro cannot write. Credentials, raw errors, raw cache keys and ACL rules never leave the inspector.

## Validation workflow

1. In **Performance → Performance incidents**, recheck the frontend slow-route incident. Its ~1.9-second timing should be high, with a note to compare a signed PHP profile for the **same** route.
2. Expand **View affected routes**; confirm route chips wrap, the expand control is aligned, and footer actions are separated at desktop and mobile widths.
3. In **Performance → Cache & Redis**, run a signed deep PHP profile, then export the diagnostic JSON. Under `object_cache_pro.cache_call_attribution`, expect positive `populated_rows` whenever the signed callback profiler measured scopes.
4. Run **Redis, Autoload & Object Cache Pro Diagnostics**. For a denied synthetic SET, inspect the sanitized failure category and verify the read-only INFO snapshot is still present when supported. Do not change ACL permissions solely because this synthetic key is denied.
5. Run a normal signed profile without deep instrumentation for a separate, more representative PHP timing comparison.

## Limitations

This build changes reporting, incident verification and administrator presentation, not production Bricks query costs or Redis server performance. It does not fix native Object Cache Pro Key Tracking errors. Live end-to-end WordPress and Object Cache Pro behaviour must be verified on staging or production. Cache counter changes inside callbacks are **not** individual Redis network operations. The non-destructive synthetic benchmark uses temporary keys with short TTLs and performs no cache flushes.
