<?php
/**
 * Fired when the plugin is deleted. Removes everything the plugin created, including stored
 * backup archives, as the readme tells users before they delete it.
 *
 * @package MigrateLift
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/functions.php';

global $wpdb;

// Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'migrate_lift_scheduled_backup_event' );
wp_clear_scheduled_hook( 'migrate_lift_scheduled_backup_retry_event' );

// Delete plugin options and transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'migrate_lift_' ) . '%',
		$wpdb->esc_like( '_transient_migrate_lift_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_migrate_lift_' ) . '%'
	)
);

// Drop the temporary tables of an unfinished import.
foreach ( array_merge( migrate_lift_tables( MIGRATE_LIFT_TMP_PREFIX ), migrate_lift_tables( MIGRATE_LIFT_OLD_PREFIX ) ) as $migrate_lift_table ) {
	migrate_lift_q( 'DROP TABLE IF EXISTS %i', array( $migrate_lift_table ) );
}

// Delete the storage folder: archives, job records, logs and temporary files.
migrate_lift_rmdir( migrate_lift_content_dir() . '/' . MIGRATE_LIFT_STORE );
