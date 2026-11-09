# Performance Console 3.2.1: Admin UX and accessibility refinement

## Installation

Upload the `performance-console` directory as a normal WordPress plugin. This is an in-place patch update from 3.2.0. The database schema and MU bootstrap are unchanged, and existing settings, diagnostic history, and backups are preserved.

## What changed

### Clearer, more consistent workflows

- Refined Overview, Incidents, Database, Profiling, Monitoring, System, Optimize, and Cache & Redis.
- Added concise incident evidence disclosures, local navigation, clearer empty states, and production-safe scan guidance.
- Reorganized Optimize into Budgets & impact, Page & query diagnostics, and Guarded fixes.
- Corrected remediation copy to distinguish reversible actions from changes that are recorded only.
- Moved advanced Cache request, callback, HTTP, and Object Cache Pro tools into a disclosure while keeping deep links functional.

### Mobile and responsive behavior

- Removed horizontal document overflow across all eight views at 390 px.
- Added two-column mobile KPI cards, single-column Optimize forms, responsive Cache status layouts, and table-overflow hints.
- Active section tabs now scroll into view on initial load and after desktop-to-mobile resizing.
- Responsive Monitoring tables retain their accessible labels without expanding the page.
- Overview priority-impact text wraps instead of widening small screens.

### Accessibility and interaction

- Increased functional text sizes and mobile form-input font sizes.
- Improved visible focus states, selection styling, touch targets, and supporting-text contrast.
- Added specific accessible names for backup Download/Delete/Resume/Verify actions and eligible Undo controls.
- Added four-per-page incident pagination with working Previous/Next controls, severity filtering, debounced search, match counts, and a no-results state.
- Incident management now expands inline instead of clipping outside the card on desktop.
- Database destructive actions are visually separated, with a stacked mobile confirmation and a larger checkbox target.

### Maintenance

- Removed the optional-parameter-before-required-parameter PHP deprecation in Optimize restart forms.
- Preserved the intentional Object Cache Pro side-tab accent identified by the design detector.

## Verification

- All eight views were exercised in the live WordPress admin at 1200 × 900 and 390 × 844.
- Incident pagination, filtering, search, no-results, and management interactions were exercised.
- Cache deep links opened the advanced disclosure with unique section anchors.
- Measured supporting-text contrast passed WCAG AA; the lowest sampled ratio was 6.56:1.
- PHP lint passed for the modified PHP files and the JavaScript syntax check passed.
- Embedded screenshot capture timed out, so final browser verification used live DOM inspection, computed layouts, and interactions rather than screenshot review.
- No repairs, cleanup, scans, or other state-changing diagnostics were executed during UI verification.
