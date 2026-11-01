/**
 * Unit Tests for MigrateLift Dashboard Data Contracts & Adapter
 *
 * Tests:
 * - Empty / partial API responses handling and normalization
 * - Relative time and byte size formatting
 * - KPI and 7-day health calculations
 * - Schema validation for DashboardSummary
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

// Load the normalization and formatting logic
// We test the business logic directly to guarantee robustness across environments
const {
	normalizeSummary,
	formatRelativeTime,
	PREVIEW_FIXTURE,
} = require( '../src/utils/dashboardApi.js' );
const { formatBytes } = require( '../src/utils/api.js' );

test( 'formatBytes formats standard SI/IEC units correctly', () => {
	assert.equal( formatBytes( 0 ), '0 B' );
	assert.equal( formatBytes( -50 ), '0 B' );
	assert.equal( formatBytes( null ), '0 B' );
	assert.equal( formatBytes( undefined ), '0 B' );
	assert.equal( formatBytes( 500 ), '500 B' );
	assert.equal( formatBytes( 1024 ), '1.0 KB' );
	assert.equal( formatBytes( 1048576 ), '1.0 MB' );
	assert.equal( formatBytes( 256901120 ), '245.0 MB' );
	assert.equal( formatBytes( 1073741824 ), '1.0 GB' );
} );

test( 'formatRelativeTime handles null, invalid, and relative timestamps', () => {
	assert.equal( formatRelativeTime( null ), '—' );
	assert.equal( formatRelativeTime( undefined ), '—' );
	assert.equal( formatRelativeTime( 0 ), '—' );
	assert.equal( formatRelativeTime( -10 ), '—' );
	assert.equal( formatRelativeTime( 'invalid' ), '—' );

	const now = Math.floor( Date.now() / 1000 );
	assert.equal( formatRelativeTime( now - 10 ), 'Just now' );
	assert.equal( formatRelativeTime( now - 120 ), '2 mins ago' );
	assert.equal( formatRelativeTime( now - 7200 ), '2 hrs ago' );
	assert.equal( formatRelativeTime( now - 172800 ), '2 days ago' );
} );

test( 'normalizeSummary handles completely empty API payload without crashing', () => {
	const result = normalizeSummary( {} );

	// Site defaults
	assert.ok( result.site );
	assert.equal( typeof result.site.url, 'string' );
	assert.equal( typeof result.site.name, 'string' );
	assert.equal( result.site.isMultisite, false );

	// System status defaults
	assert.ok( result.systemStatus );
	assert.equal( result.systemStatus.storageWritable, true );
	assert.equal( result.systemStatus.statusType, 'ready' );
	assert.equal( result.systemStatus.schedulerActive, false );

	// KPIs defaults
	assert.ok( result.kpis );
	assert.equal( result.kpis.lastBackup, null );
	assert.equal( result.kpis.nextScheduled, null );
	assert.equal( result.kpis.storedBackupsCount, 0 );
	assert.equal( result.kpis.totalStorageBytes, 0 );

	// 7-day health
	assert.ok( result.backupHealth );
	assert.equal( result.backupHealth.range, '7d' );
	assert.equal( result.backupHealth.days.length, 7 );
	assert.equal( result.backupHealth.hasActivity, false );

	// Backups and jobs
	assert.deepEqual( result.recentBackups, [] );
	assert.equal( result.migrationJob, null );

	// Protection settings defaults
	assert.ok( result.protectionSettings );
	assert.equal( result.protectionSettings.scheduleEnabled, false );
	assert.equal( result.protectionSettings.retentionCopies, 7 );
	assert.equal( result.protectionSettings.exclusionsConfigured, true );

	// Activity
	assert.deepEqual( result.recentActivity, [] );
} );

test( 'normalizeSummary parses partial response with archives list', () => {
	const partial = {
		archives: [
			{
				name: 'migrate-lift-2026-10-31.tgz',
				size: 15728640,
				time: 1793448000,
				date: 'Oct 31, 2026 12:00',
				download: 'https://example.com/download.tgz',
			},
		],
	};

	const result = normalizeSummary( partial );

	assert.equal( result.kpis.storedBackupsCount, 1 );
	assert.equal( result.kpis.totalStorageBytes, 15728640 );
	assert.ok( result.kpis.lastBackup );
	assert.equal( result.kpis.lastBackup.file, 'migrate-lift-2026-10-31.tgz' );
	assert.equal( result.kpis.lastBackup.size, 15728640 );
	assert.equal( result.kpis.lastBackup.verified, true );
	assert.equal( result.recentBackups.length, 1 );
} );

test( 'normalizeSummary preserves complete server payload', () => {
	const payload = {
		site: {
			url: 'https://staging.example.com',
			name: 'Staging Site',
			wpVersion: '6.7.1',
			timezone: 'America/New_York',
			isMultisite: false,
		},
		systemStatus: {
			storageWritable: true,
			atomicTableSwapSupported: true,
			schedulerActive: false,
			statusLabel: 'Site ready',
			statusType: 'ready',
			phpVersion: '8.2.29',
		},
		kpis: {
			lastBackup: {
				file: 'staging-snap.tgz',
				name: 'staging-snap.tgz',
				size: 52428800,
				time: 1793440000,
				date: 'Oct 31, 2026 10:00',
				verified: true,
				type: 'full',
			},
			nextScheduled: null,
			storedBackupsCount: 1,
			totalStorageBytes: 52428800,
		},
		backupHealth: {
			range: '7d',
			days: [
				{ day: 'Mon', date: '2026-10-27', count: 0, totalBytes: 0, percentage: 0 },
				{ day: 'Tue', date: '2026-10-28', count: 1, totalBytes: 52428800, percentage: 100 },
			],
			hasActivity: true,
		},
		recentBackups: [
			{
				file: 'staging-snap.tgz',
				name: 'staging-snap.tgz',
				size: 52428800,
				time: 1793440000,
				date: 'Oct 31, 2026 10:00',
				verified: true,
				type: 'full',
			},
		],
		migrationJob: null,
		protectionSettings: {
			scheduleEnabled: false,
			scheduleFrequency: null,
			retentionCopies: 7,
			compressionMode: 'Standard gzip (.tgz)',
			exclusionsConfigured: true,
			lastIntegrityCheck: 'Oct 31, 2026 10:00',
		},
		recentActivity: [
			{
				id: 'act-1',
				timestamp: 'Oct 31, 2026 10:00',
				time: 1793440000,
				event: 'Backup snapshot stored: staging-snap.tgz',
				level: 'success',
			},
		],
	};

	const result = normalizeSummary( payload );

	assert.equal( result.site.url, 'https://staging.example.com' );
	assert.equal( result.site.name, 'Staging Site' );
	assert.equal( result.systemStatus.statusType, 'ready' );
	assert.equal( result.kpis.storedBackupsCount, 1 );
	assert.equal( result.kpis.totalStorageBytes, 52428800 );
	assert.equal( result.backupHealth.hasActivity, true );
	assert.equal( result.recentBackups.length, 1 );
	assert.equal( result.recentActivity.length, 1 );
} );

test( 'PREVIEW_FIXTURE strictly conforms to DashboardSummary contract', () => {
	assert.ok( PREVIEW_FIXTURE.site.url );
	assert.ok( PREVIEW_FIXTURE.systemStatus.statusLabel );
	assert.ok( PREVIEW_FIXTURE.kpis.lastBackup );
	assert.equal( PREVIEW_FIXTURE.backupHealth.days.length, 7 );
	assert.equal( PREVIEW_FIXTURE.backupHealth.hasActivity, true );
	assert.ok( PREVIEW_FIXTURE.recentBackups.length > 0 );
	assert.ok( PREVIEW_FIXTURE.protectionSettings.retentionCopies > 0 );
	assert.ok( PREVIEW_FIXTURE.recentActivity.length > 0 );
} );

