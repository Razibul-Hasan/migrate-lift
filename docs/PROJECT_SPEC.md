# PROJECT SPECIFICATION: Migrate Lift

> **Permanent Project Contract & Architectural Specification**  
> **Product Name:** Migrate Lift – WordPress Migration, Backup & Restore  
> **Plugin Slug:** `migrate-lift`  
> **Text Domain:** `migrate-lift`  
> **Version:** 1.0.0  

---

## 1. Project Naming & Identifier Standards

All codebase contributions, symbols, and artifacts must strictly adhere to the following naming conventions:

| Standard | Identifier / Prefix | Example / Usage |
|---|---|---|
| **Plugin Name** | Migrate Lift – WordPress Migration, Backup & Restore | Header in `migrate-lift.php`, `readme.txt` |
| **Plugin Slug** | `migrate-lift` | Plugin directory, distribution package |
| **Text Domain** | `migrate-lift` | `__( 'Text', 'migrate-lift' )` |
| **PHP Function Prefix** | `migrate_lift_` | `migrate_lift_dir()`, `migrate_lift_export_step()` |
| **PHP Class Prefix** | `Migrate_Lift_` | `Migrate_Lift_Tar_Writer`, `Migrate_Lift_Tar_Reader` |
| **PHP Constants Prefix** | `MIGRATE_LIFT_` | `MIGRATE_LIFT_VERSION`, `MIGRATE_LIFT_FILE` |
| **CSS Class Prefix** | `migrate-lift-` | `.migrate-lift-wrap`, `.migrate-lift-card` |
| **CSS ID Prefix** | `migrate-lift-` | `#migrate-lift-admin-root` |
| **JavaScript Namespace** | `migrateLift` | `window.migrateLift` |
| **JavaScript/CSS Handle** | `migrate-lift-` | `migrate-lift-admin` |
| **Database Option Prefix** | `migrate_lift_` | `get_option( 'migrate_lift_settings' )` |
| **WordPress Hook Prefix** | `migrate_lift_` | `do_action( 'migrate_lift_backup_complete' )` |
| **REST API Namespace** | `migrate-lift/v1` | `/wp-json/migrate-lift/v1/...` |

---

## 2. Core Philosophy & MVP Constraints

- **Self-Hosted & Independent:** 100% functional without mandatory external cloud services, vendor lock-in, or SaaS accounts.
- **Transparent Capabilities:** No misleading marketing claims (e.g., no fake "unlimited" compression or guaranteed zero-downtime promises where server resources dictate reality).
- **Graceful Under Shared Hosting Constraints:** Operates under low PHP `memory_limit` (e.g., 64MB/128MB) and short `max_execution_time` (e.g., 30s) by utilizing chunked streaming and time-budgeted execution.
- **Safety Over Speed:** Destructive operations (database or file replacement) are disabled until preflight checks pass and a recovery checkpoint exists.
- **Native Experience:** Utilizes official `@wordpress/components` and design language for a fast, accessible, and clean user experience.

---

## 3. The Six Committed Features

1. **Automatic Daily Backup:**
   - Reliable automated backup scheduling with WP-Cron and support for system crontabs.
   - Configurable retention limits to manage server storage.
2. **Compression & Granular Exclusions:**
   - Multi-mode compression (None / Fast / Best) with automatic media bypass for already-compressed assets.
   - Rule-based exclusion lists (caches, logs, other backup directories).
3. **Large Backup Import (cPanel / SFTP Direct Path):**
   - Direct recognition and validation of archives uploaded out-of-band to the storage directory.
   - Chunked browser uploads with resume capabilities to bypass PHP upload limits.
4. **Direct Site-to-Site Migration:**
   - Cryptographically paired HTTPS transfers between destination and source.
   - Resumable transfer stream without intermediate manual downloads.
5. **Safe Destination-File Replacement:**
   - Staged unpacking into temporary quarantine before swap.
   - Atomic or reversibly tracked replacement preserving current plugin and storage.
6. **Safe Destination-Database Replacement:**
   - Staged table import with temporary table prefix.
   - Serialization-aware deep search and replace for URLs and filesystem paths.
   - Atomic multi-table `RENAME TABLE` swap.

---

## 4. Technical Stack & Environment Targets

- **WordPress Core:** >= 6.0 (Verified active testbed: WP 7.1.x)
- **PHP Version:** >= 7.4 (Compatible through PHP 8.3)
- **Database:** MySQL >= 5.7 / MariaDB >= 10.3 (requires `mysqli` extension)
- **Frontend Admin:** React 18 (`@wordpress/element`), `@wordpress/components`, `@wordpress/i18n`, `@wordpress/api-fetch`
- **Build System:** Lightweight ESBuild (`build.js`) + `@wordpress/scripts` for release packaging.

---

## 5. Security & Safety Non-Negotiables

1. **Authorization is Strict:** Nonces are for CSRF protection only. Every sensitive action must check `current_user_can( 'manage_options' )` or a dedicated administrator capability.
2. **REST Route Permissions:** Every route in `migrate-lift/v1` must declare an explicit `permission_callback`. Public routes must never expose data.
3. **Archive Storage Protection:** The storage directory (`wp-content/migrate-lift-files`) must contain both `index.php` and an `.htaccess` file blocking public HTTP access (`Require all denied`).
4. **No Arbitrary Deletions:** Deletion logic is restricted strictly to the plugin's temporary tables (`mltmp_*`, `mlold_*`) and dedicated staging directories.
5. **Preflight & Recovery Checkpoints:** No file or database overwrite can begin without a completed preflight validation and rollback snapshot.

---

## 6. Implementation Sequence (17-Stage Plan)

- [x] **Part 00 — Project Contract & Repository Audit**
- [x] **Part 01 — Plugin Bootstrap & Admin Shell**
- [x] **Part 02 — Design System & UI Components**
- [x] **Part 03 — Dashboard Page & Visual States**
- [x] **Part 04 — Job Engine & Activity Logging**
- [x] **Part 05 — Full Backup Engine**
- [x] **Part 06 — Compression Modes & Exclusion Rules**
- [x] **Part 07 — Backup Library Management UI**
- [x] **Part 08 — Daily Backup Scheduler & Retention**
- [x] **Part 09 — Large Backup Import & SFTP Detection**
- [x] **Part 10 — Restore Preflight & Recovery Checkpoint**
- [x] **Part 11 — Safe File Replacement Engine**
- [x] **Part 12 — Safe Database Replacement & URL Search/Replace**
- [x] **Part 13 — Site Pairing & Security Handshake**
- [x] **Part 14 — Direct Transfer Engine**
- [x] **Part 15 — End-to-End Migration Wizard**
- [x] **Part 16 — Security Audit, QA Matrix & WP.org Packaging**

