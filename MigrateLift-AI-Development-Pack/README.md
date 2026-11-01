# MigrateLift — AI Development Prompt Pack

A copy-paste, incremental implementation plan for **MigrateLift – WordPress Migration, Backup & Restore** (`migrate-lift`).

## What's inside
- `prompts/00-project-contract.md` to `prompts/16-security-tests-release.md`: one implementation task per file.
- `preview/dashboard-preview.html`: standalone, responsive **visual concept** of the premium dashboard. It uses sample data and does NOT connect to WordPress or perform backups.
- `preview/dashboard-preview.png`: screenshot of the dashboard concept (if present).
- `README.md`: this guide.

## How to use with Claude Code / Cursor / ChatGPT coding agent
1. Create/open a git repository for your plugin and ensure you have a disposable WordPress development site.
2. Start with **Part 00**; give the AI the contents of `00-project-contract.md` and access to the repository.
3. Next run **Part 01**. Continue sequentially, **one part per chat or iteration**. Each prompt includes shared context, a strictly limited implementation scope, acceptance criteria, and required handoff output.
4. When starting a new conversation, attach/open the current repository (or share the changed files), then paste the next part. Instruct the agent to inspect `PROJECT_SPEC.md`, `docs/feature-status.md`, and recent git history before changing code.
5. After each part: review the diff, activate/test on disposable WordPress, fix failing tests, and commit a checkpoint. Never run file or database replacement against a live site while testing.
6. Do not copy `dashboard-preview.html` directly into production. Its design is inspiration for the real React page in **Part 03**.

## Recommended order
| Part | Filename | Output |
|---|---|---|
| 00 | project-contract | Core spec and feature status |
| 01 | plugin-bootstrap | Valid WP plugin and React shell |
| 02 | design-system-app-shell | Reusable premium UI system |
| 03 | dashboard-page | Fully designed dashboard (real/empty states) |
| 04 | job-engine-activity | Resumable jobs and activity logs |
| 05 | backup-engine | Working full backup |
| 06 | compression-optimization | Compression modes and exclusions |
| 07 | backup-library-ui | Backup list, verify, download, delete |
| 08 | daily-backup-scheduler | Daily schedules and retention |
| 09 | large-backup-import | Browser chunks + cPanel/SFTP-import path |
| 10 | restore-preflight-recovery | Preflight, verified recovery, locked apply |
| 11 | file-replacement-engine | File policies with safe rollback |
| 12 | database-replacement-engine | DB table policies and safe URL transform |
| 13 | site-pairing-security | Secure destination handshake |
| 14 | direct-transfer-engine | Resumable HTTPS server transfer |
| 15 | migration-wizard | End-to-end guided migration |
| 16 | security-tests-release | QA, security and WP.org release package |

## WordPress best-practice references
- WordPress Components: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-components/
- WordPress Design System: https://developer.wordpress.org/block-editor/contributors/design/design-system-packages/
- WordPress REST endpoint permissions: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
- Nonce is not authorization: https://developer.wordpress.org/apis/security/nonces/
- WP-Cron behavior: https://developer.wordpress.org/plugins/cron/
- Serialization-aware URL migration: https://developer.wordpress.org/cli/commands/search-replace/

## Practical constraints
- Compression cannot ensure a tiny archive for images/videos that are already compressed.
- WP-Cron is usually triggered by page loads; for timing reliability offer optional server cron documentation.
- Direct server transfer still depends on server/network limits, hosting space and reliable endpoints; do not market 'unlimited' or true zero downtime without validated support.
- Anything that deletes destination data requires preflight, verified recovery snapshot, clear scope and explicit approval.
