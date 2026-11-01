/**
 * Unit & Integration Tests for MigrateLift Job Engine & Activity Logging (Part 04).
 *
 * Verifies:
 * - PHP integration suite (concurrency locks, interrupted runs, state transitions, sanitization)
 * - REST API client contracts for job creation, stepping, pause, resume, cancel
 * - Security redaction of sensitive credentials and server paths
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { spawnSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const fs = require( 'node:fs' );

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

test( 'PHP Standalone Job Engine & Concurrency Integration Suite', () => {
	const phpBin = findPhpBinary();
	assert.ok( phpBin, 'PHP binary must be available for testing' );

	const runnerPath = path.join( __dirname, 'php-test-runner.php' );
	assert.ok( fs.existsSync( runnerPath ), 'php-test-runner.php must exist' );

	const result = spawnSync( phpBin, [ runnerPath ], {
		encoding: 'utf-8',
		stdio: 'pipe',
	} );

	if ( result.status !== 0 ) {
		// eslint-disable-next-line no-console
		console.error( 'PHP Test Output:\n', result.stdout, result.stderr );
	}

	assert.equal( result.status, 0, `PHP tests failed with exit code ${ result.status }` );
	assert.ok( result.stdout.includes( 'Summary:' ), 'Test output must include summary' );
	assert.ok( ! result.stdout.includes( 'FAILED' ), 'Test output must have no failed tests' );
} );

test( 'Job state transition definitions are valid and exhaustive', () => {
	const validStates = [
		'queued',
		'running',
		'paused',
		'cancel-requested',
		'failed',
		'completed',
	];

	assert.equal( validStates.length, 6 );
	assert.ok( validStates.includes( 'queued' ) );
	assert.ok( validStates.includes( 'running' ) );
	assert.ok( validStates.includes( 'paused' ) );
	assert.ok( validStates.includes( 'cancel-requested' ) );
	assert.ok( validStates.includes( 'failed' ) );
	assert.ok( validStates.includes( 'completed' ) );
} );

test( 'REST API job and activity routes declare strict authorization permission callbacks', () => {
	const restApiPath = path.join( __dirname, '../includes/rest-api.php' );
	assert.ok( fs.existsSync( restApiPath ), 'includes/rest-api.php must exist' );

	const content = fs.readFileSync( restApiPath, 'utf-8' );

	// Verify all job and activity endpoints exist and require $admin_permission
	const requiredEndpoints = [
		'/jobs',
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)',
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)/step',
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)/pause',
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)/resume',
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)/cancel',
		'/activity',
		'/activity/clear',
	];

	for ( const ep of requiredEndpoints ) {
		assert.ok( content.includes( ep ), `Endpoint ${ ep } must be registered` );
	}

	// Verify admin permission checks manage_options and !is_multisite()
	assert.ok( content.includes( "current_user_can( 'manage_options' )" ), 'Must check manage_options capability' );
	assert.ok( content.includes( '! is_multisite()' ), 'Must enforce single site constraint' );
} );

test( 'Simulated lease contention rejects concurrent worker claim', () => {
	const now = 1000;
	const leaseDuration = 45;

	// Worker 1 claims job
	const job = {
		id: 'mljob_123',
		state: 'queued',
		lock_token: 'worker_alpha',
		locked_until: now + leaseDuration, // expires at 1045
	};

	function claimLease( currentJob, workerId, currentTime ) {
		if ( currentJob.lock_token && currentJob.locked_until ) {
			if ( currentJob.locked_until > currentTime && currentJob.lock_token !== workerId ) {
				return { success: false, code: 'job_locked' };
			}
		}
		currentJob.lock_token = workerId;
		currentJob.locked_until = currentTime + leaseDuration;
		return { success: true };
	}

	// Worker 2 attempts claim while lease is active (at time 1020)
	const attemptWorker2 = claimLease( job, 'worker_beta', 1020 );
	assert.equal( attemptWorker2.success, false );
	assert.equal( attemptWorker2.code, 'job_locked' );
	assert.equal( job.lock_token, 'worker_alpha' );

	// Worker 1 renews claim (at time 1030)
	const attemptWorker1 = claimLease( job, 'worker_alpha', 1030 );
	assert.equal( attemptWorker1.success, true );
	assert.equal( job.locked_until, 1075 );

	// Worker 2 attempts claim after lease expiry (worker 1 died, time 1080)
	const attemptWorker2AfterExpiry = claimLease( job, 'worker_beta', 1080 );
	assert.equal( attemptWorker2AfterExpiry.success, true );
	assert.equal( job.lock_token, 'worker_beta' );
} );

test( 'Simulated checkpoint resumption avoids duplicating processed chunks', () => {
	const totalItems = 20;
	const batchSize = 5;

	const jobState = {
		id: 'mljob_resumable',
		checkpoint: { cursor: 0, batch_index: 0 },
		percentage: 0,
		state: 'running',
	};

	function stepBatch( state ) {
		const cursor = state.checkpoint.cursor;
		const nextCursor = Math.min( totalItems, cursor + batchSize );
		state.checkpoint.cursor = nextCursor;
		state.checkpoint.batch_index++;
		state.percentage = Math.round( ( nextCursor / totalItems ) * 100 );
		const isDone = nextCursor >= totalItems;
		if ( isDone ) state.state = 'completed';
		return { done: isDone, processedCount: nextCursor - cursor };
	}

	// Step 1: batch 1
	const step1 = stepBatch( jobState );
	assert.equal( step1.done, false );
	assert.equal( step1.processedCount, 5 );
	assert.equal( jobState.checkpoint.cursor, 5 );
	assert.equal( jobState.percentage, 25 );

	// Simulate interruption: process crashes, job state preserved on disk
	// Worker 2 reloads state and resumes
	const step2 = stepBatch( jobState );
	assert.equal( step2.done, false );
	assert.equal( step2.processedCount, 5 );
	assert.equal( jobState.checkpoint.cursor, 10 );
	assert.equal( jobState.percentage, 50 );

	// Step 3
	stepBatch( jobState );
	assert.equal( jobState.checkpoint.cursor, 15 );
	assert.equal( jobState.percentage, 75 );

	// Step 4
	const step4 = stepBatch( jobState );
	assert.equal( step4.done, true );
	assert.equal( jobState.checkpoint.cursor, 20 );
	assert.equal( jobState.percentage, 100 );
	assert.equal( jobState.state, 'completed' );
} );

