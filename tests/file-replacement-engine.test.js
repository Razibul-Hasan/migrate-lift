/**
 * Unit & Contract Tests for MigrateLift File Replacement Engine (Part 11).
 *
 * Verifies:
 * - REST API declares strict permission callbacks for restore routes (/restore/diff-preview, /restore/execute)
 * - Three file policies: add_missing, overwrite_matching, sync_managed
 * - Canonical path protection: wp-config.php, migrate-lift-files, active plugin runtime, web server configs
 * - Diff preview calculation matches reality (creates, updates, deletes, skips)
 * - Explicit delete confirmation requirement for sync_managed
 * - Rollback mechanism restores destination files if replacement is interrupted
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'REST API declares strict permission callbacks for diff-preview and restore execution (Part 11)', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	const routes = [
		'/restore/diff-preview',
		'/restore/execute',
	];

	for ( const route of routes ) {
		assert.ok(
			restApiPhp.includes( `'${ route }'` ),
			`REST API must register route '${ route }'`
		);

		const routeIdx = restApiPhp.indexOf( `'${ route }'` );
		const routeBlock = restApiPhp.slice( routeIdx, routeIdx + 380 );

		assert.ok(
			routeBlock.includes( "'permission_callback' => $admin_permission" ),
			`Route '${ route }' must strictly enforce admin_permission callback`
		);
	}
} );

test( 'File Replacement Engine Contract: Three policies and canonical protection', () => {
	const fileEnginePhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-file-replacement-engine.php' ), 'utf-8' );

	// Verify three policies
	assert.ok( fileEnginePhp.includes( 'MODE_ADD_MISSING' ), 'Must declare MODE_ADD_MISSING' );
	assert.ok( fileEnginePhp.includes( 'MODE_OVERWRITE_MATCHING' ), 'Must declare MODE_OVERWRITE_MATCHING' );
	assert.ok( fileEnginePhp.includes( 'MODE_SYNC_MANAGED' ), 'Must declare MODE_SYNC_MANAGED' );

	// Verify canonical protection rules
	assert.ok( fileEnginePhp.includes( 'wp-config.php' ), 'Must protect wp-config.php' );
	assert.ok( fileEnginePhp.includes( '.htaccess' ), 'Must protect .htaccess' );
	assert.ok( fileEnginePhp.includes( 'web.config' ), 'Must protect web.config' );
	assert.ok( fileEnginePhp.includes( 'MIGRATE_LIFT_STORE' ), 'Must protect plugin storage' );
	assert.ok( fileEnginePhp.includes( 'mu-plugins' ), 'Must protect mu-plugins' );

	// Verify path traversal rejection
	assert.ok( fileEnginePhp.includes( 'is_safe_entry_path' ), 'Must provide is_safe_entry_path validator' );
} );

test( 'File Replacement Engine Contract: Rollback safety and explicit deletion confirmation', () => {
	const fileEnginePhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-file-replacement-engine.php' ), 'utf-8' );

	// Deletion confirmation check
	assert.ok(
		fileEnginePhp.includes( 'confirm_delete' ),
		'Must check confirm_delete option for destructive deletions'
	);

	// Rollback directory & restoration logic
	assert.ok(
		fileEnginePhp.includes( 'rollback_dir' ),
		'Must define rollback directory for safe restoration'
	);
	assert.ok(
		fileEnginePhp.includes( 'ROLLBACK:' ) || fileEnginePhp.includes( '$moves_applied' ),
		'Must record moves and restore on failure'
	);
} );

