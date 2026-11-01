# PART 06 — Compression Optimization

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
Add realistic backup-size reduction without compromising restorability or creating misleading compression claims.

## Build only this part
- Support a widely portable ZIP-based backup format, optional compression levels, efficient SQL compression, extension-aware compression choices, safe exclusions (cache, debug logs, temporary artifacts, old backups).
- Calculate uncompressed source estimate, final archive size, reduction percentage when comparable, and a verification result. Clearly label source estimates.
- Provide mode selectors `Balanced` (default), `Smallest practical`, `Fastest`; base behavior on available PHP libraries and host support rather than requiring shell binaries.
- Add optional Zstandard only if it can be packaged and restored on destination; otherwise show as unsupported or do not expose it.
- Document that JPEG, MP4, ZIP and other compressed files often shrink little; do not alter original website assets by default.
- Support streaming/segmenting to avoid materializing enormous content in PHP memory; test archive corruption and no-space conditions.
- Add 'Compression & Exclusions' UI in Settings with backend validation.

## Acceptance criteria
- A backup created with the chosen mode can be verified and restored by the compatible importer.
- UI reports truthful compression metrics and unsupported modes are not selectable.
- No promise of universally tiny files or limitless compression.
- Tests include already-compressed media, large SQL dumps and invalid settings.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
