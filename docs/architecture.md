# Migrate Lift — Architectural Blueprint

This document details the architectural layout, core subsystems, data flow, and runtime mechanics of **Migrate Lift – WordPress Migration, Backup & Restore**.

---

## 1. Subsystem Architecture Overview

Migrate Lift is engineered around a decoupled architecture where a lightweight WordPress PHP backend handles streaming filesystem and database operations under strict execution budgets, while a native React client communicates over authenticated REST endpoints and AJAX fallback routes.

```
┌────────────────────────────────────────────────────────────────────────┐
│                        React 18 Admin Interface                        │
│         (@wordpress/components, @wordpress/element, @wordpress/i18n)  │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ JSON / FormData
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   Dual API Communication Gateway                       │
│      REST: /wp-json/migrate-lift/v1/*   │   AJAX: admin-ajax.php       │
│      (Strict Capability Check: current_user_can('manage_options'))     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   Job & Time-Budget Engine                             │
│   • Microtime deadline tracker (5-20s dynamic execution windows)       │
│   • State persistence in private JSON files (*-state.json)             │
└──────────────┬────────────────────┼─────────────────────┬──────────────┘
               │                    │                     │
               ▼                    ▼                     ▼
┌──────────────────────┐ ┌────────────────────┐ ┌────────────────────────┐
│ Streaming Archive    │ │ Database Engine    │ │ Search & Replace       │
│ • Migrate_Lift_Tar_  │ │ • wpdb batches     │ │ • Serialization-safe   │
│   Writer / Reader    │ │ • Schema dump      │ │ • Multi-format needle  │
│ • Incremental chunks │ │ • Staged imports   │ │   transform (JSON/URL) │
└──────────────────────┘ └────────────────────┘ └────────────────────────┘
```

---

## 2. Core Subsystems

### 2.1 Bootstrap & Security Guard (`migrate-lift.php`, `includes/admin.php`)
- **Constants Definition:** Centralizes prefixes (`MIGRATE_LIFT_*`) and version information.
- **Admin Assets Enqueueing:** Automatically loads the production React bundle and injects environment metadata via `wp_localize_script()`.
- **Capability Isolation:** Guards all management screens and request endpoints against non-administrator accounts.

### 2.2 Storage & Directory Lockdown (`includes/functions.php`)
- Storage folder resides at `wp-content/migrate-lift-files/`.
- Auto-initializes on startup with:
  - `index.php`: Empty PHP file preventing directory browsing.
  - `.htaccess`: Apache / LiteSpeed protection (`Require all denied` / `Deny from all`).

### 2.3 Streaming TAR/GZ Engine (`includes/class-migrate-lift-tar.php`)
- **`Migrate_Lift_Tar_Writer`:**
  - Streams concatenated standard Gzip members in chunks directly to disk (`fopen( ..., 'ab' )` and `deflate_add()`).
  - Supports GNU `@LongLink` format for filenames exceeding 99 characters.
  - Generates zero in-memory accumulation, making archive size limited only by hosting storage space.
- **`Migrate_Lift_Tar_Reader`:**
  - Streaming decompression reader capable of seeking and resuming byte offsets across multiple HTTP requests.
  - Parses standard USTAR and GNU TAR headers.

### 2.4 Time-Budgeting Execution Engine
To execute successfully on shared hosting environments with aggressive PHP timeouts (e.g., 30s) and minimal RAM:
1. At the start of every request, `migrate_lift_timer_start()` computes a deadline:
   $$\text{Deadline} = \text{microtime}() + \min(20, \lfloor\text{max\_execution\_time} / 2\rfloor)$$
2. Operations loop while checking `migrate_lift_out_of_time()`.
3. Before the timeout threshold is reached, intermediate byte positions and table cursors are persisted into `wp-content/migrate-lift-files/private-{random}/{job}-state.json`.
4. The client receives a progress payload and issues the subsequent iteration request with `step=continue`.

### 2.5 Database Staging & Safe Replacement
- **Export:** Dumps all tables matching `$wpdb->prefix`. The live prefix is abstracted to the neutral token `__MIGRATE_LIFT_PREFIX__`.
- **Import:** Dumps are written directly into staged temporary tables using the prefix `mltmp_`.
- **Atomic Swap:**
  - Step 1: Active tables are renamed from `wp_*` to parked temporary prefix `mlold_*`.
  - Step 2: Staged tables are renamed from `mltmp_*` to active `wp_*`.
  - Both operations are executed inside a single atomic MySQL `RENAME TABLE` query.
  - If any failure occurs, rollback is instantaneous.

### 2.6 Serialization-Safe Search & Replace Engine
- Operates over the staged `mltmp_` tables *prior* to activation.
- Prepares transformation matrices covering:
  - Raw URLs (`http://`, `https://`, schemeless `//`)
  - JSON-escaped URLs (`https:\/\/...`)
  - URL-encoded strings
  - Filesystem paths (`ABSPATH` transformations with both forward and backslashes)
- Employs deep recursive serialization checks using PHP `unserialize()` with `allowed_classes => false` for strict safety.

---

## 3. Communication Architecture: Dual REST & AJAX

To support both bleeding-edge headless/REST setups and legacy environments with strict REST API firewalls:
1. **REST API Namespace:** `migrate-lift/v1`
   - `/status` (GET): Server metrics, storage paths, active archives.
   - `/archives` (GET): Available backups with timestamps and sizes.
   - `/archives/{file}` (DELETE): Archive removal.
   - `/export` (POST): Stepped backup generation.
   - `/import` (POST): Stepped restore execution.
   - `/cleanup` (POST): Post-swap file/table cleanup via single-use cryptographically secure token.
   - `/clean` (POST): Admin-driven scratch file purger.
2. **AJAX API:** Mirrors identical actions over `admin-ajax.php` using action names prefixed with `migrate_lift_` and verified with `migrate_lift_nonce`.

