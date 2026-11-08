# Performance Console 3.2.0: Optimization suite

## Installation

Upload the `performance-console` directory as a normal WordPress plugin. This is
an in-place update from 3.1.12. The MU bootstrap stays at **2.8.0**, because no
early bootstrap logic changed. The database schema moves to **3.1.0** to add the
`pfc_attribution` table; the upgrade runs automatically on the first request.

Nothing in this release is enabled by default. The Runtime Levers are off until
an administrator turns them on, and every other capability is read-only until an
explicit action is confirmed.

## What this release adds

This is the first release that closes the loop from finding to fix to proof.

**Change-impact ledger.** Every recorded change is compared against the traffic
before and after it. A verdict needs a full settled window and a shift beyond the
window's own spread (a MAD-derived noise floor), and the comparison runs per
route group so a change in traffic mix cannot masquerade as a performance
change. A regression outranks an improvement: if one metric got worse, the change
needs review even when another improved.

**Field attribution.** The RUM client now reports which element carried LCP, how
large its resource was and whether it was first-party, which element received the
INP interaction, and long-task counts. Only a whitelist of derived scalars leaves
the browser: no entries, URLs, selectors, class names or text. Unknown tags
collapse to `OTHER`.

**Asset and priority-hint ledger.** Audits the HTML a visitor receives for
render-blocking CSS and head scripts, the LCP candidate's `loading` and
`fetchpriority`, unprepared third-party origins, images without reserved space,
and inline weight. Each finding pairs a heuristic estimate with the exact count
or bytes it was based on.

**Guarded remediation beyond the database.** Index creation from the profiler's
existing candidates, with identifier validation, a size gate, a required backup
confirmation, online DDL and post-ALTER verification. Never-used indexes are
reported from `performance_schema` as evidence only — the tool never drops one.
Opt-in Heartbeat throttling and a revision cap are added as reversible levers.
WP-Cron duplicates can be collapsed while keeping the earliest occurrence of
each argument set, and the autoload footprint can be measured against a budget
and reduced in a recorded, revertible batch.

**Budgets and a CI gate.** TTFB, LCP, INP, CLS, server PHP median and query p75
against configurable limits, with a non-zero exit code on breach:
`wp performance budget --lcp=2500 --ttfb=800`.

**Bootstrap waterfall.** Measured phase deltas plus a static per-plugin include
footprint. It does not claim per-plugin duration, because PHP cannot observe
another plugin's `require` time.

**Cacheability advisor.** Repeated reads split into deterministic (safe to
cache) and everything else — time-, lock-, session- or user-dependent, unbounded
CTE. Savings are an upper bound, not a promise.

## How to test

1. Back up the plugin and database, then update, preferably on staging first.
2. Open **Performance → Optimize**. Confirm the budget table renders and the
   change-impact panel says no changes have been recorded yet.
3. Run a deep signed profile from **Cache & Redis**, then return to **Optimize**
   and confirm the bootstrap waterfall and index candidates appear.
4. Run `wp performance budget --days=7 --allow-missing` and confirm it prints the
   observed values and exits 0 on a quiet site.
5. Browse the site for a few minutes, then check `wp performance attribution` for
   LCP element shares.
6. Only then try a guarded fix on staging, for example creating an index
   candidate, and confirm the change appears in the ledger with an Undo button.

## Limitations

- Impact verdicts need traffic. A quiet site will report `insufficient` rather
  than invent a result.
- Attribution describes sampled visits only, and long tasks require
  `PerformanceObserver` support.
- The asset ledger's millisecond estimates order a list; they are not
  measurements. The bytes and counts beside them are.
- Index creation is InnoDB-first. Other engines require an explicit override
  because the ALTER would lock writes.
- Budgets evaluate what has been recorded: if profiling or RUM is disabled, the
  gate reports no data rather than passing silently.

## Compatibility

WordPress 6.4+, PHP 7.4+. No drop-in, `wp-config.php` or server configuration is
written. Existing data, settings, diagnostic history and backups are preserved.
