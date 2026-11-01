# PART 08 — Daily Backup Scheduler

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
Implement reliable configurable daily backups and retention while explaining WP-Cron timing constraints.

## Build only this part
- Add Schedule page with enable toggle, site-timezone start time, cadence Daily/Weekly/Manual, retention count, storage warning and summary of next scheduled run.
- Implement WP-Cron scheduling; prevent duplicate scheduled events on activation/settings change; unschedule on deactivation. If reliable exact-time operation is desired, document optional server cron trigger without requiring cPanel access.
- Make scheduled jobs use the same resumable engine and manifest validation as manual backups. Prevent overlapping jobs and add failure retry policy with capped backoff.
- Retention: remove only eligible completed backups, never a job's live staging files or a recovery snapshot still in use, and never an incremental base that remains referenced.
- Send WordPress admin notifications for repeated failures and low free space; avoid excessive emails.
- Add tests for timezone/DST, delayed cron execution, retries and changed settings.

## Acceptance criteria
- Daily backups are scheduled and eventually run when WP-Cron executes.
- UI distinguishes planned time from actual last execution.
- Retention does not remove protected backups.
- Uninstall/deactivation behavior is safe and documented.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
