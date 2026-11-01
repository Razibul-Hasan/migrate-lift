/**
 * BackupJobDrawer Component
 * Interactive progress drawer for creating full site backups via Job Engine.
 * Supports real-time stepping, pause/resume, cancellation, and backgrounding.
 *
 * @package MigrateLift
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	IconBackup,
	IconCheck,
	IconClose,
	IconRefresh,
	IconDownload,
	IconAlertTriangle,
} from '../common/Icons';
import ProgressBar from '../common/ProgressBar';
import { formatBytes, runRestJob, pauseJob, resumeJob, cancelJob } from '../../utils/api';

const PHASES = [
	{ id: 'init', label: __( 'Initialize & Disk Check', 'migrate-lift' ) },
	{ id: 'scanning_files', label: __( 'Scan Files & Exclusions', 'migrate-lift' ) },
	{ id: 'archiving_database', label: __( 'Database Export', 'migrate-lift' ) },
	{ id: 'archiving_files', label: __( 'Stream Archive Files', 'migrate-lift' ) },
	{ id: 'verify_archive', label: __( 'Integrity & Checksum Verification', 'migrate-lift' ) },
	{ id: 'finalize', label: __( 'Finalize & Register', 'migrate-lift' ) },
];

export default function BackupJobDrawer( {
	isOpen,
	activeJob = null,
	onClose,
	onSuccess,
	onViewDetails,
} ) {
	const [ isRunning, setIsRunning ] = useState( false );
	const [ isPaused, setIsPaused ] = useState( false );
	const [ currentJob, setCurrentJob ] = useState( null );
	const [ progress, setProgress ] = useState( 0 );
	const [ phase, setPhase ] = useState( 'init' );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ completedOutput, setCompletedOutput ] = useState( null );

	const isCancellingRef = useRef( false );
	const jobRunnerActiveRef = useRef( false );

	// Start or attach to job when opened
	useEffect( () => {
		if ( ! isOpen ) return;

		if ( activeJob ) {
			setCurrentJob( activeJob );
			setProgress( activeJob.percentage || 0 );
			setPhase( activeJob.phase || 'init' );
			setMessage( activeJob.diagnostic_detail || activeJob.message || '' );
			setIsPaused( activeJob.state === 'paused' );
			if ( activeJob.state === 'completed' && activeJob.output ) {
				setCompletedOutput( activeJob.output );
				setIsRunning( false );
				return;
			}
		}

		if ( ! jobRunnerActiveRef.current && ( ! activeJob || activeJob.state !== 'completed' ) ) {
			startBackupExecution();
		}
	}, [ isOpen, activeJob ] );

	const startBackupExecution = useCallback( async () => {
		if ( jobRunnerActiveRef.current ) return;
		jobRunnerActiveRef.current = true;
		setIsRunning( true );
		setError( null );
		setCompletedOutput( null );
		setProgress( 0 );
		setPhase( 'init' );
		setMessage( __( 'Starting full site snapshot…', 'migrate-lift' ) );

		try {
			const result = await runRestJob( 'backup', {}, ( update ) => {
				if ( update.job ) {
					setCurrentJob( update.job );
					setIsPaused( update.job.state === 'paused' );
				}
				if ( update.phase ) {
					setPhase( update.phase );
				}
				if ( typeof update.progress === 'number' ) {
					setProgress( update.progress );
				}
				if ( update.message ) {
					setMessage( update.message );
				}
			} );

			setProgress( 100 );
			setPhase( 'completed' );
			setIsRunning( false );
			jobRunnerActiveRef.current = false;

			const output = result.job?.output || result;
			setCompletedOutput( output );

			if ( onSuccess ) {
				onSuccess( output );
			}
		} catch ( err ) {
			if ( ! isCancellingRef.current ) {
				setError( err.message || __( 'Backup job failed unexpectedly.', 'migrate-lift' ) );
			}
			setIsRunning( false );
			jobRunnerActiveRef.current = false;
		}
	}, [ onSuccess ] );

	const handlePauseResume = async () => {
		if ( ! currentJob?.id ) return;
		try {
			if ( isPaused ) {
				await resumeJob( currentJob.id );
				setIsPaused( false );
			} else {
				await pauseJob( currentJob.id );
				setIsPaused( true );
			}
		} catch ( err ) {
			setError( err.message );
		}
	};

	const handleCancel = async () => {
		if ( ! currentJob?.id ) {
			onClose();
			return;
		}
		if ( ! window.confirm( __( 'Are you sure you want to cancel the current backup?', 'migrate-lift' ) ) ) {
			return;
		}

		isCancellingRef.current = true;
		try {
			await cancelJob( currentJob.id );
			setIsRunning( false );
			jobRunnerActiveRef.current = false;
			onClose();
		} catch ( err ) {
			setError( err.message );
		} finally {
			isCancellingRef.current = false;
		}
	};

	if ( ! isOpen ) return null;

	const isCompleted = phase === 'completed' || !! completedOutput;

	// Calculate phase index
	const currentPhaseIndex = PHASES.findIndex( ( p ) => p.id === phase );

	return (
		<div className="ml-slide-drawer-backdrop" onClick={ onClose } role="presentation">
			<div
				className="ml-drawer-panel"
				onClick={ ( e ) => e.stopPropagation() }
				role="dialog"
				aria-modal="true"
				aria-labelledby="ml-job-drawer-title"
			>
				{ /* Drawer Header */ }
				<div className="ml-drawer-header">
					<div style={ { display: 'flex', alignItems: 'center', gap: 10 } }>
						<div className="ml-drawer-icon-box">
							<IconBackup />
						</div>
						<div>
							<h3 id="ml-job-drawer-title" className="ml-drawer-title">
								{ isCompleted
									? __( 'Backup Complete', 'migrate-lift' )
									: __( 'Creating Full Site Backup', 'migrate-lift' ) }
							</h3>
							<div className="ml-drawer-subtitle">
								{ currentJob?.id ? `Job ID: ${ currentJob.id }` : __( 'WordPress snapshot engine', 'migrate-lift' ) }
							</div>
						</div>
					</div>
					<button
						type="button"
						className="ml-icon-btn"
						onClick={ onClose }
						aria-label={ __( 'Close drawer', 'migrate-lift' ) }
					>
						<IconClose style={ { width: 18, height: 18 } } />
					</button>
				</div>

				{ /* Drawer Body */ }
				<div className="ml-drawer-body">
					{ error ? (
						<div className="ml-job-error-box">
							<div style={ { display: 'flex', alignItems: 'center', gap: 10, color: 'var(--ml-red)', marginBottom: 8 } }>
								<IconAlertTriangle />
								<strong style={ { fontSize: 14 } }>{ __( 'Backup Interrupted', 'migrate-lift' ) }</strong>
							</div>
							<p style={ { fontSize: 13, color: 'var(--ml-ink-secondary)', margin: '0 0 16px' } }>
								{ error }
							</p>
							<div style={ { display: 'flex', gap: 10 } }>
								<button
									type="button"
									className="ml-btn primary"
									onClick={ startBackupExecution }
								>
									<IconRefresh />
									<span>{ __( 'Retry Backup', 'migrate-lift' ) }</span>
								</button>
								<button
									type="button"
									className="ml-btn"
									onClick={ onClose }
								>
									{ __( 'Dismiss', 'migrate-lift' ) }
								</button>
							</div>
						</div>
					) : isCompleted ? (
						/* Completed Success Card */
						<div className="ml-job-success-card">
							<div className="ml-job-success-header">
								<div className="ml-success-check-badge">
									<IconCheck />
								</div>
								<h4>{ __( 'Snapshot Stored & Verified', 'migrate-lift' ) }</h4>
								<p>{ __( 'Your archive was created and its integrity checksum verified successfully.', 'migrate-lift' ) }</p>
							</div>

							<div className="ml-job-metrics-summary">
								<div className="ml-job-metric-tile">
									<span className="ml-job-metric-label">{ __( 'Archive File', 'migrate-lift' ) }</span>
									<strong className="ml-job-metric-value monospace">
										{ completedOutput?.archive || __( 'backup.tgz', 'migrate-lift' ) }
									</strong>
								</div>
								<div className="ml-job-metric-tile">
									<span className="ml-job-metric-label">{ __( 'Final Size', 'migrate-lift' ) }</span>
									<strong className="ml-job-metric-value">
										{ formatBytes( completedOutput?.size ) }
									</strong>
								</div>
								<div className="ml-job-metric-tile">
									<span className="ml-job-metric-label">{ __( 'Raw Source Estimate', 'migrate-lift' ) }</span>
									<strong className="ml-job-metric-value">
										{ formatBytes( completedOutput?.uncompressed_estimate ) }
									</strong>
								</div>
								<div className="ml-job-metric-tile">
									<span className="ml-job-metric-label">{ __( 'Space Saved', 'migrate-lift' ) }</span>
									<strong className="ml-job-metric-value" style={ { color: 'var(--ml-teal)' } }>
										{ completedOutput?.reduction_percentage ? `${ completedOutput.reduction_percentage.toFixed( 1 ) }%` : '0%' }
									</strong>
								</div>
							</div>

							<div className="ml-job-actions-row">
								{ completedOutput?.download_url && (
									<a
										href={ completedOutput.download_url }
										className="ml-btn primary"
										download
									>
										<IconDownload />
										<span>{ __( 'Download Archive', 'migrate-lift' ) }</span>
									</a>
								)}
								{ onViewDetails && completedOutput?.archive && (
									<button
										type="button"
										className="ml-btn"
										onClick={ () => onViewDetails( completedOutput.archive ) }
									>
										<span>{ __( 'View Manifest Details', 'migrate-lift' ) }</span>
									</button>
								)}
							</div>
						</div>
					) : (
						/* In Progress View */
						<div className="ml-job-progress-section">
							<div className="ml-job-bar-box">
								<div style={ { display: 'flex', justifyContent: 'space-between', marginBottom: 8 } }>
									<span style={ { fontSize: 13, fontWeight: 700, color: 'var(--ml-ink)' } }>
										{ message || __( 'Processing…', 'migrate-lift' ) }
									</span>
									<span style={ { fontSize: 13, fontWeight: 800, color: 'var(--ml-teal)' } }>
										{ Math.round( progress ) }%
									</span>
								</div>
								<ProgressBar progress={ progress } />
							</div>

							{ /* Stepper List */ }
							<div className="ml-stepper-list">
								{ PHASES.map( ( p, idx ) => {
									const isPassed = currentPhaseIndex > idx;
									const isCurrent = currentPhaseIndex === idx;
									return (
										<div
											key={ p.id }
											className={ `ml-step-item ${ isPassed ? 'done' : '' } ${ isCurrent ? 'current' : '' }` }
										>
											<div className="ml-step-dot">
												{ isPassed ? <IconCheck style={ { width: 12, height: 12 } } /> : ( idx + 1 ) }
											</div>
											<div className="ml-step-content">
												<div className="ml-step-title">{ p.label }</div>
												{ isCurrent && (
													<div className="ml-step-subtitle">{ message }</div>
												)}
											</div>
										</div>
									);
								} ) }
							</div>
						</div>
					) }
				</div>

				{ /* Drawer Footer */ }
				<div className="ml-drawer-footer">
					{ isCompleted ? (
						<button
							type="button"
							className="ml-btn"
							onClick={ onClose }
							style={ { width: '100%' } }
						>
							{ __( 'Done', 'migrate-lift' ) }
						</button>
					) : (
						<div style={ { display: 'flex', justifyContent: 'space-between', width: '100%', gap: 10 } }>
							<button
								type="button"
								className="ml-btn"
								onClick={ onClose }
								title={ __( 'Keep running in background without closing job', 'migrate-lift' ) }
							>
								{ __( 'Run in Background', 'migrate-lift' ) }
							</button>

							<div style={ { display: 'flex', gap: 8 } }>
								<button
									type="button"
									className="ml-btn"
									onClick={ handlePauseResume }
									disabled={ ! isRunning }
								>
									{ isPaused ? __( 'Resume', 'migrate-lift' ) : __( 'Pause', 'migrate-lift' ) }
								</button>
								<button
									type="button"
									className="ml-btn danger"
									onClick={ handleCancel }
									disabled={ ! isRunning }
								>
									{ __( 'Cancel', 'migrate-lift' ) }
								</button>
							</div>
						</div>
					) }
				</div>
			</div>
		</div>
	);
}

