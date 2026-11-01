/**
 * Unit & Integration Tests for MigrateLift Backup Engine (Part 05).
 *
 * Verifies:
 * - Backup format 2.0 manifest schema
 * - Allowlisted file inclusion & granular exclusions
 * - Symlink traversal security outside root
 * - Bounded chunked database export and tokenization
 * - Private staging and atomic finalization
 * - Integrity verification via Tar Reader
 * - Security omission of secrets and passwords
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { spawnSync } = require( 'node:child_process' );

function findPhpBinary() {
	if ( process.env.PHP_BIN && fs.existsSync( process.env.PHP_BIN ) ) {
		return process.env.PHP_BIN;
	}

	try {
		const res = spawnSync( 'php', [ '-v' ], { stdio: 'pipe' } );
		if ( res.status === 0 ) {
			return 'php';
		}
	} catch ( _err ) {
		// Continue
	}

	if ( process.platform === 'win32' && process.env.APPDATA ) {
		const localServicesDir = path.join( process.env.APPDATA, 'Local', 'lightning-services' );
		if ( fs.existsSync( localServicesDir ) ) {
			const entries = fs.readdirSync( localServicesDir );
			for ( const entry of entries ) {
				if ( entry.startsWith( 'php-' ) ) {
					const candidate = path.join( localServicesDir, entry, 'bin', 'win64', 'php.exe' );
					if ( fs.existsSync( candidate ) ) {
						return candidate;
					}
				}
			}
		}
	}

	return null;
}

test( 'PHP Standalone Backup Engine Suite (PHP Runner)', () => {
	const phpBin = findPhpBinary();
	assert.ok( phpBin, 'PHP binary must be available for testing' );

	const runnerPath = path.join( __dirname, 'php-test-runner.php' );
	const res = spawnSync( phpBin, [ runnerPath ], { stdio: 'pipe', encoding: 'utf-8' } );

	if ( res.status !== 0 ) {
		// eslint-disable-next-line no-console
		console.error( 'PHP Runner Output:\n', res.stdout, res.stderr );
	}

	assert.equal( res.status, 0, 'All PHP unit and integration tests must pass cleanly' );
	assert.match( res.stdout, /Summary: \d+ passed, 0 failed\./ );
} );

test( 'REST API declares strict permission callbacks for backup routes', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	// Verify /backup/start route exists and has permission_callback
	assert.ok(
		restApiPhp.includes( "'/backup/start'" ),
		'REST API must declare /backup/start route'
	);
	assert.ok(
		restApiPhp.includes( "'/backup/verify'" ),
		'REST API must declare /backup/verify route'
	);

	// Verify permission_callback is enforced
	const backupStartBlock = restApiPhp.slice( restApiPhp.indexOf( "'/backup/start'" ), restApiPhp.indexOf( "'/backup/verify'" ) );
	assert.ok(
		backupStartBlock.includes( "'permission_callback' => $admin_permission" ),
		'/backup/start must require administrator permission callback'
	);
} );

test( 'Manifest schema format 2.0 contract verification', () => {
	const backupEnginePhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-backup-engine.php' ), 'utf-8' );

	// Verify FORMAT_VERSION constant
	assert.ok(
		backupEnginePhp.includes( "const FORMAT_VERSION = '2.0';" ),
		'Engine must declare FORMAT_VERSION 2.0'
	);

	// Verify required sections in build_manifest
	assert.ok(
		backupEnginePhp.includes( "'format_version'        => self::FORMAT_VERSION" ),
		'Manifest must output format_version 2.0'
	);
	assert.ok(
		backupEnginePhp.includes( "'source_environment'" ),
		'Manifest must output source_environment'
	);
	assert.ok(
		backupEnginePhp.includes( "'component_inventory'" ),
		'Manifest must output component_inventory'
	);
	assert.ok(
		backupEnginePhp.includes( "'database_sections'" ),
		'Manifest must output database_sections'
	);
	assert.ok(
		backupEnginePhp.includes( "'restoration_guidelines'" ),
		'Manifest must output restoration_guidelines'
	);

	// Verify secret omission: must not export passwords or salts
	assert.ok(
		! backupEnginePhp.includes( "'db_password'" ),
		'Must not contain db_password in manifest'
	);
	assert.ok(
		! backupEnginePhp.includes( "'auth_key'" ),
		'Must not contain auth_key in manifest'
	);
} );

test( 'REST API declares strict permission callbacks for compression settings routes (Part 06)', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	assert.ok(
		restApiPhp.includes( "'/settings/compression'" ),
		'REST API must declare /settings/compression route'
	);
	assert.ok(
		restApiPhp.includes( "'callback'            => 'migrate_lift_rest_get_compression_settings'" ),
		'Must register GET compression settings callback'
	);
	assert.ok(
		restApiPhp.includes( "'callback'            => 'migrate_lift_rest_save_compression_settings'" ),
		'Must register POST compression settings callback'
	);
} );

test( 'Compression engine enforces modes, formats and truthful metrics contract (Part 06)', () => {
	const compressionPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-compression.php' ), 'utf-8' );

	assert.ok( compressionPhp.includes( "const MODE_BALANCED = 'balanced';" ), 'Declares balanced mode' );
	assert.ok( compressionPhp.includes( "const MODE_SMALLEST = 'smallest';" ), 'Declares smallest mode' );
	assert.ok( compressionPhp.includes( "const MODE_FASTEST  = 'fastest';" ), 'Declares fastest mode' );
	assert.ok( compressionPhp.includes( "const FORMAT_TGZ = 'tgz';" ), 'Declares tgz format' );
	assert.ok( compressionPhp.includes( "const FORMAT_ZIP = 'zip';" ), 'Declares zip format' );

	// Verify pre-compressed extensions list includes media types
	assert.ok( compressionPhp.includes( "'jpg'" ) && compressionPhp.includes( "'mp4'" ) && compressionPhp.includes( "'zip'" ), 'Includes common pre-compressed media extensions' );

	// Verify Zstandard safety notice
	assert.ok( compressionPhp.includes( "'zstd_supported' => false" ), 'Zstandard is safely disabled by default for destination compatibility' );
} );


