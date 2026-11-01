# PART 02 — Design System App Shell

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
Implement a polished, accessible, consistent MigrateLift admin UI shell. Keep this part focused on layout and reusable components, not real backups.

## Build only this part
- Use a **clean premium SaaS aesthetic**: warm off-white `#F6F8FB` background, near-black `#152238` primary text, muted `#68758A`, subtle border `#E5EAF0`, restrained green-teal `#0D9E85`, and warning amber for cautions. Implement these as scoped CSS variables on `.migrate-lift-app`.
- Layout: fixed-width or responsive vertical product sidebar within WordPress content; brand mark; navigation: Dashboard, Backups, Schedule, Migrate Site, Import & Restore, Activity, Settings. Content max width ~1320px; roomy spacing; card radius 12–16px; restrained shadows; polished hover/focus/disabled states.
- Implement `AppShell`, `PageHeader`, `MetricCard`, `StatusPill`, `EmptyState`, `InlineNotice`, `ProgressBar`, `ConfirmDialog`, `Skeleton`, and `SectionCard` as reusable components.
- Create routes/tabs using a lightweight view state or established router if already installed; preserve browser history if feasible and avoid pulling a large router needlessly.
- Use `@wordpress/components` where practical (Button, Notice, Spinner, TextControl, SelectControl, ToggleControl, Modal) and `@wordpress/icons`; keep custom CSS scoped, never style `body` or generic `button` globally.
- Provide loading/empty/error patterns, mobile sidebar as accessible disclosure/drawer, full keyboard navigation, dark high-contrast text on light cards, and responsive layouts.
- Render placeholder pages with explicit 'Not configured yet' messages. Do not show fake successful operations.

## Acceptance criteria
- Looks cohesive at 1440px, 1024px, 768px, and 375px; no horizontal overflow.
- Focus and tab order are visible and logical; labels and dialogs accessible.
- All nav links change page; placeholders are truthful.
- WordPress native admin notices/menus are not obscured by custom CSS.

## Required response from the coding AI
1. Inspect the repository and give a brief plan scoped to this part.
2. Implement working code and update any relevant README/test fixtures.
3. Report exact changed files, setup commands, and tests run (including failures).
4. Explain any unavailable capabilities or TODO dependencies; do not claim an unimplemented action works.
5. Stop after this part. Do not start the next part until I explicitly ask.
