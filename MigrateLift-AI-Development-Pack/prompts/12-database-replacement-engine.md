# PART 12 — Database Replacement Engine

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
Implement controlled WordPress database replacement with an explicit recovery strategy and serialization-safe domain changes.

## Build only this part
- Implement modes `Keep destination DB`, `Replace selected site tables`, `Replace mapped destination WordPress tables`. Do **not** present arbitrary database-wide DROP as a supported feature.
- Validate archive DB schema and table ownership/prefix mapping; never modify unrelated tables sharing a database or assume `wp_` is the actual prefix.
- Stage imported tables with temporary names where available; verify row counts/checksums where practical; swap only in a controlled cutover with a verified recovery checkpoint.
- Treat WordPress users and authentication sessions carefully; document possible admin logout and preserve recovery access. Avoid overwriting database connection credentials or host environment secrets.
- Handle changes in site URL and paths via serialization-aware replacement (not raw SQL string REPLACE), with dry-run counts; skip opaque/binary data and report unsupported data.
- Stop safely on unsupported multisite/network configurations rather than silently corrupting content.
- Implement cancellation boundaries and recovery/rollback UI, with fixture tests covering table prefixes, large meta rows, serialized objects and partial import failures.

## Acceptance criteria
- Dry run lists exact tables that would be affected.
- Only approved site tables are affected; unrelated DB tables remain untouched.
- URL migration preserves serialized values in regression tests.
- Failure leaves a documented recovery route; destructive apply requires explicit confirmation.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
