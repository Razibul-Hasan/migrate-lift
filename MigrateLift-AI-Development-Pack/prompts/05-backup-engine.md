# PART 05 — Backup Engine

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
Build the first real end-to-end full-backup engine using the job framework. Backups must be verifiable, portable and safe to store.

## Build only this part
- Define backup format version, archive manifest schema, source environment info (excluding secrets), component inventory, file checksums, DB dump sections, and created-at timestamp.
- Capture WordPress site files within allowlisted root; exclude current backup folder, temp/cache paths, inaccessible files as explicit warnings; do not follow symlinks outside root.
- Export selected database tables in bounded pages/chunks with reliable encoding and consistent snapshot strategy; document consistency limitations under concurrent writes.
- Write to a private staging directory, preferably outside document root, with web-server protection and random non-guessable names; then atomically finalize and verify integrity.
- Never include plaintext credentials, active sessions, API secrets, or arbitrary webroot neighbors in the backup. Be explicit about `wp-config.php`: document safe restoration setup rather than silently packaging production credentials.
- Implement REST to start backup job and query status; frontend button should become real only after validated endpoint exists.
- Return meaningful errors for disk-space, filesystem permission and hosting limitations; clean interrupted temporary files safely.
- Add integration tests on disposable WordPress install using test fixtures.

## Acceptance criteria
- Real backup contains expected allowlisted files and database data and passes manifest verification.
- Backup file is not accessible anonymously via a direct URL.
- Memory and execution bounds remain reasonable for a configurable large fixture.
- Failed backup does not appear as successful; logs explain why.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
