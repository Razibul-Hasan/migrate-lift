# PART 09 — Large Backup Import

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
Implement robust import of large backup files, including archives already uploaded through the host file manager (cPanel/SFTP).

## Build only this part
- Provide Import page with two tabs: `Upload from browser` and `Already uploaded to server`.
- Browser upload: chunked resumable upload, server-side size/type/checksum checks, batch receipts, retry and cancel behavior, no reliance on a single PHP POST over the hosting upload limit.
- Already-uploaded backup: document dedicated private staging folder. Import only files inside the plugin-controlled import directory, not arbitrary paths supplied by admin input; display discovered eligible archives with size/last modified and integrity state.
- For web-root upload scenarios, ensure deny rules where supported but warn about servers that ignore `.htaccess`; provide best-effort private storage and reject if the file is publicly exposed. Prefer storage outside document root when possible.
- Put imported files in quarantine until manifest, signature/format, file list, checksums, path traversal, archive bombs and symlink rules are verified.
- Do NOT implement extraction/restore here; finish at a verified `Ready for restore` state and show warnings clearly.
- Connect import history and import errors to Activity page.

## Acceptance criteria
- Large file import resumes after an interrupted connection.
- Import cannot reference `/etc`, random server folders or external URLs.
- Malformed, oversized-expanded, traversal and nested archive cases are rejected safely.
- UI clearly distinguishes imported from restored.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
