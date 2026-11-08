# Performance Console 3.1.1: SQL Call-Site Inspector

This release adds source-code attribution to the existing opt-in signed request profiler. No automatic SQL rewrites, Redis adjustments, plugin disabling, query caching, or database changes are performed by the inspector.

## What the inspector measures

In **Performance → Cache & Redis → Real-request cache workload**, run a signed public-page profile. The new **SQL call-site inspector** shows:

- The same 12-character SQL fingerprint used in the AI exports, with its component (plugin, theme or MU plugin).
- The relative PHP source file and line that invoked the database API.
- The nearest calling PHP method/function found in the execution stack.
- The active parent WordPress hook where available.
- Calls and combined SQL execution time, with multiple distinct call sites shown separately.
- Explicit unattributed / truncated counts, rather than guessed locations.

SQL call-site timings represent database execution only. They are **not** inclusive PHP method execution time and do not measure Redis operation time. Some queries may be attributed to core/unknown if a suitable plugin/theme frame is absent. Deep profiling itself adds overhead.

## Export

Schema version **1.2** adds `sql_callsite_inspector` to each verified run. The exported field includes at most 20 query patterns and three call sites each, using only code identifiers and **relative source paths**, such as `plugins/keystone-seo/includes/Example.php`. Raw SQL, literal values, complete backtraces, request URLs, SQL parameters and absolute filesystem paths are excluded. Review exports before external sharing.

The local administrator panel may show more call sites than the AI export. All data is gathered during the already opt-in signed trace. Sampling of ordinary visitor traffic is not expanded. The MU bootstrap version is updated to **2.3.0** to capture parent WordPress hook context.

## Upgrade and validation

1. Back up the installed Performance Console plugin and database as appropriate.
2. Upload the installable ZIP over 3.1.0.
3. Visit **Performance → Cache & Redis**; confirm the MU bootstrap status is current, or repair it if WordPress cannot write to `wp-content/mu-plugins`.
4. Profile the homepage using 3 to 5 signed requests. Open **SQL call-site inspector**, inspect Keystone SEO, CGM Image Renamer, WPCodeBox and Bricks fingerprints.
5. Export the JSON and verify `source.version` is `3.1.1` and `schema_version` is `1.2`.
6. Compare the call sites across runs before editing plugins. Test query changes on staging and reprofile.

If the main profiler works but hook names are unavailable, confirm the MU bootstrap has upgraded to 2.3.0. The inspector does not add information retroactively to previous exports.

## Validation performed

- PHP syntax lint on all bundled PHP files (using PHP 8.4.24 CLI).
- Standalone callsite, multi-caller, bounding, path/redaction and full AI export contract tests.
- Build ZIP integrity, expected plugin version, MU bootstrap version and required file presence checks.

Live WordPress 7.1.4 / PHP 8.5.12 integration testing was **not** available. The updated plugin should be validated on a representative staging copy before production deployment.
