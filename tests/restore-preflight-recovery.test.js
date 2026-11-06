/**
 * Unit & Contract Tests for MigrateLift Restore Preflight & Recovery Checkpoint (Part 10).
 *
 * Verifies:
 * - REST API endpoints for preflight checks, dry-run reports, recovery checkpoint, and execution gating
 * - Strict authorization / permission_callback enforcement for all restore routes
 * - Acceptance Criterion 1: User can inspect an accurate restore preview and any blockers
 * - Acceptance Criterion 2: Preflight failure stops the operation and does not modify the target
 * - Acceptance Criterion 3: No destructive REST endpoint is enabled in this phase (destructive apply locked)
 * - Acceptance Criterion 4: Recovery checkpoint is verified and protected against retention pruning
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

test( 'REST API declares strict permission callbacks for restore preflight routes (Part 10)', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	const restoreRoutes = [
		'/restore/preflight',
		'/restore/dry-run',
		'/restore/checkpoint',
		'/restore/recovery-status',
		'/restore/execute',
	];

	for ( const route of restoreRoutes ) {
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

test( 'Non-Destructive Gating Contract: Nothing changes unless the request asks to apply', () => {
	const restApiPhp = fs.readFileSync( path.join( __dirname, '../includes/rest-api.php' ), 'utf-8' );

	// Verify /restore/execute returns locked status
	assert.ok(
		restApiPhp.includes( "'status'     => 'preflight_verified'" ) &&
		restApiPhp.includes( "'locked'     => true" ),
		'REST endpoint /restore/execute must not change anything without action=apply'
	);
} );

test( 'Preflight Blocker Contract: Blockers halt restore and prevent execution', () => {
	const preflightPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-restore-preflight.php' ), 'utf-8' );

	assert.ok(
		preflightPhp.includes( 'WordPress Multisite is not supported' ),
		'Preflight must declare fatal blocker for Multisite'
	);
	assert.ok(
		preflightPhp.includes( 'Insufficient disk space' ),
		'Preflight must declare fatal blocker when free space cannot accommodate staging and rollback snapshot'
	);
	assert.ok(
		preflightPhp.includes( 'file permissions error' ),
		'Preflight must declare fatal blocker when destination directory is unwritable'
	);
} );

test( 'Recovery Checkpoint Protection Contract: Checkpoints are marked protected and shielded from pruning', () => {
	const preflightPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-restore-preflight.php' ), 'utf-8' );
	const schedulerPhp = fs.readFileSync( path.join( __dirname, '../includes/class-migrate-lift-scheduler.php' ), 'utf-8' );

	// Preflight registers recovery point option
	assert.ok(
		preflightPhp.includes( 'update_option( self::OPTION_RECOVERY_POINT' ),
		'Preflight engine must store checkpoint in migrate_lift_recovery_point option'
	);

	// Scheduler retention pruning inspects recovery point
	assert.ok(
		schedulerPhp.includes( "get_option( 'migrate_lift_recovery_point' )" ),
		'Scheduler retention pruning must read migrate_lift_recovery_point'
	);
	assert.ok(
		schedulerPhp.includes( '$protected_files[] = (string) $recovery_point' ),
		'Scheduler retention pruning must add recovery checkpoint to protected exclusions'
	);
} );

test( 'Restore Wizard UI Contract: Guided 7 steps and explicit hostname confirmation', () => {
	const wizardJs = fs.readFileSync( path.join( __dirname, '../src/components/restore/RestoreWizard.js' ), 'utf-8' );

	// Verify 7 steps definition
	const expectedSteps = [ 'Select', 'Verify', 'Preflight', 'Strategy', 'Confirm', 'Checkpoint', 'Report' ];
	for ( const step of expectedSteps ) {
		assert.ok(
			wizardJs.includes( step ),
			`Restore Wizard must include step '${ step }'`
		);
	}

	// Verify explicit hostname matching requirement
	assert.ok(
		wizardJs.includes( 'isConfirmationMatched' ),
		'Restore Wizard Step 5 must enforce hostname matching'
	);
	assert.ok(
		wizardJs.includes( 'disabled={ ! isConfirmationMatched }' ),
		'Confirm button must be disabled until exact hostname is typed'
	);
} );

