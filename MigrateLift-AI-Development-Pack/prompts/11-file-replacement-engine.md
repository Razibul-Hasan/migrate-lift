# PART 11 — File Replacement Engine

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
Implement safe destination file policies with staging and rollback; do not delete broad server directories.

## Build only this part
- Implement exactly three file modes: `Add missing only`, `Overwrite matching files, keep destination-only files`, and `Synchronize managed WordPress content (delete destination-only managed files after verification)`.
- Scope managed paths to expected WordPress directories from a validated manifest, with strict canonical path checks; protect `wp-config.php`, unrelated hosting files, recovery files, active plugin runtime, MU-plugins, private storage and web server configs. Never recursively wipe `public_html`.
- Build a diff preview: create/update/delete counts, total bytes, exclusions and conflicts. Require explicit confirmation for deletions.
- Stage changed files, verify checksums and apply via atomic rename where possible; checkpoint original files and rollback if any batch fails. Handle filesystem permissions and symlinks safely.
- Preserve site availability or use temporary maintenance mode for cutover; ensure cleanup even after fatal errors or a timeout.
- Integrate with Restore wizard and real status reporting.

## Acceptance criteria
- Tests demonstrate the three policies on disposable directories.
- Unrelated files and protected paths are never deleted.
- Errors stop or roll back safely, including mid-apply interruption.
- UI preview and actual file diff agree.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
