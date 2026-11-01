/**
 * ActivityView Component
 * Integrated Job Engine activity audit trail and verification panel (Part 04).
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import PageHeader from '../components/common/PageHeader';
import SectionCard from '../components/common/SectionCard';
import EmptyState from '../components/common/EmptyState';
import StatusPill from '../components/common/StatusPill';
import InlineNotice from '../components/common/InlineNotice';
import ProgressBar from '../components/common/ProgressBar';
import ConfirmDialog from '../components/common/ConfirmDialog';
import {
	IconActivity,
	IconCheck,
	IconAlertTriangle,
	IconClock,
	IconRefresh,
	IconTrash,
	IconShield,
} from '../components/common/Icons';
import {
	getActivity,
	clearActivity,
	getJobs,
	createJob,
	stepJob,
	pauseJob,
	resumeJob,
	cancelJob,
} from '../utils/jobApi';

export default function ActivityView() {
	const [ activityItems, setActivityItems ] = useState( [] );
	const [ totalActivity, setTotalActivity ] = useState( 0 );
	const [ jobs, setJobs ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorNotice, setErrorNotice ] = useState( null );
	const [ successNotice, setSuccessNotice ] = useState( null );

	// Filter state
	const [ searchFilter, setSearchFilter ] = useState( '' );
	const [ levelFilter, setLevelFilter ] = useState( 'all' );

	// Clear logs modal state
	const [ isClearModalOpen, setIsClearModalOpen ] = useState( false );
	const [ isClearing, setIsClearing ] = useState( false );

	// Test Job Runner State
	const [ activeJob, setActiveJob ] = useState( null );
	const [ isStepping, setIsStepping ] = useState( false );
	const [ isAutoRunning, setIsAutoRunning ] = useState( false );
	const autoRunRef = useRef( false );

	// Fetch data
	const loadData = useCallback( async ( silent = false ) => {
		if ( ! silent ) setIsLoading( true );
		setErrorNotice( null );

		try {
			const [ activityRes, jobsRes ] = await Promise.all( [
				getActivity( {
					search: searchFilter,
					level: levelFilter,
					limit: 100,
				} ),
				getJobs( { limit: 20 } ),
			] );

			const items = ( activityRes && activityRes.items ) || [];
			setActivityItems( items );
			setTotalActivity( ( activityRes && activityRes.total ) || items.length );

			const jobsList = ( jobsRes && jobsRes.jobs ) || [];
			setJobs( jobsList );

			// If active job is being tracked, update it from fresh list
			if ( activeJob ) {
				const updated = jobsList.find( ( j ) => j.id === activeJob.id );
				if ( updated ) {
					setActiveJob( updated );
				}
			}
		} catch ( err ) {
			setErrorNotice( err.message || __( 'Failed to load activity logs.', 'migrate-lift' ) );
		} finally {
			if ( ! silent ) setIsLoading( false );
		}
	}, [ searchFilter, levelFilter, activeJob ] );

	useEffect( () => {
		loadData();
	}, [ searchFilter, levelFilter ] );

	// Handle clearing activity logs
	const handleClearActivity = async () => {
		setIsClearing( true );
		try {
			await clearActivity();
			setIsClearModalOpen( false );
			setSuccessNotice( __( 'Activity audit log cleared successfully.', 'migrate-lift' ) );
			loadData();
		} catch ( err ) {
			setErrorNotice( err.message || __( 'Failed to clear activity log.', 'migrate-lift' ) );
		} finally {
			setIsClearing( false );
		}
	};

	// Start demonstration non-destructive test job
	const handleStartTestJob = async () => {
		setErrorNotice( null );
		setSuccessNotice( null );
		setIsStepping( true );

		try {
			const idempotencyKey = `test_${ Date.now() }`;
			const newJob = await createJob( 'test_job', { items_total: 20 }, idempotencyKey );
			setActiveJob( newJob );
			setSuccessNotice(
				sprintf(
					__( 'Demonstration Test Job created (#%s). You can now step through batches or auto-run.', 'migrate-lift' ),
					newJob.id
				)
			);
			await loadData( true );
		} catch ( err ) {
			setErrorNotice( err.message || __( 'Failed to create test job.', 'migrate-lift' ) );
		} finally {
			setIsStepping( false );
		}
	};

	// Step current active job forward
	const handleStepJob = async () => {
		if ( ! activeJob || isStepping ) return;
		setIsStepping( true );
		setErrorNotice( null );

		try {
			const result = await stepJob( activeJob.id );
			if ( result && result.job ) {
				setActiveJob( result.job );
			}
			await loadData( true );
		} catch ( err ) {
			setErrorNotice( err.message || __( 'Failed to step job.', 'migrate-lift' ) );
			setIsAutoRunning( false );
			autoRunRef.current = false;
		} finally {
			setIsStepping( false );
		}
	};

	// Auto-run test job
	const handleToggleAutoRun = async () => {
		if ( isAutoRunning ) {
			// Stop auto-run
			autoRunRef.current = false;
			setIsAutoRunning( false );
			return;
		}

		if ( ! activeJob ) return;
		autoRunRef.current = true;
		setIsAutoRunning( true );

		let currentJobId = activeJob.id;
		while ( autoRunRef.current ) {
			try {
				const result = await stepJob( currentJobId );
				if ( ! result || ! autoRunRef.current ) break;

				if ( result.job ) {
					setActiveJob( result.job );
				}

				if ( result.done || result.job?.state === 'completed' || result.job?.state === 'failed' ) {
					autoRunRef.current = false;
					setIsAutoRunning( false );
					break;
				}

				// Small delay between batches to make execution visible
				await new Promise( ( resolve ) => setTimeout( resolve, 600 ) );
			} catch ( err ) {
				setErrorNotice( err.message || __( 'Auto-run stopped due to error.', 'migrate-lift' ) );
				autoRunRef.current = false;
				setIsAutoRunning( false );
				break;
			}
		}

		loadData( true );
	};

	// Pause active job
	const handlePauseJob = async () => {
		if ( ! activeJob ) return;
		autoRunRef.current = false;
		setIsAutoRunning( false );
		setIsStepping( true );

		try {
			const paused = await pauseJob( activeJob.id );
			setActiveJob( paused );
			await loadData( true );
		} catch ( err ) {
			setErrorNotice( err.message || __( 'Failed to pause job.', 'migrate-lift' ) );
		} finally {
			setIsStepping( false );
		}
	};

	// Resume paused job
	const handleResumeJob = async () => {
		if ( ! activeJob ) return;
		setIsStepping( true );

		try {
			const resumed = await resumeJob( activeJob.id );
			setActiveJob( resumed );
			await loadData( true );
		} catch ( err ) {
			setErrorNotice( err.message || __( 'Failed to resume job.', 'migrate-lift' ) );
		} finally {
			setIsStepping( false );
		}
	};

	// Cancel active job
	const handleCancelJob = async () => {
		if ( ! activeJob ) return;
		autoRunRef.current = false;
		setIsAutoRunning( false );
		setIsStepping( true );

		try {
			const cancelled = await cancelJob( activeJob.id );
			setActiveJob( cancelled );
			await loadData( true );
		} catch ( err ) {
			setErrorNotice( err.message || __( 'Failed to cancel job.', 'migrate-lift' ) );
		} finally {
			setIsStepping( false );
		}
	};

	// Helper to format level status pill
	const getLevelStatus = ( level ) => {
		switch ( level ) {
			case 'success':
				return 'success';
			case 'warning':
				return 'warning';
			case 'error':
				return 'error';
			case 'info':
			default:
				return 'info';
		}
	};

	const getJobStateStatus = ( state ) => {
		switch ( state ) {
			case 'completed':
				return 'success';
			case 'running':
				return 'info';
			case 'paused':
				return 'warning';
			case 'cancel-requested':
			case 'failed':
				return 'error';
			case 'queued':
			default:
				return 'neutral';
		}
	};

	return (
		<div className="ml-activity-view">
			<PageHeader
				overline={ __( 'Audit Trail & Telemetry', 'migrate-lift' ) }
				title={ __( 'Activity & Job Engine', 'migrate-lift' ) }
				description={ __(
					'Structured operational audit logs, lease-locked concurrency tracking, and atomic batch checkpoint telemetry.',
					'migrate-lift'
				) }
				actions={
					<div style={ { display: 'flex', gap: '8px' } }>
						<button
							type="button"
							className="ml-btn ml-btn-secondary"
							onClick={ () => loadData() }
							disabled={ isLoading }
							aria-label={ __( 'Refresh logs', 'migrate-lift' ) }
						>
							<IconRefresh />
							<span>{ __( 'Refresh', 'migrate-lift' ) }</span>
						</button>
						<button
							type="button"
							className="ml-btn ml-btn-secondary"
							onClick={ () => setIsClearModalOpen( true ) }
							disabled={ isLoading || activityItems.length === 0 }
							style={ { color: 'var(--ml-rose, #e11d48)' } }
						>
							<IconTrash />
							<span>{ __( 'Clear Log', 'migrate-lift' ) }</span>
						</button>
					</div>
				}
			/>

			{ errorNotice && (
				<InlineNotice status="error" title={ __( 'Notice', 'migrate-lift' ) }>
					{ errorNotice }
				</InlineNotice>
			) }

			{ successNotice && (
				<InlineNotice status="success" title={ __( 'Success', 'migrate-lift' ) }>
					{ successNotice }
				</InlineNotice>
			) }

			{ /* Interactive Non-Destructive Job Engine Verification Panel */ }
			<SectionCard
				title={ __( 'Job Engine Verification (Non-Destructive Demo)', 'migrate-lift' ) }
				subtitle={ __(
					'Demonstrates time-budgeted batching, lease claims, atomic checkpointing, pause/resume, and safe process recovery without touching production files.',
					'migrate-lift'
				) }
				headerAction={
					! activeJob || activeJob.state === 'completed' || activeJob.state === 'failed' ? (
						<button
							type="button"
							className="ml-btn ml-btn-primary"
							onClick={ handleStartTestJob }
							disabled={ isStepping }
						>
							<IconActivity />
							<span>{ __( 'Launch Non-Destructive Test Job', 'migrate-lift' ) }</span>
						</button>
					) : null
				}
			>
				{ activeJob ? (
					<div className="ml-test-job-panel" style={ { display: 'flex', flexDirection: 'column', gap: '16px' } }>
						<div
							style={ {
								display: 'flex',
								justifyContent: 'space-between',
								alignItems: 'center',
								flexWrap: 'wrap',
								gap: '12px',
								background: '#f8fafc',
								padding: '12px 16px',
								borderRadius: '8px',
								border: '1px solid #e2e8f0',
							} }
						>
							<div>
								<div style={ { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' } }>
									<span style={ { fontWeight: 700, fontSize: '13px', color: 'var(--ml-ink)' } }>
										{ activeJob.id }
									</span>
									<StatusPill
										status={ getJobStateStatus( activeJob.state ) }
										label={ activeJob.state.toUpperCase() }
									/>
									<span style={ { fontSize: '11px', color: 'var(--ml-muted)' } }>
										{ __( 'Kind:', 'migrate-lift' ) } { activeJob.kind }
									</span>
								</div>
								<div style={ { fontSize: '12px', color: 'var(--ml-muted)' } }>
									{ activeJob.diagnostic_detail || activeJob.phase }
								</div>
							</div>

							{ /* Control Buttons */ }
							<div style={ { display: 'flex', gap: '8px', flexWrap: 'wrap' } }>
								{ activeJob.state === 'running' || activeJob.state === 'queued' ? (
									<>
										<button
											type="button"
											className="ml-btn ml-btn-secondary"
											onClick={ handleStepJob }
											disabled={ isStepping || isAutoRunning }
										>
											{ __( 'Step Single Batch', 'migrate-lift' ) }
										</button>
										<button
											type="button"
											className={ `ml-btn ${ isAutoRunning ? 'ml-btn-secondary' : 'ml-btn-primary' }` }
											onClick={ handleToggleAutoRun }
											disabled={ isStepping }
										>
											{ isAutoRunning ? __( 'Pause Auto-Run', 'migrate-lift' ) : __( 'Auto-Run to Completion', 'migrate-lift' ) }
										</button>
										<button
											type="button"
											className="ml-btn ml-btn-secondary"
											onClick={ handlePauseJob }
											disabled={ isStepping || isAutoRunning }
										>
											{ __( 'Pause Job', 'migrate-lift' ) }
										</button>
										<button
											type="button"
											className="ml-btn ml-btn-secondary"
											onClick={ handleCancelJob }
											disabled={ isStepping }
											style={ { color: '#e11d48' } }
										>
											{ __( 'Cancel', 'migrate-lift' ) }
										</button>
									</>
								) : null }

								{ activeJob.state === 'paused' ? (
									<>
										<button
											type="button"
											className="ml-btn ml-btn-primary"
											onClick={ handleResumeJob }
											disabled={ isStepping }
										>
											{ __( 'Resume Job', 'migrate-lift' ) }
										</button>
										<button
											type="button"
											className="ml-btn ml-btn-secondary"
											onClick={ handleCancelJob }
											disabled={ isStepping }
											style={ { color: '#e11d48' } }
										>
											{ __( 'Cancel', 'migrate-lift' ) }
										</button>
									</>
								) : null }

								{ activeJob.state === 'completed' || activeJob.state === 'failed' ? (
									<button
										type="button"
										className="ml-btn ml-btn-secondary"
										onClick={ handleStartTestJob }
										disabled={ isStepping }
									>
										{ __( 'Run Another Test Job', 'migrate-lift' ) }
									</button>
								) : null }
							</div>
						</div>

						{ /* Progress Bar */ }
						<ProgressBar
							value={ activeJob.percentage || 0 }
							label={ sprintf(
								__( 'Checkpoint: Item #%d of %d (Batch %d)', 'migrate-lift' ),
								activeJob.checkpoint?.cursor || 0,
								activeJob.counts?.items_total || 20,
								activeJob.checkpoint?.batch_index || 0
							) }
							caption={
								activeJob.lock_token
									? sprintf( __( 'Lease claimed by %s (Safe Concurrency Locked)', 'migrate-lift' ), activeJob.lock_token )
									: __( 'Idle between batches (Ready for next request or worker claim)', 'migrate-lift' )
							}
							status={ activeJob.state === 'failed' ? 'error' : activeJob.state === 'completed' ? 'success' : 'primary' }
						/>
					</div>
				) : (
					<div style={ { padding: '8px 0' } }>
						<p style={ { margin: 0, fontSize: '13px', color: 'var(--ml-muted)', lineHeight: '1.5' } }>
							{ __(
								'The MigrateLift Job Engine breaks long operations into small, time-budgeted execution slices. Each chunk atomically checkpoints its cursor pointer and holds an expiring mutual exclusion lease, allowing safe resumption after server timeouts or worker restarts.',
								'migrate-lift'
							) }
						</p>
					</div>
				) }
			</SectionCard>

			{ /* Filter Toolbar */ }
			<div
				className="ml-activity-toolbar"
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					gap: '12px',
					flexWrap: 'wrap',
					margin: '24px 0 16px',
				} }
			>
				<div style={ { display: 'flex', alignItems: 'center', gap: '8px', flex: '1', maxWidth: '380px' } }>
					<input
						type="search"
						className="ml-search-input"
						placeholder={ __( 'Search audit events, actions, or job IDs…', 'migrate-lift' ) }
						value={ searchFilter }
						onChange={ ( e ) => setSearchFilter( e.target.value ) }
						style={ {
							width: '100%',
							padding: '7px 12px',
							fontSize: '12px',
							border: '1px solid #cbd5e1',
							borderRadius: '6px',
							background: '#ffffff',
						} }
					/>
				</div>

				<div style={ { display: 'flex', alignItems: 'center', gap: '6px' } }>
					<span style={ { fontSize: '12px', fontWeight: 600, color: 'var(--ml-muted)' } }>
						{ __( 'Filter:', 'migrate-lift' ) }
					</span>
					{ [ 'all', 'info', 'success', 'warning', 'error' ].map( ( level ) => (
						<button
							key={ level }
							type="button"
							className={ `ml-filter-btn ${ levelFilter === level ? 'is-active' : '' }` }
							onClick={ () => setLevelFilter( level ) }
							style={ {
								padding: '4px 10px',
								fontSize: '11px',
								fontWeight: 600,
								borderRadius: '4px',
								border: levelFilter === level ? '1px solid var(--ml-teal, #0d9488)' : '1px solid #e2e8f0',
								background: levelFilter === level ? '#e6f7f3' : '#ffffff',
								color: levelFilter === level ? 'var(--ml-teal, #0d9488)' : 'var(--ml-muted)',
								cursor: 'pointer',
								textTransform: 'capitalize',
							} }
						>
							{ level }
						</button>
					) ) }
				</div>
			</div>

			{ /* Activity Timeline Section */ }
			<SectionCard
				title={ sprintf( __( 'Operational Audit Stream (%d events)', 'migrate-lift' ), totalActivity ) }
				subtitle={ __(
					'Sanitized chronological event log with automatic secret redaction (no credentials, tokens, raw SQL, or sensitive server paths).',
					'migrate-lift'
				) }
			>
				{ activityItems.length > 0 ? (
					<div className="ml-timeline">
						{ activityItems.map( ( item ) => (
							<div key={ item.id } className="ml-timeline-item">
								<div className={ `ml-timeline-dot ${ item.level || 'info' }` } />
								<div className="ml-timeline-body">
									<div className="ml-timeline-header">
										<div style={ { display: 'flex', alignItems: 'center', gap: '8px' } }>
											<span className="ml-timeline-title">
												{ ucwords( ( item.action || 'event' ).replace( /_/g, ' ' ) ) }
											</span>
											<StatusPill
												status={ getLevelStatus( item.level ) }
												label={ ( item.level || 'info' ).toUpperCase() }
												showDot={ false }
											/>
											{ item.job_id && (
												<span
													style={ {
														fontSize: '11px',
														fontFamily: 'monospace',
														background: '#f1f5f9',
														padding: '1px 5px',
														borderRadius: '3px',
														color: '#475569',
													} }
												>
													{ item.job_id }
												</span>
											) }
										</div>
										<span className="ml-timeline-time">{ item.date || item.timestamp }</span>
									</div>
									<p className="ml-timeline-desc">{ item.message }</p>
								</div>
							</div>
						) ) }
					</div>
				) : (
					<EmptyState
						icon={ <IconActivity /> }
						title={
							searchFilter || levelFilter !== 'all'
								? __( 'No matching audit events found', 'migrate-lift' )
								: __( 'No activity recorded yet', 'migrate-lift' )
						}
						description={
							searchFilter || levelFilter !== 'all'
								? __( 'Try clearing or modifying your search filter keywords.', 'migrate-lift' )
								: __(
									'Audit events are recorded when backup, restore, or migration background jobs run. Launch the test job above to verify logging.',
									'migrate-lift'
								)
						}
						primaryAction={
							searchFilter || levelFilter !== 'all' ? (
								<button
									type="button"
									className="ml-btn ml-btn-secondary"
									onClick={ () => {
										setSearchFilter( '' );
										setLevelFilter( 'all' );
									} }
								>
									{ __( 'Clear Filters', 'migrate-lift' ) }
								</button>
							) : null
						}
					/>
				) }
			</SectionCard>

			{ /* Registered Jobs History */ }
			{ jobs.length > 0 && (
				<div style={ { marginTop: '24px' } }>
					<SectionCard
						title={ __( 'Background Jobs Registry', 'migrate-lift' ) }
						subtitle={ __( 'Persistent job state files stored safely in wp-content/migrate-lift-files/jobs/', 'migrate-lift' ) }
					>
						<div style={ { overflowX: 'auto' } }>
							<table
								className="ml-table"
								style={ {
									width: '100%',
									borderCollapse: 'collapse',
									fontSize: '12px',
									textAlign: 'left',
								} }
							>
								<thead>
									<tr style={ { borderBottom: '1px solid #e2e8f0', color: 'var(--ml-muted)' } }>
										<th style={ { padding: '8px 12px' } }>{ __( 'Job ID', 'migrate-lift' ) }</th>
										<th style={ { padding: '8px 12px' } }>{ __( 'Kind', 'migrate-lift' ) }</th>
										<th style={ { padding: '8px 12px' } }>{ __( 'State', 'migrate-lift' ) }</th>
										<th style={ { padding: '8px 12px' } }>{ __( 'Progress', 'migrate-lift' ) }</th>
										<th style={ { padding: '8px 12px' } }>{ __( 'Diagnostic Detail', 'migrate-lift' ) }</th>
										<th style={ { padding: '8px 12px' } }>{ __( 'Actions', 'migrate-lift' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ jobs.map( ( j ) => (
										<tr key={ j.id } style={ { borderBottom: '1px solid #f1f5f9' } }>
											<td style={ { padding: '8px 12px', fontFamily: 'monospace' } }>{ j.id }</td>
											<td style={ { padding: '8px 12px' } }>{ j.kind }</td>
											<td style={ { padding: '8px 12px' } }>
												<StatusPill status={ getJobStateStatus( j.state ) } label={ j.state } />
											</td>
											<td style={ { padding: '8px 12px' } }>{ j.percentage || 0 }%</td>
											<td style={ { padding: '8px 12px', color: 'var(--ml-muted)', maxWidth: '280px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
												{ j.diagnostic_detail || '—' }
											</td>
											<td style={ { padding: '8px 12px' } }>
												<button
													type="button"
													className="ml-btn ml-btn-secondary"
													style={ { padding: '2px 8px', fontSize: '11px' } }
													onClick={ () => {
														setActiveJob( j );
														window.scrollTo( { top: 0, behavior: 'smooth' } );
													} }
												>
													{ __( 'Inspect / Control', 'migrate-lift' ) }
												</button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					</SectionCard>
				</div>
			) }

			{ /* Confirm Clear Dialog */ }
			<ConfirmDialog
				isOpen={ isClearModalOpen }
				title={ __( 'Clear Activity Logs?', 'migrate-lift' ) }
				description={ __(
					'This will permanently delete the operational audit log file. Registered background jobs will not be deleted.',
					'migrate-lift'
				) }
				confirmLabel={ __( 'Clear Logs', 'migrate-lift' ) }
				cancelLabel={ __( 'Keep Logs', 'migrate-lift' ) }
				isDestructive={ true }
				isBusy={ isClearing }
				onConfirm={ handleClearActivity }
				onCancel={ () => setIsClearModalOpen( false ) }
			/>
		</div>
	);
}

function ucwords( str ) {
	return ( str || '' ).replace( /\b[a-z]/g, ( letter ) => letter.toUpperCase() );
}
