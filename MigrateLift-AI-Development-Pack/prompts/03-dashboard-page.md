# PART 03 — Dashboard Page

**Copy-paste implementation prompt for your coding AI.** Attach your current MigrateLift repository or open it in the coding agent workspace.

# Shared MigrateLift project context
- Product: **MigrateLift – WordPress Migration, Backup & Restore**.
- Directory and slug: `migrate-lift`; main PHP entry: `migrate-lift.php`; i18n text domain: `migrate-lift`.
- Prefix PHP functions/options/hooks with `migrate_lift_`; classes `Migrate_Lift_*`; constants `MIGRATE_LIFT_*`; CSS classes `migrate-lift-*`; REST namespace `migrate-lift/v1`.
- Architecture: WordPress PHP backend; React + TypeScript admin built with `@wordpress/scripts`, supported public WordPress packages (`@wordpress/components`, `@wordpress/icons`, `@wordpress/api-fetch`, `@wordpress/i18n`), and scoped CSS. Prefer stable APIs; verify installed version's components and build dependencies.
- UX direction: premium lightweight SaaS, calm off-white canvas, charcoal text, restrained teal accents, accessible and responsive. Respect WordPress admin chrome, localizable labels and keyboard access.
- Safety non-negotiables: only authorized administrators may operate backups and migration; enforce `current_user_can( 'manage_options' )` (or a deliberate custom capability), nonce for cookie-authenticated REST, input validation, output escaping, and per-route `permission_callback`. Authentication and authorization are separate; nonces alone do not grant permission. Never expose secrets or backup files via public URLs.
- **Destructive operations are disabled by default** until a verified recovery checkpoint exists, preflight passes, and the administrator explicitly confirms the exact target site and mode. Never delete arbitrary hosting folders or unrelated database tables; never rewrite `wp-config.php` automatically. Tests must use disposable local/staging sites, not production data.
- Work only on this part. Inspect the existing repository first, preserve previous functionality, and do not invent completed endpoints or sample success results. Where a future part is missing, show a clearly labeled disabled/empty state.
- Use WordPress coding conventions, WP_Error, idempotent jobs, accessibility (WCAG-minded), proper escaping/sanitizing, and robust error paths. Support shared hosting without assuming shell access or custom server extensions.

## Objective
Build the **complete premium MigrateLift dashboard page** using the app shell and reusable design system. This is the landing page immediately after opening the plugin.

## Build only this part
**Visual hierarchy (top to bottom)**
1. Welcome header: "Website protection, made simple" plus current site URL, a subtle 'Site ready' / 'Action needed' status derived from API; top actions `Create Backup` and `Migrate Site` (disabled or showing setup guidance until real endpoints exist).
2. Four compact KPI cards: Last Successful Backup, Next Scheduled Backup, Stored Backups, Total Storage Used. Each has a simple icon, dominant value, helper detail, and clear `—` empty fallback.
3. Two-column hero row: left 'Backup health' card with actual seven-day activity visualization or a thoughtful empty state; right 'Quick actions' with Backup Now, Import Backup, Start Migration, Schedule Backup, each with purpose and correct disabled state.
4. 'Recent backups' accessible table with date, type, verified status, file size, and available contextual actions; empty state with an explanatory CTA.
5. 'Migration status' card with source-to-destination pathway and real job state: Not started / Preflight / Transferring / Verifying / Completed / Failed. Only show progress based on an actual job payload.
6. 'Your protection settings' mini panel: schedule enabled/disabled, retention, compression mode, exclusions, last integrity check.
7. 'Recent activity' timeline with timestamp, event, level, and job link; empty state if no records.
**Data contracts**
- Define TypeScript types for dashboard summary, backup list, schedule summary, migration job summary, and event log.
- Implement an adapter for GET `/dashboard/summary`; if the backend is not ready, display intentional empty states, not fabricated data. A developer-only preview fixture may exist but must be off in production.
- All values must be escaped by default via React; format timestamps in site timezone and sizes with consistent IEC/SI labeling.
- Add skeleton loading, partial failures, retry button, and a graceful disconnected API state.
**Micro-interactions**
- Subtle hover feedback, status changes, tooltips, action feedback, responsive card rearrangement; no intrusive animations or fake counters.
- Confirm before any future destructive action. Keep icons restrained and provide text labels.

## Acceptance criteria
- A premium dashboard renders at desktop, tablet, mobile and works with keyboard.
- Exact empty/loading/error states are visually designed.
- Every visible action either routes correctly, starts a real supported action, or is clearly disabled; no dummy success toast.
- TypeScript interfaces document data shape and test cases cover missing/partial API responses.

## Additional constraints
Do not implement backups or migration engine in this part. If necessary, add the read-only `/dashboard/summary` route returning real available information and explicit `null` for features not built. The supplied `preview/dashboard-preview.html` is visual inspiration, NOT proof that real data exists.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
