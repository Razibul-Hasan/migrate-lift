<div align="center">

<img src="assets/images/logo.png" alt="MigrateLift logo" width="96" height="96" />

# MigrateLift

**WordPress migration, backup & restore, made simple.**

Move a site to a new host or domain, back it up, restore it, or clone it to staging, all from your WordPress dashboard.

![Version](https://img.shields.io/badge/version-1.0.1-1a6fe8)
![WordPress](https://img.shields.io/badge/WordPress-6.6%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License](https://img.shields.io/badge/license-GPLv2%2B-green)

[Documentation](https://bestwebexpert.com/migrate-lift/) · [Video Tutorial](https://youtu.be/_j4BCOmvWJE) · [Hire an Expert](https://bestwebexpert.com/)

</div>

---

## Features

- **Site migration:** move to a new host or domain with an export/import archive, or a direct site-to-site transfer over HTTPS.
- **Backup & export:** the database and `wp-content` in one `.tgz` or `.zip` archive, streamed with a time budget so it copes with low memory and timeouts.
- **Import & restore:** preflight checks, a safety snapshot, staged file replacement and database table swapping.
- **Automatic URL & path replacement:** safe for PHP-serialized data.
- **Scheduled backups:** daily backups through WP-Cron with rolling retention.
- **Chunked uploads:** large archives upload in chunks and resume if interrupted.
- **Secure transfers:** single-use pairing keys, HMAC-SHA256 request signing, replay and SSRF protection, and SHA-256 integrity checks.
- **Modern admin UI:** React screens built with `@wordpress/components`.

## Quick start

1. Install and activate MigrateLift on both sites.
2. Open **Tools > MigrateLift**.
3. Choose **Export** to download an archive, or **Migrate** for a direct transfer.
4. On the destination site, use **Import** and upload the archive.
5. Review the checks, confirm the restore, then test pages, links and media.

> **Warning:** a restore replaces data on the destination site. Keep a separate verified backup before restoring or migrating a production site. Multisite is not supported.

## Development

> `build/` is not tracked in git and the plugin loads its admin screens from it. Run `npm install && npm run build` after cloning, and again after pulling changes to `src/`.

| Command | What it does |
| --- | --- |
| `npm install` | Install dependencies |
| `npm run build` | Production build with `@wordpress/scripts` |
| `npm run start` | Watch mode |
| `npm test` | Run the test suite |
| `npm run lint:php` | PHP syntax check |
| `npm run plugin-zip` | Create `migrate-lift.zip` for WordPress.org or dashboard upload |

### Project structure

```
migrate-lift.php      Plugin bootstrap, activation and deactivation
uninstall.php         Cleanup on delete
includes/             PHP: engines (backup, import, restore, transfer, scheduler), REST API, admin
src/                  React admin app (views, components, utils, styles)
build/                Compiled assets (generated, not tracked)
tests/                Node and PHP tests
readme.txt            WordPress.org readme
```

### Conventions

| Item | Value |
| --- | --- |
| Slug / text domain | `migrate-lift` |
| PHP prefixes | `migrate_lift_` functions and hooks, `Migrate_Lift_` classes, `MIGRATE_LIFT_` constants |
| JS global | `migrateLift` |
| CSS / handle prefix | `migrate-lift-` |
| REST namespace | `migrate-lift/v1` |

## Support

Questions or a complex migration? See the [documentation](https://bestwebexpert.com/migrate-lift/), open an issue, or contact [Best Web Expert](https://bestwebexpert.com/) for optional professional help.

## License

GPLv2 or later. See https://www.gnu.org/licenses/gpl-2.0.html.
