# Performance Console 3.1.11: Native Key Tracking and Flush Evidence

## Scope
- P0: Native Object Cache Pro `Key Tracking: Error` triage. A confirmed error with an `unknown` category is **not** a known failing Redis command. The triage matrix separates native Site Health evidence from read-only `PING`, `INFO` and `SCAN` tests. Restricted `ACL WHOAMI` and `CLIENT TRACKINGINFO` never determine a Key Tracking root cause.
- P1: Improved passive cache flush trace extraction using Object Cache Pro's argument-free pre-flush trace where supplied. Restricted relative source, code-only caller name, confidence and anonymous group fingerprint are retained. Recent same-group flush-attempt bursts are summarized, not treated as completed Redis flushes.
- P1: WordPress Bricks post metadata filters now report bounded repeated key/object observations, recognizing sampling truncation. They are not Redis command observations or proof of redundant reads.
- UI and AI schema 2.2: Additional diagnostic evidence and layout improvements. Keys, values, credentials, raw paths, object IDs and raw native diagnostics remain excluded from the AI export.

## How to verify on the site
1. Back up Performance Console and install version 3.1.11, ideally on staging first. No MU bootstrap update is required (2.8.0).
2. Open **Performance → Cache & Redis**, run **Redis, Autoload & Object Cache Pro Diagnostics** and inspect **Native Key Tracking diagnosis**. Record whether the status is native_error, whether a fixed failure category is available, and whether exact native command remains unknown.
3. For an unknown cause, inspect **Tools → Site Health → Info** and Object Cache Pro's native CLI diagnostics (1.x: `wp redis diagnostics`; 2.x: `wp objectcache diagnostics`). Do not paste full CLI dumps or credentials into chat. Capture only the specific error category/command when safely verified.
4. When a cache-group flush attempt occurs, inspect **Passive flush investigator**. A caller/source may be unavailable if the hook trace cannot safely identify one. The burst table is observational.
5. Run signed deep PHP profiling to review Bricks accessor repeat evidence, then compare against a signed standard profile. Sampling does not imply user-facing request savings.
6. Export AI JSON schema 2.2 after the new diagnostics. New data requires a fresh test: exporting an old saved diagnostic will not create new health or flush evidence.

## Safety and limits
- No Redis write, configuration modification, ACL edits, keyspace scan, group flush, cache value retrieval or Object Cache Pro internal method invocation was added by this release.
- Existing optional Object Cache Pro group inventory and synthetic Redis benchmark remain separate opt-in diagnostics from previous versions.
- Native Key Tracking may remain unresolved if Object Cache Pro exposes only `Error`. A proprietary health check cannot safely be reproduced from its name alone.
- `pre_objectcache_flush` and `pre_objectcache_flush_group` are passive hooks. Returning the incoming value unchanged preserves provider behaviour. The monitor does not confirm flush completion.
- Native cache-group counts and Redis memory may reflect a warming cache and do not prove leaks or current eviction pressure.

## Optional administrator classification
When Site Health only returns “Error”, a privileged administrator can select a fixed error category and command *only if actually named in Object Cache Pro CLI diagnostics*. These selections are stored for up to 24 hours separately from native status, and exported as administrator-reported, never as confirmed commands. No free-text diagnostics or secrets are accepted.
