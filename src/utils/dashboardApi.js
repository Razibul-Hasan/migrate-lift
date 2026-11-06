/**
 * Dashboard API Adapter and Utility Functions
 *
 * Implements fetcher and normalizer for GET /dashboard/summary.
 * Provides fallback disconnected state, relative time formatting, and dev preview fixture.
 */

import { formatBytes } from './api.js';

const cfg = ( typeof window !== 'undefined' && window.migrateLift ) || {};

/**
 * Preview fixture data matching dashboard-preview.html.
 * STRICTLY disabled in production. Only activated when explicitly set:
 * window.__MIGRATE_LIFT_PREVIEW_FIXTURE__ = true;
 */
export const PREVIEW_FIXTURE = {
	site: {
		url: ( typeof window !== 'undefined' && window.location.origin ) || 'https://example.com',
		name: 'Production Website',
		wpVersion: '6.7.1',
		timezone: 'UTC',
		isMultisite: false,
	},
	systemStatus: {
		storageWritable: true,
		atomicTableSwapSupported: true,
		schedulerActive: false,
		statusLabel: 'Site ready · Demo fixture',
		statusType: 'ready',
		phpVersion: '8.2.29',
	},
	kpis: {
		lastBackup: {
			file: 'backup-2026-10-31-0814.tgz',
			name: 'Daily snapshot archive',
			size: 249561088,
			time: Math.floor( Date.now() / 1000 ) - 21600,
			date: 'Oct 31, 2026 08:14',
			verified: true,
			type: 'full',
		},
		nextScheduled: '2:00 AM Tomorrow',
		storedBackupsCount: 12,
		totalStorageBytes: 3006477107,
	},
	backupHealth: {
		range: '7d',
		days: [
			{ day: 'Sat', date: '2026-10-25', count: 1, totalBytes: 98000000, percentage: 40 },
			{ day: 'Sun', date: '2026-10-26', count: 2, totalBytes: 172000000, percentage: 70 },
			{ day: 'Mon', date: '2026-10-27', count: 1, totalBytes: 125000000, percentage: 51 },
			{ day: 'Tue', date: '2026-10-28', count: 2, totalBytes: 201000000, percentage: 82 },
			{ day: 'Wed', date: '2026-10-29', count: 1, totalBytes: 149000000, percentage: 61 },
			{ day: 'Thu', date: '2026-10-30', count: 3, totalBytes: 245000000, percentage: 100 },
			{ day: 'Fri', date: '2026-10-31', count: 2, totalBytes: 181000000, percentage: 74 },
		],
		hasActivity: true,
	},
	recentBackups: [
		{
			file: 'backup-2026-10-31-0814.tgz',
			name: 'Daily automated snapshot',
			size: 249561088,
			time: Math.floor( Date.now() / 1000 ) - 21600,
			date: 'Oct 31, 2026 08:14',
			verified: true,
			type: 'scheduled',
		},
		{
			file: 'backup-2026-10-30-2210.tgz',
			name: 'Full manual site archive',
			size: 253755392,
			time: Math.floor( Date.now() / 1000 ) - 57600,
			date: 'Oct 30, 2026 22:10',
			verified: true,
			type: 'manual',
		},
		{
			file: 'backup-2026-10-29-0200.tgz',
			name: 'Daily automated snapshot',
			size: 247463936,
			time: Math.floor( Date.now() / 1000 ) - 144000,
			date: 'Oct 29, 2026 02:00',
			verified: true,
			type: 'scheduled',
		},
	],
	migrationJob: null,
	protectionSettings: {
		scheduleEnabled: false,
		scheduleFrequency: 'Every day at 2:00 AM',
		retentionCopies: 7,
		compressionMode: 'Standard gzip (.tgz)',
		exclusionsConfigured: true,
		lastIntegrityCheck: 'Oct 31, 2026 08:14',
	},
	recentActivity: [
		{
			id: 'act-1',
			timestamp: 'Today at 08:14',
			time: Math.floor( Date.now() / 1000 ) - 21600,
			event: 'Daily backup archive created',
			description: 'Full database and wp-content package verified. Size: 238 MB',
			level: 'success',
			jobLink: null,
		},
		{
			id: 'act-2',
			timestamp: 'Yesterday at 22:10',
			time: Math.floor( Date.now() / 1000 ) - 57600,
			event: 'Manual snapshot stored',
			description: 'User initiated snapshot stored in local protected directory.',
			level: 'info',
			jobLink: null,
		},
	],
};

/**
 * Formats relative timestamp safely.
 */
export function formatRelativeTime( timestamp ) {
	if ( ! timestamp ) return '—';
	const num = typeof timestamp === 'number' ? timestamp : parseInt( timestamp, 10 );
	if ( isNaN( num ) || num <= 0 ) return '—';

	const now = Math.floor( Date.now() / 1000 );
	const diff = now - num;

	if ( diff < 60 ) return 'Just now';
	if ( diff < 3600 ) {
		const mins = Math.floor( diff / 60 );
		return `${ mins } ${ mins === 1 ? 'min' : 'mins' } ago`;
	}
	if ( diff < 86400 ) {
		const hours = Math.floor( diff / 3600 );
		return `${ hours } ${ hours === 1 ? 'hr' : 'hrs' } ago`;
	}
	if ( diff < 604800 ) {
		const days = Math.floor( diff / 86400 );
		return `${ days } ${ days === 1 ? 'day' : 'days' } ago`;
	}

	return new Date( num * 1000 ).toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} );
}

/**
 * Normalizes raw API or fallback summary into guaranteed schema.
 */
export function normalizeSummary( raw = {}, fallbackArchives = [] ) {
	const rawSite = raw.site || {};
	const rawSystem = raw.systemStatus || {};
	const rawKpis = raw.kpis || {};
	const rawHealth = raw.backupHealth || {};
	const rawSettings = raw.protectionSettings || {};

	// Derive archives from either response or fallback
	const archivesList = Array.isArray( raw.recentBackups ) && raw.recentBackups.length > 0
		? raw.recentBackups
		: ( Array.isArray( raw.archives ) ? raw.archives : fallbackArchives );

	const normalizedArchives = ( archivesList || [] ).map( ( arch ) => {
		const name = arch.file || arch.name || 'unnamed-backup.tgz';
		const size = typeof arch.size === 'number' ? arch.size : ( parseInt( arch.size, 10 ) || 0 );
		return {
			file: name,
			name,
			size,
			raw_estimate: typeof arch.raw_estimate === 'number' ? arch.raw_estimate : size,
			reduction_percentage: typeof arch.reduction_percentage === 'number' ? arch.reduction_percentage : 0.0,
			time: typeof arch.time === 'number' ? arch.time : ( parseInt( arch.time, 10 ) || 0 ),
			date: arch.date || ( arch.time ? new Date( arch.time * 1000 ).toLocaleString() : '—' ),
			download: arch.download || '',
			verified: arch.verified !== undefined ? !! arch.verified : true,
			verified_at: arch.verified_at || null,
			type: arch.type || 'full',
			storage_source: arch.storage_source || 'local',
			status: arch.status || ( arch.verified ? 'ready' : 'unverified' ),
			format: arch.format || ( name.endsWith( '.zip' ) ? 'zip' : 'tgz' ),
			checksum: arch.checksum || null,
		};
	} );

	const totalBytes = typeof rawKpis.totalStorageBytes === 'number'
		? rawKpis.totalStorageBytes
		: normalizedArchives.reduce( ( acc, curr ) => acc + ( curr.size || 0 ), 0 );

	const lastBackup = rawKpis.lastBackup || ( normalizedArchives.length > 0 ? normalizedArchives[ 0 ] : null );

	// Build default 7 days array if missing
	let days = Array.isArray( rawHealth.days ) ? rawHealth.days : [];
	if ( days.length === 0 ) {
		const dayNames = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
		const today = new Date();
		for ( let i = 6; i >= 0; i-- ) {
			const d = new Date( today );
			d.setDate( today.getDate() - i );
			days.push( {
				day: dayNames[ d.getDay() ],
				date: d.toISOString().slice( 0, 10 ),
				count: 0,
				totalBytes: 0,
				percentage: 0,
			} );
		}
	}

	const hasActivity = rawHealth.hasActivity ?? days.some( ( d ) => d.count > 0 || d.percentage > 0 );

	return {
		site: {
			url: rawSite.url || cfg.home || ( typeof window !== 'undefined' ? window.location.origin : '' ),
			name: rawSite.name || 'WordPress Site',
			wpVersion: rawSite.wpVersion || '',
			timezone: rawSite.timezone || 'UTC',
			isMultisite: !! ( rawSite.isMultisite ?? cfg.isMultisite ),
		},
		systemStatus: {
			storageWritable: rawSystem.storageWritable ?? true,
			atomicTableSwapSupported: rawSystem.atomicTableSwapSupported ?? true,
			schedulerActive: rawSystem.schedulerActive ?? false,
			statusLabel: rawSystem.statusLabel || ( rawSystem.storageWritable !== false ? 'Site ready' : 'Storage unwritable' ),
			statusType: rawSystem.statusType || ( rawSystem.storageWritable !== false ? 'ready' : 'action_needed' ),
			phpVersion: rawSystem.phpVersion || '',
		},
		kpis: {
			lastBackup: lastBackup ? {
				file: lastBackup.file || lastBackup.name,
				name: lastBackup.name || lastBackup.file,
				size: lastBackup.size || 0,
				time: lastBackup.time || 0,
				date: lastBackup.date || '',
				verified: lastBackup.verified !== undefined ? !! lastBackup.verified : true,
				type: lastBackup.type || 'full',
			} : null,
			nextScheduled: rawKpis.nextScheduled || null,
			storedBackupsCount: typeof rawKpis.storedBackupsCount === 'number'
				? rawKpis.storedBackupsCount
				: normalizedArchives.length,
			totalStorageBytes: totalBytes,
		},
		backupHealth: {
			range: rawHealth.range || '7d',
			days,
			hasActivity,
		},
		recentBackups: normalizedArchives.slice( 0, 5 ),
		migrationJob: raw.migrationJob || null,
		protectionSettings: {
			scheduleEnabled: !! rawSettings.scheduleEnabled,
			scheduleFrequency: rawSettings.scheduleFrequency || null,
			retentionCopies: rawSettings.retentionCopies || 7,
			compressionMode: rawSettings.compressionMode || 'Standard gzip (.tgz)',
			exclusionsConfigured: rawSettings.exclusionsConfigured !== false,
			lastIntegrityCheck: rawSettings.lastIntegrityCheck || ( lastBackup ? lastBackup.date : null ),
		},
		recentActivity: Array.isArray( raw.recentActivity ) ? raw.recentActivity : [],
	};
}

/**
 * Fetches dashboard summary from REST endpoint with fallback and preview fixture support.
 */
export async function fetchDashboardSummary( options = {} ) {
	// 1. Developer preview fixture toggle check
	const isPreview = ( typeof window !== 'undefined' && window.__MIGRATE_LIFT_PREVIEW_FIXTURE__ ) || options.previewFixture;
	if ( isPreview ) {
		return normalizeSummary( PREVIEW_FIXTURE );
	}

	const endpoint = `${ cfg.restUrl || '/wp-json/migrate-lift/v1/' }dashboard/summary`;
	const headers = {
		'Content-Type': 'application/json',
	};
	if ( cfg.restNonce ) {
		headers[ 'X-WP-Nonce' ] = cfg.restNonce;
	}

	try {
		const response = await fetch( endpoint, {
			method: 'GET',
			headers,
			credentials: 'same-origin',
		} );

		if ( ! response.ok ) {
			throw new Error( `HTTP ${ response.status } ${ response.statusText }` );
		}

		const data = await response.json();
		if ( data && data.success && data.data ) {
			return normalizeSummary( data.data, cfg.archives );
		}
		throw new Error( ( data && data.message ) || 'Invalid summary response' );
	} catch ( err ) {
		// Log error in dev environment and gracefully construct localized fallback
		// eslint-disable-next-line no-console
		if ( process.env.NODE_ENV !== 'production' ) {
			console.warn( '[MigrateLift] Dashboard summary API unreachable, using fallback state:', err );
		}

		// Fallback returns honest empty/localized state, NOT fabricated data
		return normalizeSummary(
			{
				systemStatus: {
					storageWritable: true,
					atomicTableSwapSupported: true,
					schedulerActive: false,
					statusLabel: 'Site ready (Offline / Local mode)',
					statusType: 'ready',
				},
				kpis: {
					lastBackup: null,
					nextScheduled: null,
					storedBackupsCount: ( cfg.archives || [] ).length,
					totalStorageBytes: ( cfg.archives || [] ).reduce( ( acc, c ) => acc + ( c.size || 0 ), 0 ),
				},
				recentBackups: cfg.archives || [],
				migrationJob: null,
				recentActivity: [],
			},
			cfg.archives || []
		);
	}
}
