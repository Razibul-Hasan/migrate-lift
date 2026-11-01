# Changelog

All notable changes to **MigrateLift – Migration, Backup & Restore** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] - 2026-11-01

### Added
- **Initial Public Release for WordPress.org:**
  - Complete WordPress site migration through archive export/import and direct HTTPS site-to-site transfer.
  - Streaming archive engine supporting both `.tgz` (tar.gz) and `.zip` formats with format 2.0 manifest schema.
  - Chunked database exporter with `utf8mb4` encoding, tokenized table prefixes, and row batching.
  - Resumable chunked archive uploader with integrity verification.
  - Site pairing and direct transfer over authenticated HTTPS with single-use pairing tokens, HMAC-SHA256 request signing, replay defense, and SSRF filtering.
  - Restore preflight checks, disk capacity validation, verified safety recovery checkpoints, and atomic table swaps.
  - Serialization-safe search and replace preserving string lengths in serialized PHP data.
  - Automated daily backup scheduler with WP-Cron integration, site-timezone awareness, and rolling retention policy.
  - Responsive admin dashboard and guided 7-step migration wizard built with WordPress components.


