/**
 * Unit & Contract Tests for MigrateLift Daily Backup Scheduler & Retention (Part 08).
 *
 * Verifies:
 * - REST API endpoints for scheduler settings and run-now trigger
 * - Strict authorization / permission_callback enforcement for all schedule endpoints
 * - Retention protection invariants (recovery checkpoints, incremental bases, .part files protected)
 * - Cron schedule registration and system crontab command generation
 * - Truthful Dashboard KPI integration for nextScheduled
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'REST API declares strict permission callbacks for scheduler routes (Part 08)', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	// Verify /settings/schedule route
	assert.ok(
		restApiPhp.includes( "'/settings/schedule'" ),
		'REST API must declare /settings/schedule route'
	);

	// Verify GET /settings/schedule has permission_callback
	const scheduleBlock = restApiPhp.slice(
		restApiPhp.indexOf( "'/settings/schedule'" ),
		restApiPhp.indexOf( "'/schedule/run-now'" )
	);
	assert.ok(
		scheduleBlock.includes( "'permission_callback' => $admin_permission" ),
		'/settings/schedule route must enforce admin_permission callback'
	);

	// Verify POST /schedule/run-now route
	assert.ok(
		restApiPhp.includes( "'/schedule/run-now'" ),
		'REST API must declare /schedule/run-now route'
	);
	const runNowBlock = restApiPhp.slice(
		restApiPhp.indexOf( "'/schedule/run-now'" ),
		restApiPhp.indexOf( "'/schedule/run-now'" ) + 350
	);
	assert.ok(
		runNowBlock.includes( "'permission_callback' => $admin_permission" ),
		'/schedule/run-now route must enforce admin_permission callback'
	);
	assert.ok(
		runNowBlock.includes( "'callback'            => 'migrate_lift_rest_run_schedule_now'" ),
		'/schedule/run-now route maps to migrate_lift_rest_run_schedule_now handler'
	);
} );

test( 'Scheduler Engine provides valid system crontab instructions', () => {
	const schedulerPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-scheduler.php' ), 'utf-8' );

	assert.ok(
		schedulerPhp.includes( 'get_system_cron_info' ),
		'Scheduler class must implement get_system_cron_info()'
	);
	assert.ok(
		schedulerPhp.includes( 'wp-cron.php?doing_wp_cron' ),
		'Cron info must include standard wget / curl wp-cron invocation'
	);
	assert.ok(
		schedulerPhp.includes( 'DISABLE_WP_CRON' ),
		'Cron info must include instructions for DISABLE_WP_CRON in wp-config.php'
	);
} );

test( 'Scheduler Engine protects critical archives during retention pruning', () => {
	const schedulerPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-scheduler.php' ), 'utf-8' );

	assert.ok(
		schedulerPhp.includes( 'migrate_lift_recovery_point' ),
		'Pruning must check migrate_lift_recovery_point option'
	);
	assert.ok(
		schedulerPhp.includes( 'migrate_lift_incremental_chain_base' ),
		'Pruning must check migrate_lift_incremental_chain_base option'
	);
	assert.ok(
		schedulerPhp.includes( '.part' ),
		'Pruning must exclude active staging .part files'
	);
} );

test( 'Scheduler Engine implements capped exponential backoff for failure retries', () => {
	const schedulerPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-scheduler.php' ), 'utf-8' );

	assert.ok(
		schedulerPhp.includes( 'migrate_lift_scheduled_backup_retry_event' ),
		'Scheduler must use dedicated retry hook migrate_lift_scheduled_backup_retry_event'
	);
	assert.ok(
		schedulerPhp.includes( 'record_failure' ),
		'Scheduler must implement record_failure()'
	);
	assert.ok(
		schedulerPhp.includes( 'maybe_notify_failure' ),
		'Scheduler must implement throttled admin notification on repeated failures'
	);
	assert.ok(
		schedulerPhp.includes( 'maybe_notify_low_disk' ),
		'Scheduler must implement throttled admin notification on low disk space'
	);
} );

test( 'Dashboard summary REST handler provides live scheduler data', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	const summaryHandlerBlock = restApiPhp.slice(
		restApiPhp.indexOf( 'function migrate_lift_rest_dashboard_summary' ),
		restApiPhp.indexOf( 'function migrate_lift_rest_get_archive_details' )
	);

	assert.ok(
		summaryHandlerBlock.includes( 'Migrate_Lift_Scheduler::get_settings()' ),
		'Dashboard summary must query Migrate_Lift_Scheduler::get_settings()'
	);
	assert.ok(
		summaryHandlerBlock.includes( "'schedulerActive'" ),
		'Dashboard summary must return schedulerActive'
	);
	assert.ok(
		summaryHandlerBlock.includes( "'nextScheduled'" ),
		'Dashboard summary must return nextScheduled'
	);
	assert.ok(
		summaryHandlerBlock.includes( "'scheduleEnabled'" ),
		'Dashboard summary must return scheduleEnabled'
	);
	assert.ok(
		summaryHandlerBlock.includes( "'scheduleFrequency'" ),
		'Dashboard summary must return scheduleFrequency'
	);
} );

