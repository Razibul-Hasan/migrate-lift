# PART 16 — Security Tests Release

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
Stabilize and package the initial free WordPress.org-ready release after the previous features are implemented and tested.

## Build only this part
- Run PHP lint, WordPress Coding Standards/PHPCS where available, TS typecheck, JS lint, unit and integration suites, and manual accessibility checks.
- Threat model backup download endpoints, upload extraction (zip slip / archive bombs), SSRF, CSRF, cross-site pairing, untrusted DB content, timeouts, SQL identifiers, secrets, REST permissions, path traversal, retention cleanup, and broken rollback.
- Add a test matrix for low-resource shared hosting; large media libraries; different table prefixes; domain changes; file collisions; cross-provider HTTPS; misconfigured cron; PHP limits; Unicode content; deactivation/reactivation; multisite should be blocked if unsupported.
- Ensure all strings are translation-ready, user-facing notices humane, destructive actions clearly flagged and every job has actionable logs.
- Produce `readme.txt`, changelog, license, uninstall behavior, screenshots guidance and packaging script with only deployable production files.
- Verify the ZIP installs on a fresh WordPress instance and functions without npm or shell access on the destination host.
- Report any unimplemented features as explicit limitations; never make unverifiable 'unlimited' or 'one-click zero downtime' claims.

## Acceptance criteria
- No critical security issues or known data-loss paths remain in test coverage.
- Installable release ZIP works on a clean environment.
- Readme accurately reflects implemented (not planned) functionality.
- Feature status document lists what is verified and what remains unsupported.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
