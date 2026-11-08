# Performance Console 3.1.4: Bricks Query & Render Inspector

## Scope

This update is a **diagnostic instrument**, not an automatic optimizer or a modification to the Bricks theme. It addresses the November 8 profiling findings: Bricks' `wp_enqueue_scripts` assets callback and `wp` elements callback were expensive, with recurring first-request SQL spikes, and approximately 11,099 WordPress cache API lookups during their two measured scopes.

### New instrumentation

- **Bricks-origin `WP_Query` tracking**: on an explicitly signed, opt-in deep PHP callback profile only, observes the WordPress `pre_get_posts` and `the_posts` boundaries. An argument-free 35-frame stack identifies queries originating in the Bricks namespace or theme. Queries outside Bricks are not stored.
- **Privacy-safe query shape grouping**: groups calls using a site-secret HMAC of safe metadata (post type, pagination size, meta/tax clause counts, search/post-in presence and main-query status). These groups **are not unique query identities**. Optional Bricks query/element IDs are HMAC pseudonyms, never plain IDs. If unavailable, the inspector reports that explicitly.
- **Query elapsed/SQL/cache evidence**: reports bounded wall time, saved SQL query count and cumulative SQL time for the interval where SAVEQUERIES is available, result count and WordPress cache hit/miss deltas. Elapsed and SQL intervals may overlap for nested queries. They are **not exclusive function time**, and cache lookup deltas are not Redis commands.
- **Bricks render hook observations**: observes `bricks/frontend/render_element`, `bricks/element/render` and `bricks/frontend/render_data` *if emitted by this Bricks installation*. Reports only elapsed time across handlers inside the hook, an optional HMAC element marker, safe element type and counter deltas. Does not claim to time all element rendering. Does not modify returned filter values.
- **Bounded capture**: at most 100 Bricks query start samples, 200 Bricks render-hook samples, 50 render groups and 30 saved rows per section. Reports skipped/incomplete measurement counts and lower-bound bookkeeping time.
- **Callback coverage**: distributes the existing callback profiler's 240-callback budget across hooks with a maximum of 65 callbacks per hook. Skips by-reference callbacks. Existing callback profiler remains opt-in.
- **AI export schema 1.5**: strictly sanitizes Bricks query and render data, rejecting raw/absolute paths, arbitrary text, post content, queries, un-HMAC'd identifiers and invalid source/function fields.
- **MU bootstrap 2.5.0**: version bump to keep deployed bootstrap matched to plugin version; signed callback-mode signature remains unchanged.

## Deployment

1. Make a database and plugin backup, preferably install on staging first.
2. Install `performance-console-3.1.4-bricks-inspector-installable.zip` using **Plugins → Add New Plugin → Upload** and replace the existing plugin.
3. Verify **Performance → Cache & Redis** shows an up-to-date MU diagnostic bootstrap (2.5.0).
4. Run a normal 3–5-request verified homepage profile, followed by a separate 3–5-request profile with **Deep PHP + Bricks query/render profiling** enabled. The latter adds overhead by design.
5. Expand **Bricks Query and Render Inspector** in the results and export the AI JSON (schema 1.5).
6. Compare multiple sessions and **do not** clear the production cache simply to force a cold request.

## Interpretation and limitations

- The inspector reads hooks supported by the WordPress core, and observes optional Bricks hooks. If this Bricks version doesn't emit the observed hooks, render evidence will be empty, not a zero-cost conclusion.
- Query grouping combines similar shapes and may combine distinct queries. Do not infer exact template/element identity from a group without a marker.
- Query timing may overlap with SQL timing, Bricks callback time, or nested queries. Do not add these totals.
- The callback instrumentation wrapper is opt-in and may affect performance. Only measured bookkeeping is estimated, not total overhead.
- This plugin does **not** patch Bricks or intercept the Redis drop-in. Neither SQL text nor query values are exported.

## Local validation

PHP syntax lint, signed-probe integrity, original PHP callback tests, deterministic Bricks query/render tests, export privacy tests and ZIP integrity checks. Live WordPress and Bricks integration still need verification.
