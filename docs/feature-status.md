# Migrate Lift — Feature Status & Roadmap Tracking

This document tracks the verified implementation status of all features across the 17-part development plan. Feature states are strictly categorized as:
- `planned`: Designed, scheduled in the roadmap, not yet implemented.
- `in progress`: Actively being coded or modified.
- `tested`: Implemented and verified in the local development/testbed environment.
- `released`: Validated, tagged, and ready for production packaging.

---

## 1. Core Feature Matrix

| Feature | Scope / Description | State | Target Phase |
|---|---|---|---|
| **Plugin Bootstrap & Standards** | Strict prefixing, main bootstrap file, and constants | `tested` | Part 01 |
| **Project Contract & Docs** | Comprehensive architectural, security, and status documentation | `tested` | Part 00 |
| **Design System & App Shell** | SaaS-style calm canvas, teal accents, design tokens | `tested` | Part 02 |
| **Dashboard UI & Metric Cards** | Primary dashboard with empty/active states and quick actions | `tested` | Part 03 |
| **Resumable Job Engine** | Time-budgeted chunked operations with state persistence | `tested` | Part 04 |
| **Activity Logging System** | Structured audit trail of backup, import, and migration jobs | `tested` | Part 04 |
| **Full Site Backup (.tgz)** | Streaming TAR/GZ database + wp-content exporter | `tested` | Part 05 |
| **Compression Modes** | Multi-mode compression (Balanced, Smallest, Fastest) + media bypass | `tested` | Part 06 |
| **Granular Exclusions** | Rule-based directory and file pattern exclusion system with UI editor | `tested` | Part 06 |
| **Backup Library Management** | Archive listing, direct download, deletion, and validation | `tested` | Part 07 |
| **Automatic Daily Scheduler** | WP-Cron and system crontab automated backup with retention | `tested` | Part 08 |
| **Large Backup SFTP Importer** | Automatic discovery of archives dropped directly via SFTP/cPanel | `tested` | Part 09 |
| **Chunked Browser Uploader** | Client-side sliced uploads with automatic resume and speed tracking | `tested` | Part 09 |
| **Restore Preflight Engine** | Server readiness, disk capacity, PHP version & memory check | `tested` | Part 10 |
| **Recovery Checkpoint Snapshot**| Automatic safety rollback snapshot prior to destructive restore | `tested` | Part 10 |
| **Safe File Replacement Engine** | Staged extraction with rollback preservation of active plugin | `tested` | Part 11 |
| **Safe Database Replacement** | Staged table import (`mltmp_`) and atomic `RENAME TABLE` swap | `tested` | Part 12 |
| **Serialization Search/Replace** | Deep recursive URL & path search-replace across all tables | `tested` | Part 12 |
| **Site Pairing Handshake** | Time-limited HMAC-SHA256 authenticated pairing keys | `tested` | Part 13 |
| **Direct Transfer Engine** | Direct server-to-server HTTPS streaming migration | `tested` | Part 14 |
| **Migration Wizard UI** | Guided step-by-step end-to-end migration flow | `tested` | Part 15 |
| **Security Audit & Release QA** | Sanitization check, WP.org validation, release packaging | `released` | Part 16 |

---

## 2. Phase-by-Phase Progress Tracker

- [x] **Part 00: Project Contract** (`tested`)
- [x] **Part 01: Plugin Bootstrap & React Shell** (`tested`)
- [x] **Part 02: Design System & App Shell** (`tested`)
- [x] **Part 03: Dashboard Page** (`tested`)
- [x] **Part 04: Job Engine & Activity Logging** (`tested`)
- [x] **Part 05: Backup Engine** (`tested`)
- [x] **Part 06: Compression & Exclusions** (`tested`)
- [x] **Part 07: Backup Library UI** (`tested`)
- [x] **Part 08: Daily Backup Scheduler** (`tested`)
- [x] **Part 09: Large Backup Import (SFTP / cPanel)** (`tested`)
- [x] **Part 10: Restore Preflight & Recovery Checkpoint** (`tested`)
- [x] **Part 11: File Replacement Engine** (`tested`)
- [x] **Part 12: Database Replacement Engine** (`tested`)
- [x] **Part 13: Site Pairing & Security** (`tested`)
- [x] **Part 14: Direct Transfer Engine** (`tested`)
- [x] **Part 15: Migration Wizard** (`tested`)
- [x] **Part 16: Security Tests & Release Packaging** (`released`)

