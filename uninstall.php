<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package MigrateLift
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'migrate_lift_daily_backup' );
wp_clear_scheduled_hook( 'migrate_lift_cron_worker' );

// Delete plugin options.
$migrate_lift_options_to_remove = array(
	'migrate_lift_version',
	'migrate_lift_activated_at',
	'migrate_lift_pending_pairings',
	'migrate_lift_paired_sites',
	'migrate_lift_schedule_settings',
	'migrate_lift_compression_settings',
	'migrate_lift_exclusion_rules',
	'migrate_lift_activity_logs',
	'migrate_lift_recovery_checkpoints',
	'migrate_lift_recovery_point',
	'migrate_lift_backup_metadata',
);

foreach ( $migrate_lift_options_to_remove as $migrate_lift_option_name ) {
	delete_option( $migrate_lift_option_name );
}

// Clean up transients created by MigrateLift.
delete_transient( 'migrate_lift_activation_redirect' );

global $wpdb;
if ( isset( $wpdb->options ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup on uninstall.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ml_%' OR option_name LIKE '_transient_timeout_ml_%'" );
}

// Check if user explicitly chose to remove all archives on uninstall.
$migrate_lift_wipe_files = get_option( 'migrate_lift_wipe_on_uninstall', false );
if ( $migrate_lift_wipe_files ) {
	$migrate_lift_upload_dir  = wp_upload_dir();
	$migrate_lift_content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( $migrate_lift_upload_dir['basedir'] );
	$migrate_lift_store_dir   = untrailingslashit( $migrate_lift_content_dir ) . '/migrate-lift-files';

	if ( is_dir( $migrate_lift_store_dir ) ) {
		$migrate_lift_files = glob( $migrate_lift_store_dir . '/*' );
		if ( is_array( $migrate_lift_files ) ) {
			foreach ( $migrate_lift_files as $migrate_lift_file ) {
				if ( is_file( $migrate_lift_file ) ) {
					wp_delete_file( $migrate_lift_file );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $migrate_lift_store_dir );
	}
}
delete_option( 'migrate_lift_wipe_on_uninstall' );
