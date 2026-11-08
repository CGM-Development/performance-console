# Performance Console 3.1.5: Bricks Element Correlation and Query Lifecycle

## Why

The previous five-request profile recorded 33 Bricks-origin WP_Query calls, 10 apparently unfinished query scopes, 0 exact element identifiers and lost render-hook coverage beyond the first 200 invocations. The most expensive callback paths were `themes/bricks/includes/assets.php:457` and `themes/bricks/includes/elements.php:289`. These findings are about observed diagnostic calls, not confirmed defects in Bricks.

## Changes

* Official `bricks/query/result` (2 arguments) associates the returned **same WP_Query object** with `$query_obj->element_id`. The object identity test is strict and query vars / query results are never modified. Bricks `bricks/posts/query_vars` records an independent short list of element IDs visible only to administrators; this list is not automatically claimed to match WP_Query objects.
* Query spans still close at `the_posts` when available. If it did not fire, the exact result-object hook can close the span, labelled `bricks_result` (upper-bound duration). Otherwise it is counted as **no completion hook observed**, not as a failed query. Counts of all paths are supplied.
* Per-query attribution stores a local-only short Bricks element ID, HMAC group, bounded query shape, and `assets` / `elements` / `other-bricks` calling path. Parent callback query elapsed/SQL counters appear beside the existing callback timer, with overlap warnings.
* Render-hook maximum increased from 200 to 700 invocations; after 90 distinct element groups, further invocations roll up into a per-hook aggregate rather than being discarded.
* AI export schema 1.6 contains **only** HMAC identifiers and bounded numeric fields; it strips all raw element IDs, SQL, query contents, absolute paths, URLs and post data.
* Profiling remains explicitly opt-in through signed deep callback probes. No changes to Bricks, Redis or actual query behaviour.

## Limits

* `bricks/query/result` can be absent, return other result types, or be bypassed by Bricks. Those queries remain unattributed. Bricks reuses query results across same-page loops; no code assumes a one-to-one relationship between element and physical database query.
* WP_Query filter `the_posts` is not guaranteed to fire (for example, suppressed filters or short-circuits). A missing completion observation is not a failed request.
* Timing on the Bricks result fallback includes a potentially larger interval than `the_posts` timing. Report methods separately.
* Query intervals can nest and overlap; do not add callback PHP, query elapsed and SQL time to determine total request time.
* Render-hook timings cover only work inside those hooks, not the full execution of every Bricks element.
* The administrator-local diagnostic payload can contain a short (nonsecret) Bricks element ID. It is never included in the AI export; restrict database backup access appropriately.

## Install and validation

Install the whole ZIP over 3.1.4, preferably on staging first. Verify the MU bootstrap version is 2.6.0. Run 5 signed homepage requests with callback profiling. Compare with a standard signed run, and review **Bricks Query and Render Inspector**. Test element IDs shown under `Bricks ID: brxe-...` in Bricks Builder. Check `query_completion` and `exact_element_attributions` in AI JSON schema 1.6. Use an ordinary homepage load to verify layout is unchanged.

## Local regression coverage

Seven isolated PHP tests cover: query start/end; SQL and cache counters; exact Bricks result-object correlation; the_posts bypass/fallback; repeated use of one WP_Query object; query-vars return-value preservation; bounded 650-hook render observations; existing callback profiling; signed-probe authorization; AI export privacy. The tests use mocks rather than a full WordPress/Bricks runtime, and do not establish live compatibility or production performance gains.
