# Performance Console 3.1.10: Native Key Tracking Diagnosis

## Goal
Make Object Cache Pro **Key Tracking: Error** a first-class diagnostic, and continue investigating Bricks' repeated metadata/option accesses and large cache groups without making risky production changes.

## Native Key Tracking, priority P0

1. A one-shot administrator-triggered diagnostic reads the **WordPress Site Health Info** filter and looks for the Object Cache Pro/Redis **Key Tracking** field. It also retains the existing registered direct Site Health test if one is publicly available. No internal proprietary functions are guessed or invoked.
2. Reports an evidence status (`native_error`, `native_ok`, `native_warning`, `native_disabled_or_unavailable`, or `needs_native_health_result`), source and confidence. If native text signals a likely category such as NOPERM, NOAUTH, unsupported command, connection/timeout, memory pressure, or read-only, only that category is retained. The raw diagnostic text is never stored or exported.
3. When native information is unavailable through supported hooks, an administrator can open **Tools → Site Health → Info** and record **only** the Key Tracking status using an allowlisted dropdown in Performance Console. The entry is explicitly labelled *administrator reported*, expires in 24 hours, and never supersedes verified native evidence.
4. Object Cache Pro 1.x documents `wp redis diagnostics`; version 2.x documents `wp objectcache diagnostics`. Run the command under the site's configured CLI environment if needed, but do not paste the full output into the plugin's AI export or into public services. Some diagnostic output may include sensitive configuration.
5. `CLIENT TRACKINGINFO` concerns Redis client tracking. Its ACL denial, like denied `ACL WHOAMI`, is **not** proof of Object Cache Pro Key Tracking failure. No ACL grants or configuration edits occur.

The plugin **cannot guarantee** automatic access to Object Cache Pro's proprietary native diagnostic result. A missing result remains `needs_native_health_result`, and an error signal without the specific native command details cannot establish the root cause.

## Bricks accessor investigator

- Signed opt-in deep profiles include safe administrator-local accessor names and one sampled component-relative source hint per distinct accessor group, while keeping the previous HMAC fingerprints.
- Credential-looking names are masked. Metadata object IDs are hashed in-process; only a bounded count of distinct objects sampled (maximum 128 per group) is retained. No values or raw object IDs are stored.
- The AI JSON export continues to exclude all accessor names, local source hints, and identities. Counts are WordPress filter calls, **not** Redis operations or proof of a redundant read.

## Cache-group growth

- The opt-in Object Cache Pro native GET group inventory now stores up to five bounded, anonymized snapshots, with no cache keys or values.
- Compare **keys** and **estimated bytes** for matching groups on sequential invocations. Memory differences are available only if both inventories explicitly included memory estimates.
- The first inventory records a baseline; a later inventory reports deltas. This is not evidence of an active leak or an eviction rate.
- Memory enumeration stays separately opt-in and can be expensive on a large cache.

## Safe usage

1. Back up the plugin and install 3.1.10 on staging first.
2. In **Performance → Cache & Redis**, run **Redis, Autoload & Object Cache Pro Diagnostics**. Review the prominent Native Key Tracking panel. If the status is unavailable, inspect Object Cache Pro's **Site Health Info** and optionally record only its Key Tracking status.
3. If desired, enable cache-group inventory, followed by a **later separate inventory** to compare growth; only enable memory estimates during an off-peak period.
4. For Bricks, run a signed, opt-in **deep PHP** profile and inspect the accessor names and source hints; run a standard signed profile separately to assess instrumentation overhead.
5. Export AI JSON schema 2.1. Raw native messages, cache keys, Redis secrets, accessor names, and filesystem paths are excluded.

## Compatibility and validation

- WordPress plugin version **3.1.10**; MU diagnostic bootstrap remains **2.8.0**.
- Local tests cover PHP syntax, the native Key Tracking field parser, sanitization, history deltas, Bricks accessor return-value preservation, masked labels, and AI export privacy.
- Live WordPress/Object Cache Pro integration and native Key Tracking diagnosis still require a run on the production or staging installation. This release does **not** claim to fix the underlying Object Cache Pro Key Tracking error.
