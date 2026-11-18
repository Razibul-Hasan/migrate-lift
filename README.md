<div align="center">

<img src="assets/images/logo.png" alt="MigrateLift logo" width="110" height="110" />

# MigrateLift

### WordPress migration, backup & restore, made simple.

Move a site to a new host or domain, back it up, restore it, or clone it to staging,<br />all from your WordPress dashboard.

<br />

[![Version](https://img.shields.io/badge/version-1.0.2-1a6fe8?style=for-the-badge)](CHANGELOG.md)
[![WordPress](https://img.shields.io/badge/WordPress-6.6%2B-21759b?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPLv2%2B-2ea44f?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0.html)

<br />

[**📖 Documentation**](https://bestwebexpert.com/migrate-lift/?utm_source=github&utm_medium=readme&utm_campaign=docs) &nbsp;·&nbsp;
[**🎬 Video Tutorial**](https://youtu.be/_j4BCOmvWJE) &nbsp;·&nbsp;
[**🤝 Hire an Expert**](https://bestwebexpert.com/?utm_source=github&utm_medium=readme&utm_campaign=hire) &nbsp;·&nbsp;
[**📝 Changelog**](CHANGELOG.md)

</div>

<br />

<div align="center">

### 🚚 Need us to move your site for you?

Complex migration, large site, or a tight deadline? **Skip the stress and let the team behind MigrateLift transfer your WordPress site for you.**

[![Get Migration Help](https://img.shields.io/badge/Get%20Migration%20Help-Contact%20Us-1a6fe8?style=for-the-badge&logo=wordpress&logoColor=white)](https://bestwebexpert.com/?utm_source=github&utm_medium=readme&utm_campaign=cta_migration)
[![Get Support](https://img.shields.io/badge/Need%20Support%3F-Get%20Help-2ea44f?style=for-the-badge)](https://bestwebexpert.com/?utm_source=github&utm_medium=readme&utm_campaign=cta_support)

<sub>The plugin is 100% free. Professional migration is optional.</sub>

</div>

<br />

## ✨ Why MigrateLift

<table>
  <tr>
    <td width="33%" valign="top">
      <h4>🧭 Guided</h4>
      A step-by-step wizard with built-in checks before anything is changed.
    </td>
    <td width="33%" valign="top">
      <h4>🔗 No cloud account</h4>
      Transfer directly from site to site over HTTPS. Nothing to sign up for.
    </td>
    <td width="33%" valign="top">
      <h4>🆓 Free & open source</h4>
      Every feature works without a paid plan. GPLv2 or later.
    </td>
  </tr>
</table>

## 🚀 Features

| | Feature | Details |
| :-: | --- | --- |
| 🚚 | **Site migration** | Move to a new host or domain with an export/import archive, or a direct site-to-site transfer over HTTPS. |
| 💾 | **Backup & export** | The database and `wp-content` in one `.tgz` or `.zip` archive, streamed with a time budget so it copes with low memory and timeouts. |
| ♻️ | **Import & restore** | Preflight checks, a safety snapshot, staged file replacement and database table swapping. |
| 🔁 | **URL & path replacement** | Automatic and safe for PHP-serialized data. |
| ⏰ | **Scheduled backups** | Daily backups through WP-Cron with rolling retention. |
| 📦 | **Chunked uploads** | Large archives upload in chunks and resume if interrupted. |
| 🔐 | **Secure transfers** | Single-use pairing keys, HMAC-SHA256 request signing, replay and SSRF protection, and SHA-256 integrity checks. |
| 🎨 | **Modern admin UI** | React screens built with `@wordpress/components`. |

## ⚡ Quick start

```text
 ┌──────────────┐      ┌──────────────┐      ┌──────────────┐
 │  1. Install  │ ───▶ │  2. Export   │ ───▶ │  3. Import   │
 │  both sites  │      │  or Migrate  │      │  & restore   │
 └──────────────┘      └──────────────┘      └──────────────┘
```

1. Install and activate MigrateLift on both sites.
2. Open **MigrateLift** in the admin menu.
3. Choose **Export** to download an archive, or **Migrate** for a direct transfer.
4. On the destination site, use **Import** and upload the archive.
5. Review the checks, confirm the restore, then test pages, links and media.

> [!WARNING]
> A restore replaces data on the destination site. Keep a separate verified backup before restoring or migrating a production site. Multisite is not supported.

## 🛠️ Development

> [!NOTE]
> `build/` is not tracked in git and the plugin loads its admin screens from it. Run `npm install && npm run build` after cloning, and again after pulling changes to `src/`.

| Command | What it does |
| --- | --- |
| `npm install` | Install dependencies |
| `npm run build` | Production build with `@wordpress/scripts` |
| `npm run start` | Watch mode |
| `npm run plugin-zip` | Create `migrate-lift.zip` for WordPress.org or dashboard upload |

<details>
<summary><b>📁 Project structure</b></summary>

<br />

```text
migrate-lift.php      Plugin bootstrap, activation and deactivation
uninstall.php         Cleanup on delete
includes/             PHP: engines (backup, import, restore, transfer, scheduler), REST API, admin
src/                  React admin app (views, components, utils, styles)
build/                Compiled assets (generated, not tracked)
readme.txt            WordPress.org readme
```

</details>

<details>
<summary><b>🏷️ Conventions</b></summary>

<br />

| Item | Value |
| --- | --- |
| Slug / text domain | `migrate-lift` |
| PHP prefixes | `migrate_lift_` functions and hooks, `Migrate_Lift_` classes, `MIGRATE_LIFT_` constants |
| JS global | `migrateLift` |
| CSS / handle prefix | `migrate-lift-` |
| REST namespace | `migrate-lift/v1` |

</details>

## 💬 Help & Support

| I want to... | Go here |
| --- | --- |
| Learn how to use the plugin | [Documentation & guides](https://bestwebexpert.com/migrate-lift/?utm_source=github&utm_medium=readme&utm_campaign=docs) |
| Watch it in action | [Video tutorial](https://youtu.be/_j4BCOmvWJE) |
| Report a bug or request a feature | [Open an issue](../../issues) |
| **Have my site transferred for me** | [**Contact Best Web Expert**](https://bestwebexpert.com/?utm_source=github&utm_medium=readme&utm_campaign=transfer) |
| Get help with a complex or custom project | [**Hire an expert**](https://bestwebexpert.com/?utm_source=github&utm_medium=readme&utm_campaign=support) |

## 📄 License

GPLv2 or later. See https://www.gnu.org/licenses/gpl-2.0.html.

<div align="center">

<br />

Made with ❤️ by [Best Web Expert](https://bestwebexpert.com/?utm_source=github&utm_medium=readme&utm_campaign=footer)  
[Visit our website](https://bestwebexpert.com/?utm_source=github&utm_medium=readme&utm_campaign=footer) for more WordPress tools and services

</div>
