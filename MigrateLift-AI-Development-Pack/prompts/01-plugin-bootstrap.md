# PART 01 — Plugin Bootstrap

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
Create an installable minimal WordPress plugin and a React/TypeScript admin entry point that can be expanded later.

## Build only this part
- Create the WordPress plugin header in `migrate-lift.php` with correct text domain and license; guard direct file access.
- Set safe activation/deactivation handlers: initialize only minimal options, remove scheduled tasks on deactivation, never wipe user backups or data upon deactivation.
- Register one top-level MigrateLift admin menu with a dedicated React mounting element; keep WordPress admin menu/navigation intact.
- Configure `@wordpress/scripts` build (`npm run build`, `npm run start`) and consume `build/index.asset.php` for dependencies and versioning. Use the right `wp-components` stylesheet dependency.
- Localize REST root and nonce safely or use documented WordPress API fetch middleware. Use `@wordpress/i18n` for strings.
- Register a read-only `/health` authenticated REST endpoint; return plugin version, PHP info in non-sensitive aggregate form, and availability state.
- Add minimal install/build instructions and a PHP lint step.

## Acceptance criteria
- The plugin activates without PHP fatal errors, and MigrateLift appears in WordPress admin only for authorized users.
- Built JS mounts correctly; no broken route or white screen.
- REST health endpoint rejects unauthorized requests and returns JSON to an authorized administrator.
- No migration/backup operations are simulated as complete.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
