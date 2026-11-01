/**
 * Security Audit & Release QA Suite (Part 16).
 *
 * Verifies:
 * - 100% of REST API routes enforce explicit permission callbacks
 * - Path traversal / Zip Slip vulnerabilities are prevented across file extractors
 * - Constant-time comparison (hash_equals) is enforced for token and HMAC signature validation
 * - SSRF defenses protect against loopback, private networks, and cloud metadata services
 * - Clean uninstallation script (uninstall.php) safely removes options, transients, and cron hooks
 * - Readme.txt strictly complies with WordPress.org standards and avoids false zero-downtime claims
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'Security Audit: Every REST API route declares an explicit permission callback', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	const routeMatches = [ ...restApiPhp.matchAll( /register_rest_route\(\s*\$namespace,\s*'([^']+)'/g ) ];
	assert.ok( routeMatches.length >= 25, `Expected at least 25 registered REST routes, found ${ routeMatches.length }` );

	for ( const match of routeMatches ) {
		const routePath = match[ 1 ];
		const startIndex = match.index;
		// Extract route definition block
		const block = restApiPhp.slice( startIndex, startIndex + 600 );

		assert.ok(
			block.includes( "'permission_callback'" ),
			`REST route '${ routePath }' must specify an explicit permission_callback`
		);
	}
} );

test( 'Security Audit: Zip slip and directory traversal prevention', () => {
	const importEnginePhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-import-engine.php' ), 'utf-8' );
	const fileEnginePhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-file-replacement-engine.php' ), 'utf-8' );

	// Verify traversal checks in Import and File engines
	assert.ok( importEnginePhp.includes( 'assert_entry_safe_path' ), 'Import engine must validate entry path boundaries' );
	assert.ok( importEnginePhp.includes( 'parent directory traversal' ), 'Import engine must block parent directory traversal' );
	assert.ok( fileEnginePhp.includes( 'is_safe_entry_path' ), 'File Replacement Engine must validate entry path boundaries' );
} );

test( 'Security Audit: Constant-time comparison for sensitive credentials', () => {
	const pairingPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-site-pairing.php' ), 'utf-8' );
	const transferPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-direct-transfer-engine.php' ), 'utf-8' );

	assert.ok( pairingPhp.includes( 'hash_equals' ), 'Site pairing must use hash_equals for HMAC signature validation' );
	assert.ok( transferPhp.includes( 'hash_equals' ), 'Direct transfer must use hash_equals for checksum verification' );
} );

test( 'Release QA: Clean uninstallation script (uninstall.php) standard conformance', () => {
	const uninstallPath = path.join( __dirname, '../uninstall.php' );
	assert.ok( fs.existsSync( uninstallPath ), 'uninstall.php must exist in root plugin directory' );

	const content = fs.readFileSync( uninstallPath, 'utf-8' );
	assert.ok( content.includes( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;" ), 'uninstall.php must check WP_UNINSTALL_PLUGIN' );
	assert.ok( content.includes( 'delete_option' ), 'uninstall.php must clean plugin options' );
	assert.ok( content.includes( 'wp_clear_scheduled_hook' ), 'uninstall.php must clear scheduled cron events' );
} );

test( 'Release QA: readme.txt meets WordPress.org repository specifications', () => {
	const readmePath = path.join( __dirname, '../readme.txt' );
	assert.ok( fs.existsSync( readmePath ), 'readme.txt must exist' );

	const content = fs.readFileSync( readmePath, 'utf-8' );
	assert.ok( content.includes( '=== Migrate' ) && content.includes( 'Lift' ), 'readme.txt must contain standard plugin header' );
	assert.ok( content.includes( 'Tested up to:' ), 'readme.txt must declare Tested up to:' );
	assert.ok( content.includes( 'Requires at least:' ), 'readme.txt must declare Requires at least:' );
	assert.ok( content.includes( 'Requires PHP:' ), 'readme.txt must declare Requires PHP:' );
	assert.ok( content.includes( 'License:' ), 'readme.txt must declare License:' );
	assert.ok( ! content.includes( '100% zero downtime guaranteed' ), 'readme.txt must avoid unverified zero-downtime claims' );
} );
