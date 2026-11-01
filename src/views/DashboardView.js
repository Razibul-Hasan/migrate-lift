/**
 * DashboardView Component
 * Calm, minimal overview of the site's protection state.
 *
 * Visual hierarchy:
 * 1. Status header: a plain-language verdict ("Your site is protected") with the two primary actions.
 * 2. Stats strip: Last backup, Schedule, Stored backups, Storage used — one frame, hairline dividers.
 * 3. Two-column body:
 *    - Left: Recent backups list and Recent activity.
 *    - Right: Quick actions list, Protection summary, and a live migration card when one is running.
 *
 * @package MigrateLift
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import PageHeader from '../components/common/PageHeader';
import MetricCard from '../components/common/MetricCard';
import SectionCard from '../components/common/SectionCard';
import StatusPill from '../components/common/StatusPill';
import EmptyState from '../components/common/EmptyState';
import InlineNotice from '../components/common/InlineNotice';
import Skeleton from '../components/common/Skeleton';
import ProgressBar from '../components/common/ProgressBar';
import {
	IconBackup,
	IconClock,
	IconMigrate,
	IconImport,
	IconPlus,
	IconArrowRight,
	IconChevronRight,
	IconRefresh,
	IconDownload,
} from '../components/common/Icons';
import { formatBytes } from '../utils/api';
import {
	fetchDashboardSummary,
	formatRelativeTime,
	normalizeSummary,
} from '../utils/dashboardApi';

export default function DashboardView( {
	archives = [],
	onNavigate,
} ) {
	const [ summary, setSummary ] = useState( () => normalizeSummary( {}, archives ) );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const loadSummary = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		try {
			const data = await fetchDashboardSummary();
			setSummary( data );
		} catch ( err ) {
			setError( err.message || __( 'Could not load dashboard summary.', 'migrate-lift' ) );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		loadSummary();
	}, [ loadSummary ] );

	// 1. Loading skeleton state
	if ( isLoading && ! summary.site.url ) {
		return (
			<div className="ml-dashboard-view">
				<div style={ { display: 'flex', justifyContent: 'space-between', gap: 16 } }>
					<div style={ { width: '40%' } }>
						<Skeleton variant="text" width="280px" height="24px" />
						<Skeleton variant="text" width="360px" height="14px" />
					</div>
					<div style={ { display: 'flex', gap: 8 } }>
						<Skeleton variant="rect" width="96px" height="36px" />
						<Skeleton variant="rect" width="132px" height="36px" />
					</div>
				</div>
				<Skeleton variant="card" height="104px" />
				<div className="ml-dashboard-main-grid">
					<div className="ml-dash-primary-col">
						<Skeleton variant="card" height="280px" />
						<Skeleton variant="card" height="200px" />
					</div>
					<div className="ml-dash-secondary-col">
						<Skeleton variant="card" height="260px" />
						<Skeleton variant="card" height="200px" />
					</div>
				</div>
			</div>
		);
	}

	const {
		site,
		kpis,
		recentBackups,
		migrationJob,
		protectionSettings,
		recentActivity,
	} = summary;

	const isProtected = !! kpis.lastBackup;
	const isScheduleOn = !! protectionSettings.scheduleEnabled;

	const quickActions = [
		{
			route: 'backups',
			icon: <IconBackup />,
			title: __( 'Create a backup', 'migrate-lift' ),
			desc: __( 'Database and wp-content in one archive', 'migrate-lift' ),
		},
		{
			route: 'import',
			icon: <IconImport />,
			title: __( 'Import or restore', 'migrate-lift' ),
			desc: __( 'Upload an archive or roll back', 'migrate-lift' ),
		},
		{
			route: 'schedule',
			icon: <IconClock />,
			title: __( 'Automate backups', 'migrate-lift' ),
			desc: __( 'Daily backups with retention', 'migrate-lift' ),
		},
		{
			route: 'migrate',
			icon: <IconMigrate />,
			title: __( 'Migrate site', 'migrate-lift' ),
			desc: __( 'Server-to-server transfer', 'migrate-lift' ),
		},
	];

	const protectionRows = [
		{
			label: __( 'Automatic backups', 'migrate-lift' ),
			value: isScheduleOn
				? ( protectionSettings.scheduleFrequency || __( 'On', 'migrate-lift' ) )
				: __( 'Off', 'migrate-lift' ),
			tone: isScheduleOn ? 'success' : 'warning',
		},
		{
			label: __( 'Retention', 'migrate-lift' ),
			value: `${ protectionSettings.retentionCopies } ` + __( 'copies', 'migrate-lift' ),
		},
		{
			label: __( 'Compression', 'migrate-lift' ),
			value: protectionSettings.compressionMode || __( 'Balanced', 'migrate-lift' ),
		},
		{
			label: __( 'Storage folder', 'migrate-lift' ),
			value: __( 'Protected', 'migrate-lift' ),
			tone: 'success',
		},
	];

	return (
		<div className="ml-dashboard-view">
			{ /* Offline / Local Fallback Notice */ }
			{ error && (
				<InlineNotice
					status="warning"
					title={ __( 'Couldn’t reach the server', 'migrate-lift' ) }
					isDismissible
					onDismiss={ () => setError( null ) }
				>
					<div className="ml-notice-row">
						<span>
							{ error } { __( 'Showing what we could read locally.', 'migrate-lift' ) }
						</span>
						<button
							type="button"
							className="ml-btn sm"
							onClick={ () => loadSummary() }
						>
							<IconRefresh />
							<span>{ __( 'Retry', 'migrate-lift' ) }</span>
						</button>
					</div>
				</InlineNotice>
			) }

			{ /* 1. Status header */ }
			<PageHeader
				title={
					isProtected
						? __( 'Your site is protected', 'migrate-lift' )
						: __( 'Your site needs a backup', 'migrate-lift' )
				}
				description={
					<span className="ml-dash-header-status">
						<span className={ `ml-pulse-dot ${ isProtected ? 'online' : 'attention' }` } aria-hidden="true" />
						<span>
							{ isProtected
								? __( 'Latest backup is verified and ready to restore.', 'migrate-lift' )
								: __( 'Create your first backup so you always have a restore point.', 'migrate-lift' ) }
						</span>
					</span>
				}
				actions={
					<>
						<button
							type="button"
							className="ml-btn"
							onClick={ () => onNavigate( 'import' ) }
						>
							<IconImport />
							<span>{ __( 'Import', 'migrate-lift' ) }</span>
						</button>
						<button
							type="button"
							className="ml-btn primary"
							onClick={ () => onNavigate( 'backups' ) }
						>
							<IconPlus />
							<span>{ __( 'Create backup', 'migrate-lift' ) }</span>
						</button>
					</>
				}
			/>

			{ /* 2. Stats strip */ }
			<div className="ml-metric-grid" aria-label={ __( 'Key site protection metrics', 'migrate-lift' ) }>
				<MetricCard
					title={ __( 'Last backup', 'migrate-lift' ) }
					value={ kpis.lastBackup ? formatRelativeTime( kpis.lastBackup.time ) : '—' }
					metaHighlight={ kpis.lastBackup ? __( 'Verified', 'migrate-lift' ) : __( 'None yet', 'migrate-lift' ) }
					meta={ kpis.lastBackup ? ( kpis.lastBackup.date || kpis.lastBackup.file ) : '' }
					tone={ kpis.lastBackup ? 'success' : 'warning' }
					onClick={ () => onNavigate( 'backups' ) }
				/>

				<MetricCard
					title={ __( 'Schedule', 'migrate-lift' ) }
					value={ isScheduleOn ? ( kpis.nextScheduled || __( 'Scheduled', 'migrate-lift' ) ) : __( 'Manual', 'migrate-lift' ) }
					metaHighlight={ isScheduleOn ? __( 'Active', 'migrate-lift' ) : __( 'Off', 'migrate-lift' ) }
					meta={
						isScheduleOn
							? ( protectionSettings.scheduleFrequency || __( 'Daily', 'migrate-lift' ) )
							: __( 'Set up automatic backups', 'migrate-lift' )
					}
					tone={ isScheduleOn ? 'success' : 'neutral' }
					onClick={ () => onNavigate( 'schedule' ) }
				/>

				<MetricCard
					title={ __( 'Stored backups', 'migrate-lift' ) }
					value={ String( kpis.storedBackupsCount || 0 ) }
					meta={ __( 'In protected storage', 'migrate-lift' ) }
					onClick={ () => onNavigate( 'backups' ) }
				/>

				<MetricCard
					title={ __( 'Storage used', 'migrate-lift' ) }
					value={ kpis.totalStorageBytes > 0 ? formatBytes( kpis.totalStorageBytes ) : '0 B' }
					meta={ __( 'On this server', 'migrate-lift' ) }
					onClick={ () => onNavigate( 'backups' ) }
				/>
			</div>

			{ /* 3. Two-column body */ }
			<div className="ml-dashboard-main-grid">
				<div className="ml-dash-primary-col">
					{ /* Recent Backups */ }
					<SectionCard
						title={ __( 'Recent backups', 'migrate-lift' ) }
						headerActions={
							recentBackups.length > 0 && (
								<button
									type="button"
									className="ml-link-btn"
									onClick={ () => onNavigate( 'backups' ) }
								>
									{ __( 'View all', 'migrate-lift' ) }
									<IconArrowRight />
								</button>
							)
						}
						noPadding={ recentBackups.length > 0 }
					>
						{ recentBackups.length === 0 ? (
							<EmptyState
								icon={ <IconBackup /> }
								title={ __( 'No backups yet', 'migrate-lift' ) }
								description={ __(
									'A backup packages your database and wp-content into one verified archive you can restore or move.',
									'migrate-lift'
								) }
								primaryAction={
									<button
										type="button"
										className="ml-btn primary"
										onClick={ () => onNavigate( 'backups' ) }
									>
										<IconPlus />
										<span>{ __( 'Create first backup', 'migrate-lift' ) }</span>
									</button>
								}
							/>
						) : (
							<ul className="ml-dash-backups-list">
								{ recentBackups.slice( 0, 4 ).map( ( arch ) => {
									const isZip = arch.name?.endsWith( '.zip' ) || arch.file?.endsWith( '.zip' );

									return (
										<li key={ arch.file } className="ml-dash-backup-row">
											<div className="ml-dash-backup-left">
												<div className="ml-dash-file-icon" aria-hidden="true">
													<IconBackup />
												</div>
												<div className="ml-dash-backup-meta">
													<div className="ml-dash-backup-title" title={ arch.name || arch.file }>
														{ arch.name || arch.file }
													</div>
													<div className="ml-dash-backup-sub">
														<span>{ arch.date || formatRelativeTime( arch.time ) }</span>
														<span className="ml-dot-sep" aria-hidden="true">·</span>
														<span>{ formatBytes( arch.size ) }</span>
														<span className="ml-dot-sep" aria-hidden="true">·</span>
														<span>{ isZip ? 'ZIP' : 'TGZ' }</span>
													</div>
												</div>
											</div>

											<div className="ml-dash-backup-right">
												<StatusPill status="success" label={ __( 'Verified', 'migrate-lift' ) } />
												{ arch.download && (
													<a
														href={ arch.download }
														className="ml-icon-btn"
														title={ __( 'Download archive', 'migrate-lift' ) }
														aria-label={ __( 'Download archive', 'migrate-lift' ) }
														download
													>
														<IconDownload />
													</a>
												) }
												<button
													type="button"
													className="ml-icon-btn"
													onClick={ () => onNavigate( 'backups' ) }
													title={ __( 'Manage', 'migrate-lift' ) }
													aria-label={ __( 'Manage backup', 'migrate-lift' ) }
												>
													<IconChevronRight />
												</button>
											</div>
										</li>
									);
								} ) }
							</ul>
						) }
					</SectionCard>

					{ /* Recent Activity */ }
					<SectionCard
						title={ __( 'Recent activity', 'migrate-lift' ) }
						headerActions={
							<button
								type="button"
								className="ml-link-btn"
								onClick={ () => onNavigate( 'activity' ) }
							>
								{ __( 'Full log', 'migrate-lift' ) }
								<IconArrowRight />
							</button>
						}
					>
						{ recentActivity.length === 0 ? (
							<div className="ml-activity-empty-box">
								<p>{ __( 'Backup, restore and schedule events will show up here.', 'migrate-lift' ) }</p>
							</div>
						) : (
							<div className="ml-timeline" role="feed" aria-label={ __( 'Recent events', 'migrate-lift' ) }>
								{ recentActivity.slice( 0, 4 ).map( ( item ) => (
									<article key={ item.id } className="ml-timeline-item">
										<div
											className={ `ml-timeline-dot ${ item.level || 'info' }` }
											aria-hidden="true"
										/>
										<div className="ml-timeline-body">
											<div className="ml-timeline-header">
												<span className="ml-timeline-title">{ item.event }</span>
												<time className="ml-timeline-time">{ item.timestamp }</time>
											</div>
											{ item.description && (
												<p className="ml-timeline-desc">{ item.description }</p>
											) }
										</div>
									</article>
								) ) }
							</div>
						) }
					</SectionCard>
				</div>

				<div className="ml-dash-secondary-col">
					{ /* Live migration (only while one is running) */ }
					{ migrationJob && (
						<SectionCard
							title={ __( 'Migration in progress', 'migrate-lift' ) }
							headerActions={
								<StatusPill status="info" label={ migrationJob.status || __( 'Running', 'migrate-lift' ) } />
							}
						>
							<ProgressBar
								progress={ migrationJob.progress || 0 }
								label={ migrationJob.currentStep || __( 'Transferring…', 'migrate-lift' ) }
							/>
							<button
								type="button"
								className="ml-link-btn"
								onClick={ () => onNavigate( 'migrate' ) }
							>
								{ __( 'Open monitor', 'migrate-lift' ) }
								<IconArrowRight />
							</button>
						</SectionCard>
					) }

					{ /* Quick Actions */ }
					<SectionCard title={ __( 'Quick actions', 'migrate-lift' ) } noPadding>
						<ul className="ml-action-list">
							{ quickActions.map( ( action ) => (
								<li key={ action.route }>
									<button
										type="button"
										className="ml-action-row"
										onClick={ () => onNavigate( action.route ) }
									>
										<span className="ml-action-row-icon" aria-hidden="true">{ action.icon }</span>
										<span className="ml-action-row-text">
											<strong>{ action.title }</strong>
											<span>{ action.desc }</span>
										</span>
										{ action.badge && <span className="ml-nav-badge-soon">{ action.badge }</span> }
										<IconChevronRight className="ml-action-row-chevron" />
									</button>
								</li>
							) ) }
						</ul>
					</SectionCard>

					{ /* Protection summary */ }
					<SectionCard
						title={ __( 'Protection', 'migrate-lift' ) }
						headerActions={
							<button
								type="button"
								className="ml-link-btn"
								onClick={ () => onNavigate( 'settings' ) }
							>
								{ __( 'Settings', 'migrate-lift' ) }
								<IconArrowRight />
							</button>
						}
					>
						<dl className="ml-kv-list">
							{ protectionRows.map( ( row ) => (
								<div key={ row.label } className="ml-kv-row">
									<dt>{ row.label }</dt>
									<dd>
										{ row.tone && <span className={ `ml-kv-dot ${ row.tone }` } aria-hidden="true" /> }
										{ row.value }
									</dd>
								</div>
							) ) }
						</dl>
					</SectionCard>
				</div>
			</div>
		</div>
	);
}
