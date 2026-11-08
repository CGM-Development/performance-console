# Optimization suite (3.2.0)

Performance Console up to 3.1.x answered *what is slow*. This release adds the
other half: **what to change, whether the change worked, and how to undo it**.

Seven capabilities are described below. Each one is deliberately conservative:
it reports what it can prove, marks what it cannot, and never presents a
heuristic as a measurement.

---

## 1. Change-impact ledger — `PFC_Change_Impact`

Every change the plugin performs, plus the plugin/theme updates WordPress already
reported, is written to `pfc_changes`. This class pairs a change with the traffic
before and after it.

**How it decides.** A change becomes *assessable* only once the settled window
has a full day of traffic. The comparison uses the median, and the noise floor is
`max(metric floor, 2 × MAD of the before window)`. A shift smaller than the
noise floor is reported as `no_measurable_change`, not as a win. Verdicts are
`improved`, `worse`, `no_measurable_change`, `insufficient`, `pending`.

**Route-group comparison.** Whole-site p75 across a change mixes populations: a
release that triples mobile traffic can "regress" LCP with nothing slower. The
ledger takes one p75 per route group and compares the medians of those groups.
If the traffic mix drifts more than 25 percentage points, confidence falls and
the row says so.

**Limits.** Timing does not prove causation. A regression outranks an
improvement, because a change that made one metric worse needs review even if
another improved.

`wp performance changes [--limit=<n>] [--json]`

---

## 2. Field attribution — `PFC_Attribution` + `assets/js/rum.js`

The RUM client now sends a bounded attribution sidecar derived from the
`web-vitals` entries:

| Field | Meaning |
| --- | --- |
| `lcp_tag` | Tag name of the LCP element, whitelist only, else `OTHER` |
| `lcp_bytes` | LCP resource size, rounded to the nearest KB |
| `lcp_same_origin` | Whether the LCP resource was first-party |
| `inp_tag` | Tag name of the element that received the INP interaction |
| `long_task_count` / `_max` / `_total` | Long-task observations |

**Privacy.** Entries, URLs, selectors, class names, text content and the raw
`entries` array never leave the browser. Unknown tag names collapse to `OTHER`.
The server rejects anything outside its own whitelist and clamps every number.

**Limits.** Percentages describe *attributed* visits, not all traffic. Long tasks
are only observed where `PerformanceObserver` supports `longtask`.

`wp performance attribution [--days=<n>] [--json]`

---

## 3. Asset and priority-hint ledger — `PFC_Asset_Ledger`

Fetches the HTML a visitor receives through a signed diagnostic request and
prices it: render-blocking stylesheets, blocking head scripts, the LCP
candidate's `loading`/`fetchpriority`, unprepared third-party origins, images
without reserved dimensions, missing `srcset`, font origins without `preconnect`,
and inline CSS/JS weight.

Every finding carries a heuristic estimate **and** the exact count or bytes it
was based on. The estimate orders the list; the evidence is what you act on.
Nothing here rewrites HTML or enqueues anything.

`wp performance assets <url> [--json]`

---

## 4. Guarded remediation

All four actions follow the Repair Centre pattern: validate → preflight →
gate → execute → verify → record. Each is reversible from the Optimize screen or
from the recorded `change_id`.

### Index creation — `PFC_Index_Repair`

Carries the profiler's review-only candidate through: identifier validation,
`SHOW COLUMNS`/`SHOW INDEX`/`SHOW TABLE STATUS`, duplicate-prefix detection,
a 2 GB size gate, a required backup confirmation, `ALGORITHM=INPLACE, LOCK=NONE`
on InnoDB, and a post-ALTER `SHOW INDEX` verification.

It creates only non-unique indexes, only on InnoDB by default, and undo drops
only an index whose name carries the `pfc_opt_` prefix it generated.

**Never-used indexes** are read from
`performance_schema.table_io_waits_summary_by_index_usage`. This is evidence
only: the view never drops an index, because a drop can change a query plan or
break a foreign key. Server uptime under seven days is called out as weak
evidence.

`wp performance indexes`
`wp performance index-create <table> <columns> --backup-confirmed [--force-large] [--force-engine]`
`wp performance index-undo <change-id>`

### Runtime levers — `PFC_Runtime_Levers`

Off by default. When enabled:

- **Heartbeat**: sets `heartbeat_settings['interval']` and deregisters the
  heartbeat script for logged-out visitors, who have no editor to keep in sync.
- **Revisions**: caps `wp_revisions_to_keep`, never raising an existing lower cap.

Both are recorded before and after, and revert through the same change record.
The state option is deliberately small and autoloaded so registration costs no
query on an ordinary request.

`wp performance levers [--heartbeat=<seconds>|--no-heartbeat] [--revisions=<n>|--no-revisions] --confirm`

### WP-Cron duplicates — `PFC_Cron_Repair`

Separates two different problems: the same hook registered several times, and a
hook rescheduled faster than five minutes. De-duplication keeps the earliest
occurrence of each distinct argument set and removes the rest, recording exactly
what it removed. Single events are never touched, and the plugin's own
maintenance hook is protected.

`wp performance cron`
`wp performance cron-dedupe <hook> --confirm`

### Autoload budget — `PFC_Autoload_Budget`

Measures the autoloaded option footprint (count and bytes) against a 1 MB /
400-option budget, ranks offenders with whatever access evidence the sample
ledger holds, and can turn autoload off for an explicit list.

An option with no observed reads is reported as **"no evidence"**, never as
"unused": the sample ledger is a sample. Protected options are refused. Every
batch records the previous autoload value for each option and can be reverted.

`wp performance autoload [--limit=<n>]`
`wp performance autoload-off option_one,option_two --confirm`

---

## 5. CI budget gate — `PFC_Budget`

Budgets over TTFB p75, LCP p75, INP p75, CLS p75, server PHP median and query
p75. The evaluator is pure, so the CLI gate and the admin panel cannot disagree.

Statuses are `pass`, `fail`, `no_data`, `disabled`. A budget set to `0` is
disabled and never fails. By default a budget with no traffic fails, because a
deploy gate should not pass on absent evidence; `--allow-missing` relaxes that.

```bash
wp performance budget --days=1 --lcp=2500 --ttfb=800 --inp=200 --cls=0.1
wp performance budget --route-group=singular:post --allow-missing
```

Exit code is non-zero on a breach, so it drops straight into CI.

---

## 6. Bootstrap waterfall — `PFC_Bootstrap_Timeline`

Two honest halves:

1. **Measured**: the profiler's phase deltas between MU bootstrap and
   `wp_footer`, ordered into a waterfall with shares.
2. **Static**: the per-plugin/theme include footprint (file count and bytes),
   recorded on deep requests only.

There is deliberately **no per-plugin duration**. PHP has no hook that reports
how long another plugin's `require` took, and an include wrapper cannot observe
code that is not ours. The two halves are never added together.

`wp performance boot [--json]`

---

## 7. Cacheability advisor — `PFC_Cacheability`

Separates "repeats and costs time" from "repeats, costs time, and is
deterministic". Reads are rejected when they use time-, session- or
server-dependent functions, row locks, `SQL_CALC_FOUND_ROWS`, user- session- or
queue-scoped tables, or a CTE that might be recursive.

For the rest it estimates savings as `(calls − runs) × average`, which assumes
one execution per request is unavoidable and every repeat is removable — an
upper bound, not a promise. A pattern repeated inside one request is
recommended a request-scoped cache; one repeated across requests needs an
expiring store with explicit invalidation.

`wp performance cacheability [--days=<n>] [--limit=<n>]`

---

## Where this appears

- **Optimize** view in wp-admin: budgets, change impact, attribution, asset
  audit, cacheability, waterfall, and the guarded fixes with undo.
- WP-CLI: the commands above.
- Data: `pfc_attribution` is new; the schema version is **3.1.0**.

## Safety summary

- Nothing here flushes a cache, edits Redis ACLs, changes `wp-config.php` or
  writes a drop-in.
- DDL requires a verified-backup confirmation and is verified afterwards.
- Autoload, cron and lever changes are recorded with their previous state and
  can be reverted.
- The asset ledger only fetches pages; it never modifies them.
