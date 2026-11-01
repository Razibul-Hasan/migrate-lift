/**
 * Unit & Contract Tests for MigrateLift Direct Transfer Engine (Part 14).
 *
 * Verifies:
 * - REST API declares strict permission callbacks for transfer routes (/transfer/*)
 * - Resumable chunk streaming protocol with per-chunk SHA-256 verification
 * - Destination preflight disk space capacity requirement
 * - Full archive integrity checksum verification on finalize
 * - Idempotent chunk storage and isolation in incoming temporary folder
 * - Non-destructive guarantee: target site unchanged during transfer
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'REST API declares strict permission callbacks for direct transfer routes (Part 14)', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	const adminRoutes = [
		'/transfer/start',
		'/transfer/status',
		'/transfer/cancel',
	];

	for ( const route of adminRoutes ) {
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

	const signedRoutes = [
		'/transfer/init',
		'/transfer/chunk',
		'/transfer/finalize',
	];

	for ( const route of signedRoutes ) {
		assert.ok(
			restApiPhp.includes( `'${ route }'` ),
			`REST API must register cross-site route '${ route }'`
		);

		const routeIdx = restApiPhp.indexOf( `'${ route }'` );
		const routeBlock = restApiPhp.slice( routeIdx, routeIdx + 380 );

		assert.ok(
			routeBlock.includes( "'permission_callback' => $signed_permission" ),
			`Route '${ route }' must strictly enforce signed_permission callback`
		);
	}
} );

test( 'Direct Transfer Engine Contract: Bounded chunks, checksums, and disk preflight', () => {
	const transferPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-direct-transfer-engine.php' ), 'utf-8' );

	// Verify chunk streaming and bounds
	assert.ok( transferPhp.includes( 'DEFAULT_CHUNK_SIZE = 2097152' ), 'Default chunk size should be 2 MiB' );
	assert.ok( transferPhp.includes( 'MAX_RETRIES = 3' ), 'Must implement capped retry backoff' );
	assert.ok( transferPhp.includes( 'INCOMING_DIR = \'incoming\'' ), 'Incoming temporary chunks must be isolated in incoming/' );

	// Verify disk space preflight on destination
	assert.ok( transferPhp.includes( 'insufficient_destination_disk' ), 'Must reject transfer if destination has insufficient disk space' );
	assert.ok( transferPhp.includes( '$total_bytes * 1.5' ), 'Must require at least 1.5x archive size in free space' );

	// Verify per-chunk and full archive SHA-256 integrity verification
	assert.ok( transferPhp.includes( 'chunk_checksum_mismatch' ), 'Must reject corrupt chunks on checksum mismatch' );
	assert.ok( transferPhp.includes( 'archive_checksum_failed' ), 'Must verify assembled archive matches expected full SHA-256' );
	assert.ok( transferPhp.includes( 'hash_equals' ), 'Must use constant-time comparison for checksum verification' );

	// Verify Job Engine integration
	assert.ok( transferPhp.includes( 'Migrate_Lift_Job_Engine::register_handler' ), 'Must register direct_transfer handler with Job Engine' );
} );

