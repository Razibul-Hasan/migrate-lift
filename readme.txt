=== MigrateLift – Migration, Backup & Restore ===
Contributors: bestwpexpert
Tags: migration, backup, restore, export, import
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate WordPress sites, back up your database and files, and restore or import site archives with guided migration and scheduled backups.

== Description ==

**MigrateLift** is a WordPress migration, backup, and restore plugin for moving a website between hosts or domains, creating site backups, and restoring saved data. Export your WordPress database and wp-content files into one archive, import a backup on another WordPress installation, or transfer a site directly over HTTPS.

Whether you need to migrate a WordPress website, back up important site data, restore a previous backup, or clone a site for staging, MigrateLift provides a guided workflow from your WordPress dashboard.

= WordPress Migration, Backup, and Restore Features =

* **Website Migration:** Move a WordPress site's database and wp-content files to a new host or domain using an export/import archive or a direct site-to-site transfer.
* **Backup and Export:** Export the WordPress database and wp-content directory together as one compressed .tgz or .zip archive.
* **Import and Restore:** Upload a previously exported site archive, review preflight checks, and confirm the restore on the destination WordPress site.
* **Direct Site-to-Site Migration:** Pair two WordPress installations and transfer an archive directly over HTTPS without a separate cloud storage account.
* **Automatic URL Replacement:** Update site URLs and filesystem paths during migration while preserving PHP-serialized data.
* **Scheduled WordPress Backups:** Configure daily automated backups with WP-Cron and a rolling backup retention policy.
* **Chunked Archive Uploads:** Upload larger backup archives in chunks, with resume support if an upload is interrupted.
* **Preflight and Restore Safeguards:** Check the destination environment, create a safety snapshot, and use staged file replacement and database table swapping during restore.
* **Migration Security:** Use single-use pairing keys, HMAC-SHA256 request signing, replay protection, SSRF protection, and SHA-256 archive integrity verification for direct transfers.
* **WordPress Admin Dashboard:** Manage exports, imports, site migration, and scheduled backups from an interface built with WordPress components.

= How to Migrate a WordPress Site =

1. Install and activate MigrateLift on the WordPress sites involved in the migration.
2. Open **Tools > MigrateLift** in the WordPress dashboard.
3. Use **Export** to download a site archive for manual migration, or choose **Migrate** to begin a direct site-to-site transfer.
4. For archive migration, use **Import** on the destination site and upload the exported .tgz or .zip file.
5. Review the destination checks and restore details, then explicitly confirm the restore.
6. Check the migrated site's pages, links, media, and functionality after the process finishes.

For recurring backups instead of a site migration, configure the daily backup schedule and retention settings in MigrateLift.

= Common Use Cases =

* Migrate a WordPress website from one web host to another.
* Move a WordPress site to a different domain with URL replacement.
* Export a WordPress database and wp-content files for a manual backup.
* Import a site backup and restore content on another WordPress installation.
* Clone a live site to a staging or development environment.
* Schedule daily WordPress backups and manage retained archives.

= Source Code =

The admin screens are built with `@wordpress/scripts`. The source is in the `src` folder of this plugin and at https://github.com/Razibul-Hasan/migrate-lift. To rebuild it, run `npm install` and then `npm run build`.

**Important:** A restore replaces destination site data. Keep a separate verified backup before restoring or migrating a production website. For sites with active orders, bookings, or other frequently changing data, plan the migration carefully to avoid losing new changes.

== Installation ==

1. Upload the `migrate-lift` plugin folder to `/wp-content/plugins/`, or install the plugin through **Plugins > Add New** in your WordPress dashboard.
2. Activate **MigrateLift** from the **Plugins** screen.
3. Open **Tools > MigrateLift**.
4. Select **Export**, **Import**, or **Migrate** to start a site backup, restore, or migration.
5. To enable automatic backups, configure the daily schedule and retention policy in the MigrateLift settings.

== Frequently Asked Questions ==

= Can I migrate a WordPress website to a new host or domain? =

Yes. MigrateLift supports WordPress site migration using an exported archive or direct site-to-site transfer. It can replace site URLs and filesystem paths when restoring data to the destination.

= What is included in a WordPress backup or export? =

An export includes the WordPress database and wp-content directory. Temporary cache files, existing backup folders, server logs, and other non-essential files are excluded. WordPress core files are not part of the described export.

= Can I import a backup and restore my WordPress site? =

Yes. Use the Import tab to upload a compatible .tgz or .zip archive, review the preflight checks, and confirm the restore. Restoration can overwrite existing destination data, so make a separate backup first.

= Does MigrateLift support direct WordPress site-to-site migration? =

Yes. You can pair a source and destination WordPress installation and transfer an archive directly over HTTPS, so both sites need a valid HTTPS address. The transfer uses authenticated pairing and archive integrity checks, and it only sends data to the site you paired with. Keep the migration screen open while it runs. The destination site is not changed until you confirm the restore.

= Does the plugin support automatic URL replacement? =

Yes. The migration and restore process supports URL and filesystem path replacement, including values stored in PHP-serialized data.

= Can I schedule automatic WordPress backups? =

Yes. MigrateLift supports daily scheduled backups through WP-Cron, with configurable rolling retention. WP-Cron execution depends on WordPress cron activity and the hosting environment.

= Which backup archive formats are supported? =

MigrateLift supports .tgz (tar.gz) and .zip archives.

= Can I upload large WordPress backup archives? =

MigrateLift includes chunked uploading and resume support. Actual upload and processing capacity still depends on the limits and resources of your hosting environment.

= Does MigrateLift work with WordPress multisite? =

No. WordPress multisite networks are not currently supported.

= What happens if I deactivate or uninstall MigrateLift? =

Deactivating the plugin preserves its backup archives and settings. Deleting the plugin entirely removes its plugin data, including stored backup archives and options. Save any backups you need before deletion.

== Screenshots ==

1. MigrateLift dashboard with Export, Import, and Migrate options.
2. WordPress backup export progress with step-by-step status.
3. Backup import and site restore with URL replacement.
4. Guided direct WordPress site-to-site migration wizard.
5. Automated daily backup schedule and retention settings.

== Changelog ==

= 1.0.0 =
* Initial public release for the WordPress.org Plugin Directory.
* WordPress website migration through archive export/import and direct HTTPS site-to-site transfer.
* WordPress database and wp-content backup and restore with .tgz and .zip support.
* Secure site pairing, HMAC-SHA256 request signing, replay protection, SSRF protection, and archive integrity verification.
* Guided migration wizard, preflight checks, restore safeguards, and serialization-safe URL replacement.
* Daily scheduled backups, rolling retention, chunked archive uploads, and responsive WordPress admin interface.