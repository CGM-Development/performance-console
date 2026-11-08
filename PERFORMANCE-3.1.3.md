# Performance Console 3.1.3: PHP callback scopes and HTTP collector verification

## Purpose

This version investigates the remaining 2.1–2.2 seconds of signed homepage PHP time after recurring Keystone SEO/Image Renamer schema maintenance and large SQL spikes were addressed. It extends **measurements**, not third-party code changes.

## Features

### Opt-in frontend PHP Hook & Callback Inspector

- In **Performance → Cache & Redis**, select **Deep PHP callback profiling (extra overhead)** when profiling 3–5 signed page requests.
- Only selected WordPress hooks are instrumented: `wp`, `template_redirect`, `wp_enqueue_scripts`, `wp_head`, `wp_footer`, `the_content`, `pre_get_posts`, and five Bricks-oriented hooks where present.
- Records callback identifier, owning component, safe path relative to `wp-content`, line number, executions, inclusive time, and time with other instrumented nested callbacks subtracted.
- Measures public WordPress object-cache GET counter changes within the callback scope when exposed by the cache drop-in. This is **not** an exact `wp_cache_get()` caller trace, Redis command count, or Redis network timing. Uninstrumented nested work can contribute to the scope.
- Refrains from wrapping callbacks with by-reference parameters or reference returns, and reports skipped callbacks.
- Limits the number of wrapped callbacks and exported rows. Captured arguments, return values, post contents, raw SQL, URL targets, database values, credentials, and absolute filesystem paths are excluded.
- Reports instrumentation bookkeeping as a **lower bound**, not as the total overhead. Compare with a separate standard signed profile; two measurements are not a controlled proof of a performance effect.
- Shows a separate **Bricks callback evidence** total from observed Bricks callbacks. This is partial hook coverage, not complete Bricks render time.

### HTTP collector self-test

- New nonce-protected administrator action issues exactly one bounded, same-site REST-index loopback via the WordPress HTTP API.
- Reports whether the existing HTTP API instrumentation captured the operation, plus status category and duration. It does not store or export the destination URL, response body, or secrets.
- A successful test demonstrates that the capture hooks work for the test operation. It does not prove that AAWP or any other plugin made a request in the homepage samples.

### Diagnostics transport

- Signed probe parameters bind the opt-in callback mode into their HMAC. Editing a standard probe URL cannot enable callback wrapping.
- MU bootstrap version **2.4.0**; the new version must be installed for the callback flag to be recognized.
- AI export schema **1.4**, with strictly allowlisted callback and cache capability fields. Exports without the opt-in capture report `enabled: false`.

## Operational safety

This tool is only for development/staging or brief, controlled production diagnostics. It modifies selected callback entries in memory **during the single diagnostic request**. It does not persist callback wrappers or change plugin/theme files. Callback wrapping can affect uncommon plugin behaviour despite by-reference guards, so compare outputs and switch the extra profiler off when not diagnosing. Default signed profiles and unsampled production traffic do not activate it.

No generic WordPress API supports reliable per-object-cache-call attribution. A future cache drop-in integration could enable that only with provider-specific support.

## Validation performed

- PHP syntax check across all packaged PHP files.
- Isolated simulated WordPress hook tests for filter outputs, reference guard, counter scope and timing.
- HMAC test for standard vs callback probe mode, including failed signature on tampering.
- AI export allowlist checks rejecting raw absolute paths and malformed callable labels.
- Archive structure and ZIP integrity checks.

**Not performed:** full live WordPress/Bricks/Redis integration testing, public-page functional comparison, or benchmarking the actual profiler overhead on the user's server.

## How to use

1. Back up Performance Console and install 3.1.3 over 3.1.2.
2. Open Performance Console's **Overview** and verify/repair the diagnostic MU bootstrap to **2.4.0**, if needed. A read-only MU directory requires a manual update.
3. Open **Cache & Redis** and run a standard signed profile of three pages.
4. Run the exact same route again with **Deep PHP callback profiling** selected. Use three or five requests, acknowledging added overhead.
5. Review the timed callback table, Bricks callback evidence and counter capability note.
6. Run **Verify HTTP collector (local loopback)** if the homepage continues to report no outbound HTTP calls.
7. Export the 1.4 AI JSON file for further analysis. Treat first-request SQL and zero-HTTP results as observational unless verified independently.
