# PART 13 — Site Pairing Security

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
Create a trust connection between two WordPress sites running MigrateLift, without a vendor account.

## Build only this part
- Source and destination each have MigrateLift; the destination admin initiates pairing and generates a short-lived single-use connection token with expiry and limited capabilities.
- Implement authenticated, rate-limited handshake exchanging only required capability metadata and endpoint info; both ends show their origin/target URL and request explicit admin approval.
- Bind credentials to the exact approved origin, site identity, transfer job and expiration; avoid storing plaintext long-lived secrets or displaying tokens again after pairing. Build revoke/disconnect UI.
- Use HTTPS with certificate validation, request signing/replay protection where appropriate; reject redirects to arbitrary hosts. Validate destination URLs and block localhost/private network/metadata-service targets to reduce SSRF risk unless deliberate restricted local-development mode is explicitly enabled.
- REST endpoints must use appropriate trust checks for cross-site requests *and* capability checks for local admin requests. Do not use a WordPress admin nonce as a cross-server API secret.
- Display 'Connected' only after round-trip handshake has been verified. Provide clear connection diagnostics (TLS error, DNS fail, wrong token, incompatible version).
- Add pairing tests for token replay, expiry, unauthorized endpoints and incorrect URL.

## Acceptance criteria
- Two disposable test WordPress sites can pair and revoke safely.
- Expired or replayed tokens cannot authorize work.
- No passwords or secrets appear in logs or browser-accessible permanent settings.
- UI never falsely reports a connection.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
