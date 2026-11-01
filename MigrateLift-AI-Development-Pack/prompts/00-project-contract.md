# PART 00 — Project Contract

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
Treat this file as the permanent project specification. Create or revise `PROJECT_SPEC.md` so every future session can follow the same conventions. Do not implement the whole plugin in one step.

## Build only this part
- Audit an existing repository if one is present; summarize current working features and file layout before modifying anything.
- Record the six committed features: automatic daily backup; compression and exclusions; importing large backups already uploaded through cPanel/SFTP; direct site-to-site migration; safe destination-file replacement; safe destination-database replacement.
- Record a complete work sequence and feature states: `planned`, `in progress`, `tested`, `released`.
- Define MVP constraints: self-hosted, free, no mandatory vendor account, no fake "unlimited" upload or compression claims.
- Define documented compatibility target based on the actual test environment, not a guessed latest version.
- Add `docs/architecture.md`, `docs/security-model.md`, `docs/feature-status.md`, and a concise `CHANGELOG.md`.
- Specify that database/file replacement will be integrated only after recovery and preflight checks exist.

## Acceptance criteria
- Developers can identify the current status of each feature from one document.
- Shared naming rules match the exact slug and prefixes above.
- Future AI sessions can follow the project without asking to rewrite the plugin.
- No functionality is represented as finished merely because it is planned.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
