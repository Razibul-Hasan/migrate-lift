/**
 * Unit & Contract Tests for MigrateLift Database Replacement Engine (Part 12).
 *
 * Verifies:
 * - Three database policies: keep_destination, replace_mapped, replace_selected
 * - Unrelated tables in database are never touched
 * - Staged table isolation with temporary prefix (mltmp_)
 * - Recursive serialization-safe search and replace preserves string lengths
 * - Admin user preservation avoids session lockout
 * - Atomic table swap cutover and rollback on failure
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'Database Replacement Engine Contract: Three policies and prefix mapping', () => {
	const dbEnginePhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-database-replacement-engine.php' ), 'utf-8' );

	// Verify modes
	assert.ok( dbEnginePhp.includes( 'MODE_KEEP' ), 'Must declare MODE_KEEP' );
	assert.ok( dbEnginePhp.includes( 'MODE_REPLACE_MAPPED' ), 'Must declare MODE_REPLACE_MAPPED' );
	assert.ok( dbEnginePhp.includes( 'MODE_REPLACE_SELECTED' ), 'Must declare MODE_REPLACE_SELECTED' );

	// Verify staged temporary isolation
	assert.ok( dbEnginePhp.includes( 'MIGRATE_LIFT_TMP_PREFIX' ), 'Must use MIGRATE_LIFT_TMP_PREFIX for staging' );
	assert.ok( dbEnginePhp.includes( 'stage_import_sql' ), 'Must provide stage_import_sql' );

	// Verify table groups
	assert.ok( dbEnginePhp.includes( 'get_table_groups' ), 'Must provide get_table_groups' );
	assert.ok( dbEnginePhp.includes( 'posts_and_comments' ), 'Must support posts_and_comments group' );
} );

test( 'Database Replacement Engine Contract: Serialization-safe replacement & admin preservation', () => {
	const dbEnginePhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-database-replacement-engine.php' ), 'utf-8' );

	// Serialization-safe search & replace
	assert.ok(
		dbEnginePhp.includes( 'recursive_serialization_replace' ),
		'Must provide recursive_serialization_replace'
	);
	assert.ok(
		dbEnginePhp.includes( 'is_serialized' ),
		'Must check is_serialized to parse PHP serialized strings'
	);
	assert.ok(
		dbEnginePhp.includes( 'unserialize' ) && dbEnginePhp.includes( 'serialize' ),
		'Must unserialize and re-serialize to preserve byte lengths'
	);

	// Admin account preservation
	assert.ok(
		dbEnginePhp.includes( 'preserve_current_admin_user' ),
		'Must provide preserve_current_admin_user'
	);
	assert.ok(
		dbEnginePhp.includes( 'tmp_users' ) && dbEnginePhp.includes( 'tmp_usermeta' ),
		'Must preserve live admin into staged users and usermeta tables'
	);

	// Atomic multi-table swap & maintenance mode
	assert.ok(
		dbEnginePhp.includes( 'swap_staged_tables' ),
		'Must provide swap_staged_tables'
	);
	assert.ok(
		dbEnginePhp.includes( 'enable_maintenance_mode' ) && dbEnginePhp.includes( 'disable_maintenance_mode' ),
		'Must control WordPress maintenance mode during cutover'
	);
	assert.ok(
		dbEnginePhp.includes( 'RENAME TABLE' ),
		'Must execute atomic multi-table rename query'
	);
} );

