# Performance Console 3.1.6: Targeted Bricks Review and Lifecycle Evidence

## Why

The 3.1.5 five-request homepage profile showed eight exact Bricks query-loop matches (including six costly first-request loops), 11 Bricks-origin WP_Query scopes with no observed completion hook, and a persistent roughly 200 ms cost in each of the Bricks assets/elements callbacks after SQL warm-up.

## Additions

- An administrator-only **Priority Bricks query-loop elements** table sorts the exact matched `brxe-` IDs by SQL cost, alongside bounded query configuration (post types, requested result count, taxonomy clause count), elapsed time and cache counter changes. IDs are *only* available in local wp-admin and never included in AI exports. The table does not guess template or page ownership or change builder settings.
- **Unclosed query investigation** inspects a bounded amount of safe shutdown state for queries without either completion hook, describing filters-suppressed candidates, released WP_Query objects, result state still observable and unknown state. These states do not prove that a query failed or identify a unique reason for skipped hooks. Already closed queries and their measurements are unaffected.
- **Bricks initialization comparison** correlates the independently timed assets and elements WordPress callbacks against completed WP_Query spans observed within each. The arithmetic difference is *not exclusive PHP time*, because hook instrumentation, overlapping scopes and nested/unobserved work can bias it. Both callbacks' cache GET counters are scope measurements, not Redis commands.
- AI export schema **1.7** includes only allowlisted count, grouping and timing fields. No raw builder element ID, post body, SQL, query argument contents, private filenames or URL is exported. Signed-request-only instrumentation remains opt-in, with no changes to other plugins' operations.

## Validation and next steps

Install this ZIP over 3.1.5, ideally test on staging. Confirm the MU bootstrap updated to **2.7.0** and the export reads **3.1.6** / schema **1.7**. Run five signed homepage requests with the same deep PHP + Bricks instrumentation, open the priority element table in the admin and inspect the costly IDs in Bricks. Run a normal signed profile too to estimate instrumentation overhead and see whether first-request SQL behavior repeats. Evaluate query/filter changes in Bricks one by one and verify rendered homepage content remains identical. A missing completion hook is not a failed query.

This build is validated by isolated WordPress mocks and static checks, not by a live Bricks/WordPress site.
