/**
 * TypeScript Data Contracts for MigrateLift Dashboard
 *
 * Defines the data contracts for dashboard summaries, backups, schedule status,
 * migration jobs, and audit event logs.
 */

export interface SiteInfo {
	url: string;
	name: string;
	wpVersion: string;
	timezone: string;
	isMultisite: boolean;
}

export interface SystemStatus {
	storageWritable: boolean;
	atomicTableSwapSupported: boolean;
	schedulerActive: boolean;
	statusLabel: string;
	statusType: 'ready' | 'action_needed' | 'warning';
	phpVersion?: string;
}

export interface BackupItem {
	file: string;
	name: string;
	size: number;
	time: number;
	date: string;
	download?: string;
	verified?: boolean;
	type?: 'full' | 'manual' | 'scheduled';
}

export interface DashboardKPIs {
	lastBackup: BackupItem | null;
	nextScheduled: string | null;
	storedBackupsCount: number;
	totalStorageBytes: number;
}

export interface DayActivity {
	day: string;
	date: string;
	count: number;
	totalBytes: number;
	percentage: number;
}

export interface BackupHealth {
	range: string;
	days: DayActivity[];
	hasActivity: boolean;
	note?: string;
}

export type MigrationJobStatus =
	| 'not_started'
	| 'preflight'
	| 'transferring'
	| 'verifying'
	| 'completed'
	| 'failed';

export interface MigrationJobSummary {
	id: string;
	status: MigrationJobStatus;
	sourceUrl: string;
	destinationUrl: string | null;
	progress: number;
	currentStep: string | null;
	startedAt: string | null;
	error: string | null;
}

export interface ProtectionSettingsSummary {
	scheduleEnabled: boolean;
	scheduleFrequency: string | null;
	retentionCopies: number;
	compressionMode: string;
	exclusionsConfigured: boolean;
	lastIntegrityCheck: string | null;
}

export interface ActivityEvent {
	id: string;
	timestamp: string;
	time: number;
	event: string;
	description?: string;
	level: 'info' | 'success' | 'warning' | 'error';
	jobLink?: string | null;
}

export interface DashboardSummary {
	site: SiteInfo;
	systemStatus: SystemStatus;
	kpis: DashboardKPIs;
	backupHealth: BackupHealth;
	recentBackups: BackupItem[];
	migrationJob: MigrationJobSummary | null;
	protectionSettings: ProtectionSettingsSummary;
	recentActivity: ActivityEvent[];
}

