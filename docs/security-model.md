# Migrate Lift — Security Model & Threat Mitigation

This document outlines the security architecture, authorization principles, storage protections, and threat mitigations implemented in **Migrate Lift – WordPress Migration, Backup & Restore**.

---

## 1. Core Security Principles

1. **Authentication $\neq$ Authorization:** WordPress nonces verify intent (CSRF defense) but *never* confer authorization. All operational endpoints independently verify user capabilities.
2. **Explicit Capability Checks:** All administrative, backup, and restore operations require explicit `current_user_can( 'manage_options' )` verification.
3. **Zero Public Disclosure:** Backup archives, state files, and logs are never exposed over public web requests or directory listings.
4. **Isolated Destructive Scope:** Destructive database and filesystem modifications are strictly constrained to pre-staged prefixes and folders. Live configuration files (`wp-config.php`, `.htaccess` at webroot) are never automatically modified.

---

## 2. Threat Model & Mitigations

### 2.1 Unauthorized Archive Access & Data Exfiltration
- **Threat:** An unauthenticated visitor guesses or scrapes the name of a backup archive inside `wp-content/migrate-lift-files/` and downloads full site data.
- **Mitigation:**
  1. The storage directory is protected with a web server denial rule (`.htaccess` featuring `Require all denied` and fallback `Deny from all`).
  2. A blank `index.php` is created to prevent directory indexing on Nginx or IIS environments.
  3. Archive filenames include high-entropy pseudo-random tokens generated via `wp_generate_password( 12, false )`.
  4. Direct file downloads are routed through `admin_post_migrate_lift_download` requiring both capability checks and admin nonces.

### 2.2 Cross-Site Request Forgery (CSRF)
- **Threat:** An administrator visits a malicious website that triggers an unintended background backup or site restore.
- **Mitigation:**
  - Every REST call requires the `X-WP-Nonce` header.
  - Every AJAX action verifies `migrate_lift_nonce` via `check_ajax_referer()`.

### 2.3 Post-Swap Session Loss & Temporary Cleanup Token
- **Threat Context:** During a complete site restore, the destination site's database tables (including `wp_users` and session tokens) are replaced by the imported archive's users. The active administrator session is naturally severed.
- **Mitigation:**
  - Before swapping tables, the engine generates a cryptographically secure 32-character single-use token (`wp_generate_password( 32, false )`) recorded in the job state.
  - The final cleanup step (`migrate_lift_cleanup_step`) validates this token using timing-safe comparison (`hash_equals()`).
  - Once cleanup completes, the token and state file are permanently deleted.

### 2.4 SQL Injection & Corrupt Deserialization
- **Threat:** Malicious data inside database dumps triggers SQL injection or PHP object injection during serialized search-and-replace.
- **Mitigation:**
  - Low-level database operations utilize direct `mysqli` links with escaping (`mysqli_real_escape_string`) and explicit binary column detection.
  - Search and replace un-serializes strings using `unserialize( $data, ['allowed_classes' => ['stdClass']] )`, preventing arbitrary PHP object instantiation and PHP Object Injection (POI) attacks.

### 2.5 Site Pairing & Direct Migration Handshake (Planned)
- **Design Requirement:** When direct site-to-site migration is active, credentials must not be passed in cleartext.
- **Mitigation Strategy:**
  - Pairing keys will utilize signed payloads (HMAC-SHA256) with a short time-to-live (TTL, e.g. 15 minutes).
  - Mutual TLS verification enforced on all server-to-server HTTPS transports.

