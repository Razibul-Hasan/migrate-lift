# PART 14 — Direct Transfer Engine

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
Build fault-tolerant direct server-to-server archive transfer on top of pairing and the job system.

## Build only this part
- Agree on transfer manifest and file parts; transfer bounded chunks over authenticated HTTPS without requiring the user to download/reupload. Start with ~1–4 MiB configurable chunks, adapt on hosting limits.
- Support resuming from verified chunk offsets/IDs; use per-part checksums and overall archive verification; detect duplicates and ensure idempotent writes.
- Implement retries with capped exponential backoff, lock ownership, cancellation, throttling, cleanup and timeouts. Capture safe and actionable diagnostics.
- Build `Transfer` job stage with source/destination sizes and actual bytes transferred, percentage only when total size is known.
- Transfer must complete and verify before any restore or file/database apply can begin.
- Test network drops, slow hosting, partial writes, mismatched checksums, low disk space, concurrent requests and revoked pairing credentials.

## Acceptance criteria
- A full transfer succeeds between two staging installs without browser-mediated file upload.
- Browser closing does not lose a checkpointed transfer, provided cron/worker ticks continue.
- Interrupted transfers resume without silently corrupting data.
- Target site stays unchanged until an explicit restore stage is approved.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
