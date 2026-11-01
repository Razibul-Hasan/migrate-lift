# PART 04 — Job Engine Activity

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
Create an extensible, resumable background-job foundation used by backup, import, restore, and migration.

## Build only this part
- Introduce job records with ID, kind, owner, state, percentage (if calculable), phase, counts, safe diagnostic detail, timestamps, retry metadata, checkpoint location, and idempotency key.
- Define states queued, running, paused, cancel-requested, failed, completed and transitions, with proper locks/lease expiration and recovery after process restarts.
- Process bounded batches per request/cron tick and checkpoint progress atomically; do not hold a 1-hour HTTP request open or depend on client keeping browser open.
- Implement safe access-controlled REST `GET /jobs`, `GET /jobs/{id}` and controlled start/stop interfaces where appropriate; do not enable actual destructive jobs yet.
- Persist bounded logs without credentials, raw SQL, tokens or absolute sensitive server paths. Add an Activity page with searchable/filterable jobs/events and clear empty states.
- Ensure retries are idempotent and do not accidentally duplicate extraction/SQL writes.
- Provide automated tests for concurrency, interrupted runs and authorization.

## Acceptance criteria
- A demonstration non-destructive test job can resume after an interrupted request.
- Two workers cannot process the same claimed chunk simultaneously.
- Job records and activity events are permission protected; no secret leakage.
- Activity page is integrated with the real job API rather than invented records.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
