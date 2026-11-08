# Performance Console 3.1.7: Targeted Bricks Initialization and Comparison

## Changes

1. **Correctly prioritize first-request SQL-heavy elements.** The 3.1.6 admin table relied on the final verified request, when Bricks SQL is often already warmed. It now reads the first verified request and matches later requests by exact anonymized element group and query shape. A null later SQL value means the element was not matched, not zero. The table displays raw short `brxe-` IDs to administrators only. Conditional guidance flags mixed post types, multiple taxonomy clauses and result counts, without rewriting queries or assuming an index problem.
2. **Scoped accessor observations.** On signed opt-in deep requests only, the callback profiler marks the beginning and end of the two Bricks initialization callbacks. Read-only `get_post_metadata`, `get_term_metadata`, `get_user_metadata`, and `pre_option` callbacks count observed invocations. All filters return their first argument unchanged; no object IDs, metadata values, option values, raw names or content are stored. Only up to 12,000 accessor events and 60 anonymous key groups are counted; excess is explicitly reported. These hooks do not represent every WordPress cache get or Redis network operation. Counts can overlap query scopes.
3. **Optional standard versus deep paired diagnostic.** A separate administrator action runs three pairs of signed and verified public-page probes in alternating order, with response code, content type and body-size comparability checks. It reports standard and deep medians plus median paired difference, with an explicit small-sample warning. Six requests are issued only on demand. It does not alter the selected main request profile or purge caches.
4. **AI export schema 1.8**, including only allowlisted accessor groups, counts, timing summaries and optional paired comparisons. Hashed keys are short site-secret HMACs. Raw Bricks IDs remain administrator-local. No raw SQL, URLs, post IDs, content or secrets.

## How to test

Back up and install over 3.1.6 (prefer staging). Ensure the MU bootstrap is **2.8.0**. Run five signed homepage probes with deep PHP + Bricks profiling and inspect the first-versus-later priority table and scoped accessor observations. Separately run the optional *Compare standard and deep PHP profiling* control and export the schema **1.8** JSON. On staging, inspect the six priority Bricks query elements one by one and verify the rendered output before considering configuration changes. Do not infer exclusive PHP time from parent minus child query timing or Redis latency from cache API counters.

## Constraints

Only local PHP syntax/mock tests and ZIP checks were run. This package has not been deployed or tested on your production site. WordPress accessor hooks impose measurement overhead on deep probes, and cache state may differ across paired requests.
