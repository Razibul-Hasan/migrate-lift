/**
 * ScheduleView Component
 * Configures reliable automated backup scheduling (Daily / Weekly),
 * site-timezone start times, rolling retention rules, storage alerts,
 * and system crontab instructions (Part 08).
 *
 * @package MigrateLift
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import PageHeader from '../components/common/PageHeader';
import SectionCard from '../components/common/SectionCard';
import StatusPill from '../components/common/StatusPill';
import InlineNotice from '../components/common/InlineNotice';
import Skeleton from '../components/common/Skeleton';
import {
	IconClock,
	IconCheck,
	IconAlertTriangle,
	IconRefresh,
	IconServer,
	IconShield,
	IconHarddrive,
} from '../components/common/Icons';
import {
	formatBytes,
	getScheduleSettings,
	saveScheduleSettings,
	runScheduleNow,
} from '../utils/api';

export default function ScheduleView() {
	const [ data, setData ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isRunningNow, setIsRunningNow ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ copySuccess, setCopySuccess ] = useState( false );

	// Form State
	const [ enabled, setEnabled ] = useState( false );
	const [ cadence, setCadence ] = useState( 'daily' );
	const [ time, setTime ] = useState( '02:00' );
	const [ dayOfWeek, setDayOfWeek ] = useState( 1 );
	const [ retentionCopies, setRetentionCopies ] = useState( 7 );
	const [ notifyOnFailure, setNotifyOnFailure ] = useState( true );
	const [ notifyEmail, setNotifyEmail ] = useState( '' );
	const [ notifyLowDisk, setNotifyLowDisk ] = useState( true );

	const loadSettings = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		try {
			const res = await getScheduleSettings();
			setData( res );
			if ( res.settings ) {
				setEnabled( !! res.settings.enabled );
				setCadence( res.settings.cadence || 'daily' );
				setTime( res.settings.time || '02:00' );
				setDayOfWeek( res.settings.day_of_week || 1 );
				setRetentionCopies( res.settings.retention_copies || 7 );
				setNotifyOnFailure( res.settings.notify_on_failure !== false );
				setNotifyEmail( res.settings.notify_email || '' );
				setNotifyLowDisk( res.settings.notify_low_disk !== false );
			}
		} catch ( err ) {
			setError( err.message || __( 'Could not load schedule settings.', 'migrate-lift' ) );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		loadSettings();
	}, [ loadSettings ] );

	const handleSave = async ( e ) => {
		if ( e ) e.preventDefault();
		setIsSaving( true );
		setNotice( null );
		setError( null );

		try {
			const payload = {
				enabled,
				cadence,
				time,
				day_of_week: Number( dayOfWeek ),
				retention_copies: Number( retentionCopies ),
				notify_on_failure: notifyOnFailure,
				notify_email: notifyEmail,
				notify_low_disk: notifyLowDisk,
			};

			const res = await saveScheduleSettings( payload );
			setNotice( {
				status: 'success',
				message: res?.message || __( 'Backup schedule configuration saved successfully.', 'migrate-lift' ),
			} );
			await loadSettings();
		} catch ( err ) {
			setError( err.message || __( 'Failed to save schedule settings.', 'migrate-lift' ) );
		} finally {
			setIsSaving( false );
		}
	};

	const handleRunNow = async () => {
		if ( isRunningNow ) return;
		setIsRunningNow( true );
		setNotice( null );
		setError( null );

		try {
			const res = await runScheduleNow();
			setNotice( {
				status: 'success',
				message: res?.message || __( 'Automated scheduled backup completed and verified.', 'migrate-lift' ),
			} );
			await loadSettings();
		} catch ( err ) {
			setError( err.message || __( 'Execution failed. Check activity log.', 'migrate-lift' ) );
		} finally {
			setIsRunningNow( false );
		}
	};

	const handleCopyCron = ( text ) => {
		if ( ! navigator?.clipboard ) return;
		navigator.clipboard.writeText( text );
		setCopySuccess( true );
		setTimeout( () => setCopySuccess( false ), 2000 );
	};

	if ( isLoading && ! data ) {
		return (
			<div className="ml-schedule-view">
				<Skeleton variant="text" width="220px" height="28px" />
				<div style={ { display: 'flex', flexDirection: 'column', gap: 20, marginTop: 20 } }>
					<Skeleton variant="card" count={ 3 } />
				</div>
			</div>
		);
	}

	const tz = data?.site_timezone || {};
	const storage = data?.server_storage || {};
	const systemCron = data?.system_cron || {};
	const lastRun = data?.last_run;
	const nextRunFormatted = data?.next_run_formatted;

	// Disk capacity warning: check if free space < 100MB
	const isLowDisk = storage.disk_free_bytes !== null && storage.disk_free_bytes < 104857600;

	return (
		<div className="ml-schedule-view">
			{ /* Page Header */ }
			<PageHeader
				overline={ __( 'Automation Engine', 'migrate-lift' ) }
				title={ __( 'Backup Schedule & Retention', 'migrate-lift' ) }
				description={ __(
					'Automate regular site backups with configurable schedules, site-timezone off-peak timing, and automatic retention limits.',
					'migrate-lift'
				) }
				actions={
					<div style={ { display: 'flex', gap: 10 } }>
						<button
							type="button"
							className="ml-btn"
							onClick={ handleRunNow }
							disabled={ isRunningNow || isSaving }
							title={ __( 'Test trigger the scheduled backup process right now', 'migrate-lift' ) }
						>
							<IconRefresh className={ isRunningNow ? 'ml-spin' : '' } />
							<span>{ isRunningNow ? __( 'Running…', 'migrate-lift' ) : __( 'Run Schedule Now', 'migrate-lift' ) }</span>
						</button>
						<button
							type="button"
							className="ml-btn primary"
							onClick={ handleSave }
							disabled={ isSaving || isRunningNow }
						>
							<IconCheck />
							<span>{ isSaving ? __( 'Saving…', 'migrate-lift' ) : __( 'Save Changes', 'migrate-lift' ) }</span>
						</button>
					</div>
				}
			/>

			{ /* Notices */ }
			{ notice && (
				<InlineNotice status={ notice.status } onDismiss={ () => setNotice( null ) }>
					{ notice.message }
				</InlineNotice>
			) }

			{ error && (
				<InlineNotice status="error" onDismiss={ () => setError( null ) }>
					<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16 } }>
						<span>{ error }</span>
						<button type="button" className="ml-btn sm" onClick={ loadSettings }>
							<IconRefresh style={ { width: 12, height: 12 } } />
							<span>{ __( 'Retry', 'migrate-lift' ) }</span>
						</button>
					</div>
				</InlineNotice>
			) }

			{ /* Next Run & Status Banner Card */ }
			<div className="ml-schedule-status-banner">
				<div className="ml-schedule-status-left">
					<div className="ml-schedule-icon-badge">
						<IconClock />
					</div>
					<div>
						<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
							<StatusPill
								status={ enabled ? 'success' : 'neutral' }
								label={ enabled ? __( 'Schedule Active', 'migrate-lift' ) : __( 'Schedule Disabled', 'migrate-lift' ) }
							/>
							<span style={ { fontSize: 12, color: 'var(--ml-muted)' } }>
								{ data?.frequency_label || __( 'Manual', 'migrate-lift' ) }
							</span>
						</div>
						<div className="ml-schedule-next-run">
							{ enabled ? (
								nextRunFormatted ? (
									<>
										{ __( 'Next run:', 'migrate-lift' ) }{ ' ' }
										<strong style={ { color: 'var(--ml-ink)' } }>{ nextRunFormatted }</strong>
									</>
								) : (
									__( 'Next run: Scheduled on next cron cycle', 'migrate-lift' )
								)
							) : (
								__( 'Automatic backups are currently disabled.', 'migrate-lift' )
							) }
						</div>
					</div>
				</div>

				{ lastRun && (
					<div className="ml-schedule-last-run-box">
						<span className="ml-schedule-last-label">{ __( 'Last Execution:', 'migrate-lift' ) }</span>
						<div style={ { display: 'flex', alignItems: 'center', gap: 6, marginTop: 2 } }>
							<StatusPill
								status={ lastRun.status === 'success' ? 'success' : 'danger' }
								label={ lastRun.status === 'success' ? __( 'Success', 'migrate-lift' ) : __( 'Failed', 'migrate-lift' ) }
							/>
							<span style={ { fontSize: 12, color: 'var(--ml-muted)' } }>
								{ lastRun.date || '—' }
								{ lastRun.size > 0 && ` (${ formatBytes( lastRun.size ) })` }
							</span>
						</div>
					</div>
				)}
			</div>

			{ /* Main Form Layout Grid */ }
			<form onSubmit={ handleSave } className="ml-schedule-form-grid">
				{ /* Column 1: Core Frequency & Timing Settings */ }
				<SectionCard
					title={ __( 'Schedule Configuration', 'migrate-lift' ) }
					subtitle={ __( 'Set cadence and preferred off-peak execution window in your site timezone.', 'migrate-lift' ) }
				>
					{ /* Enable / Disable Toggle */ }
					<div className="ml-form-row">
						<div className="ml-form-label-group">
							<label htmlFor="ml-sched-enable" className="ml-form-label">
								{ __( 'Enable Automated Backups', 'migrate-lift' ) }
							</label>
							<p className="ml-form-desc">
								{ __( 'When active, MigrateLift automatically exports a full snapshot and verifies archive integrity.', 'migrate-lift' ) }
							</p>
						</div>
						<div className="ml-form-control">
							<input
								id="ml-sched-enable"
								type="checkbox"
								checked={ enabled }
								onChange={ ( e ) => setEnabled( e.target.checked ) }
								className="ml-toggle-switch"
							/>
						</div>
					</div>

					{ /* Cadence Selector */ }
					<div className="ml-form-row">
						<div className="ml-form-label-group">
							<label htmlFor="ml-sched-cadence" className="ml-form-label">
								{ __( 'Backup Frequency', 'migrate-lift' ) }
							</label>
							<p className="ml-form-desc">
								{ __( 'Choose how often snapshots should be generated.', 'migrate-lift' ) }
							</p>
						</div>
						<div className="ml-form-control">
							<select
								id="ml-sched-cadence"
								value={ cadence }
								onChange={ ( e ) => setCadence( e.target.value ) }
								disabled={ ! enabled }
								className="ml-select"
								style={ { minWidth: 160 } }
							>
								<option value="daily">{ __( 'Every Day (Daily)', 'migrate-lift' ) }</option>
								<option value="weekly">{ __( 'Once a Week (Weekly)', 'migrate-lift' ) }</option>
								<option value="manual">{ __( 'Manual Only (Disabled)', 'migrate-lift' ) }</option>
							</select>
						</div>
					</div>

					{ /* Weekly Day of Week (if Weekly selected) */ }
					{ cadence === 'weekly' && (
						<div className="ml-form-row">
							<div className="ml-form-label-group">
								<label htmlFor="ml-sched-dow" className="ml-form-label">
									{ __( 'Day of the Week', 'migrate-lift' ) }
								</label>
								<p className="ml-form-desc">
									{ __( 'Day on which the weekly backup should execute.', 'migrate-lift' ) }
								</p>
							</div>
							<div className="ml-form-control">
								<select
									id="ml-sched-dow"
									value={ dayOfWeek }
									onChange={ ( e ) => setDayOfWeek( Number( e.target.value ) ) }
									disabled={ ! enabled }
									className="ml-select"
								>
									<option value={ 1 }>{ __( 'Monday', 'migrate-lift' ) }</option>
									<option value={ 2 }>{ __( 'Tuesday', 'migrate-lift' ) }</option>
									<option value={ 3 }>{ __( 'Wednesday', 'migrate-lift' ) }</option>
									<option value={ 4 }>{ __( 'Thursday', 'migrate-lift' ) }</option>
									<option value={ 5 }>{ __( 'Friday', 'migrate-lift' ) }</option>
									<option value={ 6 }>{ __( 'Saturday', 'migrate-lift' ) }</option>
									<option value={ 7 }>{ __( 'Sunday', 'migrate-lift' ) }</option>
								</select>
							</div>
						</div>
					)}

					{ /* Start Time in Site Timezone */ }
					<div className="ml-form-row">
						<div className="ml-form-label-group">
							<label htmlFor="ml-sched-time" className="ml-form-label">
								{ __( 'Execution Time', 'migrate-lift' ) }
							</label>
							<p className="ml-form-desc">
								{ sprintf(
									__( 'Time in your WordPress site timezone (%s, current time: %s). Off-peak hours recommended.', 'migrate-lift' ),
									tz.string || 'UTC',
									tz.current_time || '00:00'
								) }
							</p>
						</div>
						<div className="ml-form-control" style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
							<input
								id="ml-sched-time"
								type="time"
								value={ time }
								onChange={ ( e ) => setTime( e.target.value ) }
								disabled={ ! enabled }
								className="ml-input"
								style={ { padding: '6px 10px', fontSize: 13, borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }
							/>
							<span style={ { fontSize: 12, color: 'var(--ml-muted)' } }>
								{ tz.string || 'UTC' }
							</span>
						</div>
					</div>

					{ /* Retention Limit */ }
					<div className="ml-form-row">
						<div className="ml-form-label-group">
							<label htmlFor="ml-sched-retention" className="ml-form-label">
								{ __( 'Retention Limit', 'migrate-lift' ) }
							</label>
							<p className="ml-form-desc">
								{ __(
									'Number of recent completed backup copies to keep. When a new scheduled backup succeeds, older completed backups exceeding this limit are pruned.',
									'migrate-lift'
								) }
							</p>
						</div>
						<div className="ml-form-control" style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
							<input
								id="ml-sched-retention"
								type="number"
								min="1"
								max="60"
								value={ retentionCopies }
								onChange={ ( e ) => setRetentionCopies( Math.max( 1, Math.min( 60, parseInt( e.target.value, 10 ) || 1 ) ) ) }
								disabled={ ! enabled }
								className="ml-input"
								style={ { width: 80, padding: '6px 10px', fontSize: 13, borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }
							/>
							<span style={ { fontSize: 12, color: 'var(--ml-muted)' } }>
								{ __( 'snapshots (1–60)', 'migrate-lift' ) }
							</span>
						</div>
					</div>
				</SectionCard>

				{ /* Column 2: Storage Warnings & Notifications */ }
				<SectionCard
					title={ __( 'Storage & Administrator Alerts', 'migrate-lift' ) }
					subtitle={ __( 'Safety thresholds and notifications for failure retries and server capacity.', 'migrate-lift' ) }
				>
					{ /* Server Storage Status */ }
					<div className="ml-sched-storage-box">
						<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 } }>
							<IconHarddrive style={ { width: 18, height: 18, color: isLowDisk ? 'var(--ml-red)' : 'var(--ml-teal)' } } />
							<strong style={ { fontSize: 13, color: 'var(--ml-ink)' } }>
								{ __( 'Server Storage Capacity', 'migrate-lift' ) }
							</strong>
						</div>
						<div style={ { fontSize: 12, color: 'var(--ml-muted)', display: 'flex', flexDirection: 'column', gap: 4 } }>
							<div>
								{ __( 'Available Free Space:', 'migrate-lift' ) }{ ' ' }
								<strong style={ { color: isLowDisk ? 'var(--ml-red)' : 'var(--ml-ink)' } }>
									{ storage.disk_free_bytes !== null ? formatBytes( storage.disk_free_bytes ) : __( 'Checking…', 'migrate-lift' ) }
								</strong>
							</div>
							<div>
								{ __( 'Estimated Retention Footprint:', 'migrate-lift' ) }{ ' ' }
								<strong>~{ retentionCopies } { __( 'copies', 'migrate-lift' ) }</strong>
							</div>
						</div>
						{ isLowDisk && (
							<div style={ { marginTop: 10, display: 'flex', alignItems: 'center', gap: 6, color: 'var(--ml-red)', fontSize: 12 } }>
								<IconAlertTriangle style={ { width: 14, height: 14 } } />
								<span>{ __( 'Free disk space is critically low. Consider freeing space or reducing retention copies.', 'migrate-lift' ) }</span>
							</div>
						)}
					</div>

					{ /* Failure Notifications */ }
					<div className="ml-form-row">
						<div className="ml-form-label-group">
							<label htmlFor="ml-sched-notify-fail" className="ml-form-label">
								{ __( 'Alert on Repeated Failures', 'migrate-lift' ) }
							</label>
							<p className="ml-form-desc">
								{ __( 'Sends an email alert to the administrator if automated backup fails after capped retries. Throttled to once every 24 hours.', 'migrate-lift' ) }
							</p>
						</div>
						<div className="ml-form-control">
							<input
								id="ml-sched-notify-fail"
								type="checkbox"
								checked={ notifyOnFailure }
								onChange={ ( e ) => setNotifyOnFailure( e.target.checked ) }
								className="ml-toggle-switch"
							/>
						</div>
					</div>

					{ /* Low Disk Alerts */ }
					<div className="ml-form-row">
						<div className="ml-form-label-group">
							<label htmlFor="ml-sched-notify-disk" className="ml-form-label">
								{ __( 'Alert on Low Disk Space', 'migrate-lift' ) }
							</label>
							<p className="ml-form-desc">
								{ __( 'Sends an email warning if free disk space falls below 50 MB to prevent server filesystem crashes.', 'migrate-lift' ) }
							</p>
						</div>
						<div className="ml-form-control">
							<input
								id="ml-sched-notify-disk"
								type="checkbox"
								checked={ notifyLowDisk }
								onChange={ ( e ) => setNotifyLowDisk( e.target.checked ) }
								className="ml-toggle-switch"
							/>
						</div>
					</div>

					{ /* Notification Email */ }
					<div className="ml-form-row">
						<div className="ml-form-label-group">
							<label htmlFor="ml-sched-email" className="ml-form-label">
								{ __( 'Notification Email', 'migrate-lift' ) }
							</label>
							<p className="ml-form-desc">
								{ __( 'Destination email address for automated warnings.', 'migrate-lift' ) }
							</p>
						</div>
						<div className="ml-form-control">
							<input
								id="ml-sched-email"
								type="email"
								value={ notifyEmail }
								onChange={ ( e ) => setNotifyEmail( e.target.value ) }
								disabled={ ! notifyOnFailure && ! notifyLowDisk }
								placeholder="admin@example.com"
								className="ml-input"
								style={ { width: '100%', maxWidth: 260, padding: '6px 10px', fontSize: 13, borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }
							/>
						</div>
					</div>
				</SectionCard>
			</form>

			{ /* WP-Cron Timing Notice & System Crontab Guide */ }
			<SectionCard
				title={ __( 'Timing Reliability & Server Cron Instructions', 'migrate-lift' ) }
				subtitle={ __( 'How WordPress handles background tasks and how to configure optional exact-time system execution.', 'migrate-lift' ) }
			>
				<div className="ml-cron-explanation-box">
					<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 } }>
						<IconServer style={ { width: 18, height: 18, color: 'var(--ml-blue)' } } />
						<strong style={ { fontSize: 13, color: 'var(--ml-ink)' } }>
							{ __( 'Understanding WP-Cron Timing Behavior', 'migrate-lift' ) }
						</strong>
					</div>
					<p style={ { fontSize: 13, color: 'var(--ml-muted)', margin: '0 0 14px', lineHeight: 1.55 } }>
						{ __(
							'By default, WordPress WP-Cron is traffic-driven: scheduled tasks check and execute when a visitor requests a page on your site. On sites with sporadic or zero traffic during overnight hours, the scheduled backup will trigger as soon as the first morning visitor arrives. No external SaaS account or cloud connection is required.',
							'migrate-lift'
						) }
					</p>

					<div className="ml-system-cron-panel">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 } }>
							<span style={ { fontSize: 12, fontWeight: 700, color: 'var(--ml-ink)' } }>
								{ __( 'Optional Exact-Time Crontab Command (Standard Linux / cPanel / VPS)', 'migrate-lift' ) }
							</span>
							<button
								type="button"
								className="ml-btn sm"
								onClick={ () => handleCopyCron( systemCron.curl_command || '' ) }
							>
								{ copySuccess ? __( 'Copied!', 'migrate-lift' ) : __( 'Copy Command', 'migrate-lift' ) }
							</button>
						</div>
						<pre className="ml-cron-code-block">
							{ systemCron.curl_command || '* * * * * curl -s -L "https://example.com/wp-cron.php?doing_wp_cron" > /dev/null 2>&1' }
						</pre>
						<div style={ { fontSize: 11, color: 'var(--ml-muted-light)', marginTop: 6 } }>
							{ __( 'Alternatively, if using WP-CLI on your server:', 'migrate-lift' ) }{ ' ' }
							<code>{ systemCron.wp_cli_command || 'wp cron event run migrate_lift_scheduled_backup_event' }</code>
						</div>
					</div>
				</div>
			</SectionCard>
		</div>
	);
}
