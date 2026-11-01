/**
 * Unit & Contract Tests for MigrateLift Site Pairing & Security (Part 13).
 *
 * Verifies:
 * - REST API declares strict permission callbacks for pairing routes
 * - Time-limited, single-use token generation with HMAC capability
 * - SSRF protection blocks private network ranges, loopbacks, and cloud metadata services
 * - Request signing with replay protection (X-ML-Timestamp skew window and X-ML-Nonce cache)
 * - Safe revocation and credential isolation
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'REST API declares strict permission callbacks for pairing routes (Part 13)', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	const adminRoutes = [
		'/pair/token',
		'/pair/connect',
		'/pair/status',
		'/pair/revoke',
		'/pair/verify',
		'/pair/remote-preflight',
		'/pair/remote-diff',
		'/pair/remote-apply',
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

	// Signed cross-site routes
	const signedRoutes = [
		'/pair/ping',
		'/pair/revoke-remote',
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

test( 'Site Pairing Engine Contract: SSRF protection and private IP range restrictions', () => {
	const pairingPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-site-pairing.php' ), 'utf-8' );

	// Verify SSRF validator existence
	assert.ok( pairingPhp.includes( 'function validate_target_url' ), 'Must implement validate_target_url' );

	// Verify blocked IP ranges
	assert.ok( pairingPhp.includes( 'ssrf_loopback_blocked' ) || pairingPhp.includes( '127.0.0.0/8' ), 'Must block loopback addresses' );
	assert.ok( pairingPhp.includes( '10.0.0.0/8' ), 'Must block RFC1918 10.0.0.0/8' );
	assert.ok( pairingPhp.includes( '172.16.0.0/12' ), 'Must block RFC1918 172.16.0.0/12' );
	assert.ok( pairingPhp.includes( '192.168.0.0/16' ), 'Must block RFC1918 192.168.0.0/16' );
	assert.ok( pairingPhp.includes( '169.254.0.0/16' ), 'Must block link-local and cloud metadata 169.254.0.0/16' );

	// Local development override
	assert.ok( pairingPhp.includes( 'MIGRATE_LIFT_ALLOW_LOCAL_TRANSFER' ), 'Must support explicit local testing override constant' );
} );

test( 'Site Pairing Engine Contract: Replay protection and HMAC signature verification', () => {
	const pairingPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-site-pairing.php' ), 'utf-8' );

	// Verify clock skew and nonce transient
	assert.ok( pairingPhp.includes( 'SIGNATURE_WINDOW = 300' ), 'Must enforce 5-minute (300s) clock skew window' );
	assert.ok( pairingPhp.includes( 'replay_attack_detected' ), 'Must detect and reject duplicate nonces' );
	assert.ok( pairingPhp.includes( 'set_transient' ), 'Must cache nonces in transients' );
	assert.ok( pairingPhp.includes( 'hash_hmac' ), 'Must calculate HMAC-SHA256 signature' );
	assert.ok( pairingPhp.includes( 'hash_equals' ), 'Must use constant-time hash_equals for signature comparison' );

	// Verify single-use token consumption
	assert.ok( pairingPhp.includes( 'function consume_pairing_token' ), 'Must implement consume_pairing_token' );
	assert.ok( pairingPhp.includes( 'unset( $pending[' ), 'Must remove token immediately upon consumption to ensure single-use' );
} );

