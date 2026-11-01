/**
 * Unit & Contract Tests for MigrateLift Backup Library UI & Management API (Part 07).
 *
 * Verifies:
 * - REST API endpoints for archive listing, details, verification, and deletion
 * - Strict authorization / permission_callback enforcement
 * - Safe archive deletion validation (preventing deletion of active restore/recovery checkpoints)
 * - Prevention of duplicate active backup jobs (409 conflict contract)
 * - Authenticated streaming download contract (no unsigned public direct URLs)
 * - Truthful Dashboard KPI verification contract
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'REST API declares strict permission callbacks for backup library routes (Part 07)', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	// Verify /archives routes
	assert.ok(
		restApiPhp.includes( "'/archives'" ),
		'REST API must declare /archives route'
	);
	assert.ok(
		restApiPhp.includes( "'/archives/(?P<file>[a-zA-Z0-9._-]+)'" ),
		'REST API must declare /archives/:file details and delete routes'
	);

	// Verify GET /archives has permission_callback
	const archivesListBlock = restApiPhp.slice(
		restApiPhp.indexOf( "'/archives'" ),
		restApiPhp.indexOf( "'/archives/(?P<file>" )
	);
	assert.ok(
		archivesListBlock.includes( "'permission_callback' => $admin_permission" ),
		'/archives list route must enforce admin_permission callback'
	);

	// Verify DELETE /archives/:file has permission_callback
	const deleteBlock = restApiPhp.slice(
		restApiPhp.indexOf( "'callback'            => 'migrate_lift_rest_delete_archive'" ) - 100,
		restApiPhp.indexOf( "'callback'            => 'migrate_lift_rest_delete_archive'" ) + 150
	);
	assert.ok(
		deleteBlock.includes( "'permission_callback' => $admin_permission" ),
		'/archives/:file DELETE route must enforce admin_permission callback'
	);

	// Verify GET /archives/:file has permission_callback
	const detailsBlock = restApiPhp.slice(
		restApiPhp.indexOf( "'callback'            => 'migrate_lift_rest_get_archive_details'" ) - 100,
		restApiPhp.indexOf( "'callback'            => 'migrate_lift_rest_get_archive_details'" ) + 150
	);
	assert.ok(
		detailsBlock.includes( "'permission_callback' => $admin_permission" ),
		'/archives/:file GET details route must enforce admin_permission callback'
	);
} );

test( 'Authenticated streaming download contract (No public unsigned URLs)', () => {
	const functionsPhp = fs.readFileSync( path.join( __dirname, '../includes/functions.php' ), 'utf-8' );
	const adminPhp = fs.readFileSync( path.join( __dirname, '../includes/admin.php' ), 'utf-8' );

	// Download URL must route through authenticated admin-post.php with nonce
	assert.ok(
		functionsPhp.includes( "'action'   => 'migrate_lift_download'" ),
		'Download URL must use admin-post.php action'
	);
	assert.ok(
		functionsPhp.includes( "wp_create_nonce( 'migrate_lift_download' )" ),
		'Download URL must generate a cryptographic nonce'
	);

	// Handler must verify current_user_can('manage_options') and nonce
	assert.ok(
		adminPhp.includes( "current_user_can( 'manage_options' )" ),
		'Download handler must check manage_options capability'
	);
	assert.ok(
		adminPhp.includes( "check_admin_referer( 'migrate_lift_download' )" ),
		'Download handler must verify download nonce'
	);
	assert.ok(
		adminPhp.includes( "header( 'Content-Disposition: attachment;" ),
		'Download handler must stream file as attachment'
	);
} );

test( 'Safe deletion protects recovery points and active jobs', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	const deleteFunc = restApiPhp.slice(
		restApiPhp.indexOf( 'function migrate_lift_rest_delete_archive(' ),
		restApiPhp.indexOf( 'function migrate_lift_rest_export(' )
	);

	// Check 1: Active job reference check
	assert.ok(
		deleteFunc.includes( 'archive_in_use' ),
		'Delete handler must check if an active background job is using the archive'
	);

	// Check 2: Recovery point protection check
	assert.ok(
		deleteFunc.includes( 'migrate_lift_recovery_point' ),
		'Delete handler must check if archive is registered recovery point'
	);
	assert.ok(
		deleteFunc.includes( 'archive_is_recovery_point' ),
		'Delete handler must error if archive is designated recovery point'
	);

	// Check 3: Incremental base chain protection check
	assert.ok(
		deleteFunc.includes( 'migrate_lift_incremental_chain_base' ),
		'Delete handler must check if archive is base for incremental chain'
	);

	// Check 4: Activity logging
	assert.ok(
		deleteFunc.includes( 'Migrate_Lift_Activity_Logger::warning(' ),
		'Delete handler must log deletion event in audit trail'
	);
} );

test( 'Repeat backup requests disabled while matching job exists (409 conflict contract)', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	assert.ok(
		restApiPhp.includes( "'backup_job_already_active'" ),
		'REST API must declare backup_job_already_active error code'
	);
	assert.ok(
		restApiPhp.includes( "'status' => 409" ),
		'REST API must return HTTP 409 Conflict when a backup is already running'
	);
} );

test( 'Truthful Dashboard KPI contract: checksum verification required for success', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	const summaryFunc = restApiPhp.slice(
		restApiPhp.indexOf( 'function migrate_lift_rest_dashboard_summary(' ),
		restApiPhp.indexOf( 'function migrate_lift_rest_get_archive_details(' )
	);

	// Must check for verified boolean before setting as last_backup
	assert.ok(
		summaryFunc.includes( '! empty( $arch[\'verified\'] )' ),
		'Dashboard summary must only select verified archives as last successful backup'
	);
} );

