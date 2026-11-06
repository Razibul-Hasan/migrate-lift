# Migrate Lift – WordPress Migration, Backup & Restore

A lightweight WordPress migration, backup, and restore plugin featuring a modern React admin interface built entirely using official **WordPress Components** (`@wordpress/components`).

## Naming Standards & Identifiers

| Identifier | Value |
| --- | --- |
| **Plugin Name** | Migrate Lift – WordPress Migration, Backup & Restore |
| **Plugin Slug** | `migrate-lift` |
| **Text Domain** | `migrate-lift` |
| **PHP Function Prefix** | `migrate_lift_` |
| **PHP Class Prefix** | `Migrate_Lift_` |
| **PHP Constants Prefix** | `MIGRATE_LIFT_` |
| **CSS Class Prefix** | `migrate-lift-` |
| **CSS ID Prefix** | `migrate-lift-` |
| **JavaScript Namespace** | `migrateLift` |
| **JavaScript/CSS Handle** | `migrate-lift-` |
| **Database Option Prefix** | `migrate_lift_` |
| **WordPress Hook Prefix** | `migrate_lift_` |
| **REST API Namespace** | `migrate-lift/v1` |

## Key Features

- **Single Compressed Archive**: Streams database and entire `wp-content` folder (themes, plugins, uploads) into a `.tgz` archive with time-budgeted execution for low memory & timeout resilience.
- **Automatic URL & Path Replacement**: Automatically handles serialized data and database URL replacement on import.
- **Chunked File Uploads**: Seamlessly uploads large archives in chunks with auto-resume capability.
- **WordPress REST API & AJAX**: Full support for standard WordPress AJAX actions and REST endpoints under `migrate-lift/v1`.
- **Built with WordPress Components**: Uses official components throughout (`Card`, `Button`, `ButtonGroup`, `FormFileUpload`, `CheckboxControl`, `ProgressBar`, `Notice`, `Modal`, `Spinner`, `Flex`).
- **Standardized WordPress Build**: Built using official `@wordpress/scripts` with automatic dependency extraction and asset metadata generation.
- **Auto-generated asset metadata**: Generates `build/index.asset.php` automatically declaring WordPress script dependencies (`react-jsx-runtime`, `wp-api-fetch`, `wp-components`, `wp-element`, `wp-i18n`) and version hash.
- **Read-only REST Health Endpoint**: Provides authenticated system diagnostics at `/wp-json/migrate-lift/v1/health`.

## Project Structure

```
├── package.json                          # NPM scripts and dependencies (@wordpress/scripts)
├── bin/
│   └── lint-php.js                       # Cross-platform PHP syntax validation runner
├── readme.txt                            # Standard WordPress.org plugin readme
├── build/
│   ├── index.js                          # Compiled React production bundle
│   └── index.asset.php                   # Auto-generated WP dependencies & version hash
├── src/
│   ├── index.js                          # Entry point mounting React into #migrate-lift-admin-root
│   ├── App.js                            # Main tabbed view (Export, Import, Archives, Tools)
│   ├── components/
│   │   └── CleanTemporary.js             # Cleanup tools
│   └── utils/
│       └── api.js                        # AJAX & chunked upload handlers
├── includes/
│   ├── functions.php                     # Core helpers, SQL & filesystem utilities
│   ├── class-migrate-lift-tar.php        # Streaming .tar.gz writer and reader
│   ├── export.php                        # Site and database export handlers
│   ├── import.php                        # Site import and URL replacement handlers
│   ├── admin.php                         # Admin menu, asset registration, AJAX endpoints
│   └── rest-api.php                      # REST API routes under migrate-lift/v1
└── migrate-lift.php                      # Main plugin bootstrap file with activation/deactivation
```

## Development & Publishing Commands

> **Fresh clones must be built before use.** `build/` is not tracked in git, and the plugin loads its admin screens from it at runtime. Run `npm install && npm run build` after cloning, and `npm run build` again after pulling changes to `src/`.

- **Install dependencies:**
  ```bash
  npm install
  ```

- **Build production assets (@wordpress/scripts):**
  ```bash
  npm run build
  ```

- **Start watch mode during development:**
  ```bash
  npm run start
  ```

- **Run PHP syntax linting:**
  ```bash
  npm run lint:php
  ```

- **Create publish-ready plugin ZIP:**
  ```bash
  npm run plugin-zip
  ```
  Generates `migrate-lift.zip` containing only production files ready for WordPress.org or direct dashboard upload.
