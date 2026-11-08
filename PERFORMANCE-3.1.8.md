# Performance Console 3.1.8: Object Cache Pro Troubleshooter

## Installation
Upload the `performance-console` directory as a normal WordPress plugin. This is an in-place update from 3.1.7. The MU bootstrap stays at **2.8.0**, because no early bootstrap logic changed. The installed/required bootstrap versions and repair control are now shown in **Performance → Cache & Redis** in addition to Overview.

## Object Cache Pro module
Open **Performance → Cache & Redis → Object Cache Pro Troubleshooter**. Click **Run Redis, Autoload & Object Cache Pro Diagnostics**. Results are administrator-only and available in the existing AI JSON export, schema **1.9**, under `object_cache_pro`.

### Included
- Detection of Object Cache Pro plugin/drop-in without confusing `WP_REDIS_CONFIG` alone with proof of Object Cache Pro installation. Sites using other cache drop-ins receive an explicit unconfirmed/not-detected status.
- Native **WordPress Site Health** `objectcache_redis_tracking_keys` results *only if* the running version registers that check as a callable direct Site Health test. Many versions expose tracking health through their own dashboard instead, in which case `not_exposed` is reported. Do not misrepresent this as a successful or failed tracking test.
- Read-only Redis capability checks on a separate short-timeout PhpRedis connection: `PING`, `INFO server`, a bounded first `SCAN` iteration without storing keys, `ACL WHOAMI` without storing usernames, and `CLIENT TRACKINGINFO`. Command denial/status is categorized. `ACL WHOAMI` is an optional *diagnostic* permission, not necessarily needed for the cache to run.
- Optional Object Cache Pro native `GET /objectcache/v1/groups` request via WordPress internal REST dispatch. This can scan the keyspace, so it **only runs when checked**. Memory estimates (`memory=1`) require a second opt-in checkbox and may be costly on large caches. The plugin never calls Object Cache Pro's DELETE endpoints.
- Passive `pre_objectcache_flush` and `pre_objectcache_flush_group` hooks preserve the original decision and inputs, aggregate up to 24 *attempts* with safe caller/component names and HMAC group identifiers, and persist at shutdown without caching key values or stacks. The monitor only records attempts after the new version is installed; native Object Cache Pro history prior to installation is not imported. An attempt may have been declined or failed.
- Isolated 25-iteration synthetic PHP `serialize`/`unserialize`, optional `igbinary_*`, optional `lz4_*` and zlib round-trip measurements. No WordPress cache values are used; results do not establish which encoding is best for real production workloads.
- Existing signed PHP callback profiling is reused to identify callback scopes with heavy WordPress cache GET-counter changes, rather than falsely treating those counters as separate Redis commands.
- New AI-export sections: health_checks, key_tracking_diagnostics, acl_diagnostics, cache_group_metrics, cache_call_attribution, cache_overhead_breakdown, slow_operations, flush_history, serialization_benchmarks and prioritized recommendation identifiers.
- Guidance-first repair table. **No automatic cache flush, Redis ACL adjustment, `wp-config.php` mutation, key/value scanning, or Object Cache Pro debug enablement.** Debug settings are reported as booleans only.

### Limitations
- WordPress has no generic public per-`wp_cache_get()` hook that yields a stack trace and operation duration across all object-cache drop-ins. Exact per-operation/Redis call-site attribution is **not measured**. Object Cache Pro's Query Monitor command logging can supply more detail but is memory intensive and should be enabled only briefly in a controlled environment.
- No per-key oversized-object enumeration is performed, and no raw Redis key names, contents, group names outside an explicit safe allowlist, usernames, credentials or errors are persisted or exported.
- The existing synthetic Redis test writes and deletes its **own short-TTL** keys. This module's ACL checks are read-only. The optional native REST cache-group enumeration is read-only but may consume server resources.
- Paired signed requests and synthetic microbenchmarks are not equivalent to representative uninstrumented production percentiles.

## Test procedure
1. Back up the current plugin and update to 3.1.8 on staging first.
2. Verify Cache & Redis reports **MU bootstrap installed: 2.8.0 / required: 2.8.0**.
3. Run normal Redis/Autoload/OCP diagnostics without selecting group inventory. Confirm the provider detection and capability table; on non-OCP drop-ins, confirm no OCP-specific ACL probe runs.
4. If Object Cache Pro is active, optionally test group inventory off-peak. Enable memory estimates only when safe to run a potentially costly scan.
5. Run a signed 3–5-request deep PHP profile and inspect the callback scope cache lookups.
6. Export JSON; confirm `schema_version: 1.9` and `object_cache_pro` contains only aggregate/redacted data.
7. Review **Object Cache Pro → Tools/Site Health** directly if the key-tracking check is not registered in WordPress Site Health. Compare with the read-only ACL capability results; a denied optional command is not conclusive proof of the key tracking error.

**Compatibility:** Object Cache Pro's internal implementation is not replaced or called by undocumented PHP APIs. WordPress `WP_REDIS_CONFIG` alone is not taken as proof of Object Cache Pro installation. No production integration test has been performed; PHP syntax and isolated unit regressions are run locally.
