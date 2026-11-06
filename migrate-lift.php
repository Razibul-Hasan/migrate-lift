<?php

/**
 * Plugin Name:       MigrateLift – Migration, Backup & Restore
 * Plugin URI:        https://bestwebexpert.com/migrate-lift/
 * Description:       Lightweight site migration, backup and restore for WordPress. Export database and wp-content into one .tgz archive; import on another site with automatic URL replacement.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            bestwpexpert
 * Author URI:        https://bestwebexpert.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       migrate-lift
 * Domain Path:       /languages
 *
 * @package           MigrateLift
 */

defined('ABSPATH') || exit;

// PHP Constants Prefix: MIGRATE_LIFT_
define('MIGRATE_LIFT_VERSION', '1.0.0');
define('MIGRATE_LIFT_FILE', __FILE__);
define('MIGRATE_LIFT_PATH', plugin_dir_path(__FILE__));
define('MIGRATE_LIFT_URL', plugin_dir_url(__FILE__));
define('MIGRATE_LIFT_REST_NAMESPACE', 'migrate-lift/v1');

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/class-migrate-lift-activity-logger.php';
require_once __DIR__ . '/includes/class-migrate-lift-compression.php';
require_once __DIR__ . '/includes/class-migrate-lift-tar.php';
require_once __DIR__ . '/includes/class-migrate-lift-zip.php';
require_once __DIR__ . '/includes/class-migrate-lift-backup-engine.php';
require_once __DIR__ . '/includes/class-migrate-lift-import-engine.php';
require_once __DIR__ . '/includes/class-migrate-lift-restore-preflight.php';
require_once __DIR__ . '/includes/class-migrate-lift-file-replacement-engine.php';
require_once __DIR__ . '/includes/class-migrate-lift-database-replacement-engine.php';
require_once __DIR__ . '/includes/class-migrate-lift-job-engine.php';
require_once __DIR__ . '/includes/class-migrate-lift-scheduler.php';
require_once __DIR__ . '/includes/class-migrate-lift-site-pairing.php';
require_once __DIR__ . '/includes/class-migrate-lift-direct-transfer-engine.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/import.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest-api.php';

// Initialize background handlers.
Migrate_Lift_Backup_Engine::init();
Migrate_Lift_Import_Engine::init();
Migrate_Lift_Scheduler::init();
Migrate_Lift_Direct_Transfer_Engine::init();

/**
 * Plugin activation handler.
 * Initializes minimal options and ensures protected storage directory exists.
 */
function migrate_lift_activate()
{
	if (version_compare(PHP_VERSION, '7.4', '<')) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(
			esc_html__('MigrateLift requires PHP 7.4 or higher.', 'migrate-lift'),
			'Plugin Activation Error',
			array('back_link' => true)
		);
	}

	if (false === get_option('migrate_lift_version')) {
		add_option('migrate_lift_version', MIGRATE_LIFT_VERSION);
	} else {
		update_option('migrate_lift_version', MIGRATE_LIFT_VERSION);
	}

	if (false === get_option('migrate_lift_activated_at')) {
		add_option('migrate_lift_activated_at', time());
	}

	// Ensure protected storage directory is initialized.
	if (function_exists('migrate_lift_dir')) {
		migrate_lift_dir();
	}

	// Reschedule backups if automated schedule was previously enabled.
	if (class_exists('Migrate_Lift_Scheduler')) {
		Migrate_Lift_Scheduler::reschedule();
	}

	// Flag for dashboard redirect on single plugin activation.
	set_transient('migrate_lift_activation_redirect', 1, 60);
}
register_activation_hook(__FILE__, 'migrate_lift_activate');

/**
 * Plugin deactivation handler.
 * Clears scheduled cron events; preserves user backups and configuration data.
 */
function migrate_lift_deactivate()
{
	delete_transient('migrate_lift_activation_redirect');

	// Clear any scheduled cron events for MigrateLift.
	if (class_exists('Migrate_Lift_Scheduler')) {
		Migrate_Lift_Scheduler::unschedule();
	}
	wp_clear_scheduled_hook('migrate_lift_daily_backup');
	wp_clear_scheduled_hook('migrate_lift_cleanup_cron');

	// Note: User backup archives and settings are deliberately preserved.
}
register_deactivation_hook(__FILE__, 'migrate_lift_deactivate');