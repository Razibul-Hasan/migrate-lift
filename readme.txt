=== MigrateLift – WordPress Migration, Backup & Restore ===
Contributors: bestwpexpert
Tags: migration, backup, restore, migrate, clone
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate a WordPress site to a new host or domain. Back up, restore, clone, and schedule backups with one-click export and import.

== Description ==

**Moving your WordPress site shouldn't be stressful.** **MigrateLift** is a WordPress migration, backup, and restore plugin that guides you step by step. Move to a new host or domain, back up your database and wp-content files, restore from an archive, or clone a site to staging, all from your dashboard.

**[Documentation](https://bestwebexpert.com/migrate-lift/)** | **[Video Tutorial](https://www.youtube.com/watch?v=_j4BCOmvWJE)** | **[Need Help? Contact Best Web Expert](https://bestwebexpert.com/)**

**Why people choose MigrateLift**

* Guided wizard with built-in checks before anything is changed
* No separate cloud account needed for direct site-to-site transfers
* URLs and paths are fixed for you, even in serialized data
* Free and open source

**Watch the video tutorial:**

https://www.youtube.com/watch?v=_j4BCOmvWJE

= Features =

* **WordPress Migration:** Move a site to a new host or domain with an export/import archive or a direct site-to-site transfer over HTTPS.
* **Backup and Export:** Save the database and wp-content files as one .tgz or .zip archive.
* **Import and Restore:** Upload an archive, review preflight checks, and confirm the restore.
* **Automatic URL Replacement:** Update URLs and paths during migration, including PHP-serialized data.
* **Scheduled Backups:** Run daily automatic backups with WP-Cron and rolling retention.
* **Large Backup Uploads:** Chunked uploads with resume support.
* **Restore Safeguards:** Safety snapshot, staged file replacement, and database table swapping.
* **Secure Transfers:** Single-use pairing keys, HMAC-SHA256 signing, replay and SSRF protection, and SHA-256 integrity checks.

= How to Migrate a WordPress Site =

1. Install and activate MigrateLift on both sites.
2. Open **Tools > MigrateLift**.
3. Choose **Export** to download an archive, or **Migrate** for a direct transfer.
4. On the destination site, use **Import** and upload the archive.
5. Review the checks, confirm the restore, then test your pages, links, and media.

= Use Cases =

* Migrate WordPress to a new host.
* Move a site to a new domain.
* Back up and restore a WordPress site.
* Clone a live site to staging or development.

= Need a Hand With Your Migration? =

MigrateLift is free to use and every feature works without a paid plan. If you'd rather have a professional handle a complex migration, a large site, or a custom WordPress project, the team behind the plugin offers optional services at [Best Web Expert](https://bestwebexpert.com/). Setup guides are in the [MigrateLift documentation](https://bestwebexpert.com/migrate-lift/).

= Source Code =

The admin screens are built with `@wordpress/scripts`. The source is in the `src` folder of this plugin and at https://github.com/Razibul-Hasan/migrate-lift. To rebuild it, run `npm install` and then `npm run build`.

**Important:** A restore replaces destination site data. Keep a separate verified backup before restoring or migrating a production site.

== Installation ==

1. Upload the `migrate-lift` folder to `/wp-content/plugins/`, or install it from **Plugins > Add New**.
2. Activate **MigrateLift**.
3. Open **Tools > MigrateLift** and choose **Export**, **Import**, or **Migrate**.
4. For automatic backups, set the schedule and retention in the MigrateLift settings.

== Frequently Asked Questions ==

= Can I migrate WordPress to a new host or domain? =

Yes. Use an exported archive or a direct site-to-site transfer. URLs and file paths are replaced during restore.

= What does a backup include? =

The database and the wp-content directory. Cache files, existing backup folders, and logs are excluded. WordPress core files are not included.

= Can I restore a site from a backup? =

Yes. Upload a .tgz or .zip archive on the Import tab, review the checks, and confirm. A restore can overwrite existing data, so back up first.

= Does it support direct site-to-site migration? =

Yes. Pair two sites and transfer the archive over HTTPS. Both sites need a valid HTTPS address. The destination is not changed until you confirm the restore.

= Does it handle serialized data when replacing URLs? =

Yes. URL and path replacement is safe for PHP-serialized data.

= Can I schedule automatic backups? =

Yes. Daily backups run through WP-Cron with configurable rolling retention. Timing depends on site traffic and your host.

= Can I upload large backups? =

Yes. Uploads are chunked and can resume, but limits still depend on your hosting.

= Can someone migrate my site for me? =

Yes, optionally. The plugin is free and works on its own. If you prefer expert help with a migration, you can contact [Best Web Expert](https://bestwebexpert.com/).

= Does it work with multisite? =

No. WordPress multisite is not currently supported.

= What happens if I deactivate or uninstall it? =

Deactivating keeps your backups and settings. Deleting the plugin removes its data, including stored backups, so save any you need first.

== Screenshots ==

1. MigrateLift dashboard with Export, Import, and Migrate options.
2. Backup export progress.
3. Import and restore with URL replacement.
4. Direct site-to-site migration wizard.
5. Scheduled backup and retention settings.

== Changelog ==

= 1.0.2 =
* MigrateLift now appears as its own top-level menu in the WordPress admin.

= 1.0.1 =
* Updated the dashboard icon and header navigation.
* Added a Hire an Expert link.
* Fixed the backup drawer starting backups in a loop.
* Job data is now stored as plain JSON.

= 1.0.0 =
* Initial public release.
