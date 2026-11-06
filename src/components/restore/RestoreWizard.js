/**
 * RestoreWizard Component (Restore Preflight, File & Database Replacement)
 *
 * Implements the 7-step guided restore wizard:
 * 1. Select backup
 * 2. Verify archive
 * 3. Destination preflight
 * 4. Choose replacement strategy (File policies, Database policies, Diff preview)
 * 5. Confirm (requires explicit hostname typing)
 * 6. Execute (Recovery checkpoint snapshot, file replacement, database staged swap)
 * 7. Verify & report (Restoration results, recovery snapshot, session status)
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Spinner,
	Notice,
	TextControl,
	CheckboxControl,
	RadioControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import StatusPill from '../common/StatusPill';
import InlineNotice from '../common/InlineNotice';
import {
	IconCheck,
	IconAlertTriangle,
	IconShield,
	IconHarddrive,
	IconServer,
	IconDownload,
	IconArrowRight,
	IconRefresh,
} from '../common/Icons';
import {
	fetchArchives,
	verifyBackup,
	runRestorePreflight,
	fetchRestoreDryRun,
	createRecoveryCheckpoint,
	fetchRecoveryStatus,
	fetchRestoreDiffPreview,
	executeRestoreWizard,
	formatBytes,
} from '../../utils/api';

const cfg = ( typeof window !== 'undefined' && window.migrateLift ) || {};

export default function RestoreWizard( { initialArchive = null, onComplete } ) {
	const [ currentStep, setCurrentStep ] = useState( 1 );
	const [ archives, setArchives ] = useState( [] );
	const [ loadingArchives, setLoadingArchives ] = useState( false );
	const [ selectedArchive, setSelectedArchive ] = useState( initialArchive );

	// Step 2 Verification State
	const [ verifying, setVerifying ] = useState( false );
	const [ verificationReport, setVerificationReport ] = useState( null );
	const [ verifyError, setVerifyError ] = useState( null );

	// Step 3 Preflight State
	const [ runningPreflight, setRunningPreflight ] = useState( false );
	const [ preflightReport, setPreflightReport ] = useState( null );
	const [ preflightError, setPreflightError ] = useState( null );

	// Step 4 Strategy Selection State
	const [ fileMode, setFileMode ] = useState( 'overwrite_matching' );
	const [ dbMode, setDbMode ] = useState( 'replace_mapped' );
	const [ preserveAdmin, setPreserveAdmin ] = useState( true );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ loadingDiff, setLoadingDiff ] = useState( false );
	const [ diffPreview, setDiffPreview ] = useState( null );
	const [ diffError, setDiffError ] = useState( null );

	// Step 5 Confirmation State
	const [ confirmInput, setConfirmInput ] = useState( '' );
	const [ expectedHost, setExpectedHost ] = useState( '' );

	// Step 6 Checkpoint & Execution State
	const [ executingRestore, setExecutingRestore ] = useState( false );
	const [ restorePhase, setRestorePhase ] = useState( '' );
	const [ executionProgress, setExecutionProgress ] = useState( 0 );
	const [ executionError, setExecutionError ] = useState( null );
	const [ checkpointData, setCheckpointData ] = useState( null );

	// Step 7 Results State
	const [ executionResults, setExecutionResults ] = useState( null );

	// Load destination hostname
	useEffect( () => {
		try {
			const url = cfg.home || ( typeof window !== 'undefined' ? window.location.origin : '' );
			if ( url ) {
				const parsed = new URL( url );
				setExpectedHost( parsed.host.toLowerCase() );
			}
		} catch ( _e ) {
			setExpectedHost( 'localhost' );
		}
	}, [] );

	// Load archives list for Step 1
	const loadArchivesList = useCallback( async () => {
		setLoadingArchives( true );
		try {
			const list = await fetchArchives();
			setArchives( list || [] );
			if ( initialArchive && ! selectedArchive ) {
				const match = list.find( ( a ) => ( a.file || a.name ) === initialArchive );
				if ( match ) {
					setSelectedArchive( match );
				}
			}
		} catch ( _err ) {
			// Maintain current list
		} finally {
			setLoadingArchives( false );
		}
	}, [ initialArchive, selectedArchive ] );

	useEffect( () => {
		loadArchivesList();
	}, [ loadArchivesList ] );

	// Step 2: Verify archive
	const handleVerifyStep = async () => {
		if ( ! selectedArchive ) return;
		const fileName = selectedArchive.file || selectedArchive.name;
		setVerifying( true );
		setVerifyError( null );
		try {
			const res = await verifyBackup( fileName );
			setVerificationReport( res.report || res );
			setCurrentStep( 2 );
		} catch ( err ) {
			setVerifyError( err.message || __( 'Archive verification failed.', 'migrate-lift' ) );
		} finally {
			setVerifying( false );
		}
	};

	// Step 3: Run Preflight Checks
	const handlePreflightStep = async () => {
		if ( ! selectedArchive ) return;
		const fileName = selectedArchive.file || selectedArchive.name;
		setRunningPreflight( true );
		setPreflightError( null );
		try {
			const res = await runRestorePreflight( fileName );
			setPreflightReport( res );
			setCurrentStep( 3 );
		} catch ( err ) {
			setPreflightError( err.message || __( 'Preflight inspection failed.', 'migrate-lift' ) );
		} finally {
			setRunningPreflight( false );
		}
	};

	// Fetch Diff Preview for Step 4
	const loadDiffPreview = useCallback( async ( fMode = fileMode, dMode = dbMode ) => {
		if ( ! selectedArchive ) return;
		const fileName = selectedArchive.file || selectedArchive.name;
		setLoadingDiff( true );
		setDiffError( null );
		try {
			const res = await fetchRestoreDiffPreview( fileName, fMode, dMode );
			setDiffPreview( res );
		} catch ( err ) {
			setDiffError( err.message || __( 'Could not generate diff preview.', 'migrate-lift' ) );
		} finally {
			setLoadingDiff( false );
		}
	}, [ selectedArchive, fileMode, dbMode ] );

	// When entering Step 4, automatically load diff preview
	useEffect( () => {
		if ( 4 === currentStep && selectedArchive ) {
			loadDiffPreview();
		}
	}, [ currentStep, selectedArchive, loadDiffPreview ] );

	// Step 6: Execute Restore (Checkpoints -> Staging -> Replacement -> Swap)
	const handleExecuteRestore = async () => {
		if ( ! selectedArchive ) return;
		const fileName = selectedArchive.file || selectedArchive.name;

		setExecutingRestore( true );
		setExecutionError( null );
		setExecutionProgress( 5 );
		setRestorePhase( __( 'Creating verified destination recovery rollback checkpoint…', 'migrate-lift' ) );

		try {
			// Phase 1: Capture safety rollback checkpoint
			const cp = await createRecoveryCheckpoint( ( step ) => {
				setExecutionProgress( 5 + Math.round( ( step.progress || 0 ) * 0.35 ) );
				if ( step.message ) {
					setRestorePhase( step.message );
				}
			} );
			setCheckpointData( cp );

			setExecutionProgress( 40 );
			setRestorePhase( __( 'Extracting archive and staging files in isolated workspace…', 'migrate-lift' ) );

			// Phase 2: Apply file and database replacement
			setExecutionProgress( 65 );
			setRestorePhase( __( 'Staging database tables (mltmp_*) and applying recursive URL search & replace…', 'migrate-lift' ) );

			setExecutionProgress( 85 );
			setRestorePhase( __( 'Entering maintenance mode, performing atomic multi-table rename swap…', 'migrate-lift' ) );

			const execRes = await executeRestoreWizard( fileName, confirmInput, {
				action: 'apply',
				file_mode: fileMode,
				db_mode: dbMode,
				preserve_admin_user: preserveAdmin,
				confirm_delete: confirmDelete,
			} );

			setExecutionProgress( 100 );
			setRestorePhase( __( 'Restoration complete! Finalizing caches and audit trail…', 'migrate-lift' ) );
			setExecutionResults( execRes );

			// Advance to Step 7
			setCurrentStep( 7 );
		} catch ( err ) {
			setExecutionError( err.message || __( 'Restoration failed. Check the activity log for details.', 'migrate-lift' ) );
		} finally {
			setExecutingRestore( false );
		}
	};

	const cleanConfirmInput = confirmInput.trim().toLowerCase().replace( /^https?:\/\//, '' ).replace( /\/$/, '' );
	const isConfirmationMatched = ( cleanConfirmInput === expectedHost );

	const STEPS = [
		{ num: 1, label: __( 'Select', 'migrate-lift' ) },
		{ num: 2, label: __( 'Verify', 'migrate-lift' ) },
		{ num: 3, label: __( 'Preflight', 'migrate-lift' ) },
		{ num: 4, label: __( 'Strategy', 'migrate-lift' ) },
		{ num: 5, label: __( 'Confirm', 'migrate-lift' ) },
		{ num: 6, label: __( 'Checkpoint', 'migrate-lift' ) },
		{ num: 7, label: __( 'Report', 'migrate-lift' ) },
	];

	return (
		<div className="ml-restore-wizard">
			{ /* Wizard Stepper Bar */ }
			<div className="ml-wizard-stepper">
				{ STEPS.map( ( s ) => {
					const isDone = currentStep > s.num;
					const isActive = currentStep === s.num;
					return (
						<div
							key={ s.num }
							className={ `ml-wizard-step-node ${ isActive ? 'ml-step-active' : '' } ${ isDone ? 'ml-step-done' : '' }` }
						>
							<div className="ml-step-circle">
								{ isDone ? <IconCheck style={ { width: 14, height: 14 } } /> : s.num }
							</div>
							<span className="ml-step-label">{ s.label }</span>
						</div>
					);
				} ) }
			</div>

			{ /* STEP 1: SELECT BACKUP */ }
			{ 1 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600 } }>
							{ __( 'Step 1: Select Backup to Restore', 'migrate-lift' ) }
						</h2>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						<p style={ { fontSize: '13.5px', color: 'var(--ml-muted)', marginTop: 0 } }>
							{ __(
								'Choose a verified backup archive from your library. The restore wizard will inspect compatibility, run preflight capacity checks, and generate a safety rollback snapshot.',
								'migrate-lift'
							) }
						</p>

						{ loadingArchives ? (
							<div style={ { display: 'flex', alignItems: 'center', gap: '8px', padding: '24px', color: 'var(--ml-muted)' } }>
								<Spinner />
								<span>{ __( 'Loading backup library…', 'migrate-lift' ) }</span>
							</div>
						) : archives.length === 0 ? (
							<div style={ { padding: '32px', textAlign: 'center', background: 'var(--ml-canvas)', borderRadius: 'var(--ml-radius-md)', border: '1px dashed var(--ml-line)' } }>
								<IconHarddrive style={ { width: 36, height: 36, color: 'var(--ml-muted-light)', margin: '0 auto 8px' } } />
								<div style={ { fontWeight: 600 } }>{ __( 'No backup archives found in library.', 'migrate-lift' ) }</div>
								<div style={ { fontSize: '12.5px', color: 'var(--ml-muted)', marginTop: '4px' } }>
									{ __( 'Create a backup or upload an archive via the Import tab first.', 'migrate-lift' ) }
								</div>
							</div>
						) : (
							<div style={ { display: 'flex', flexDirection: 'column', gap: '10px', marginTop: '16px' } }>
								{ archives.map( ( arch ) => {
									const fileName = arch.file || arch.name;
									const isSelected = selectedArchive && ( selectedArchive.file || selectedArchive.name ) === fileName;
									return (
										<div
											key={ fileName }
											onClick={ () => setSelectedArchive( arch ) }
											style={ {
												border: `2px solid ${ isSelected ? 'var(--ml-teal)' : 'var(--ml-line)' }`,
												background: isSelected ? 'var(--ml-teal-soft)' : 'var(--ml-paper)',
												borderRadius: 'var(--ml-radius-md)',
												padding: '14px 18px',
												display: 'flex',
												alignItems: 'center',
												justifyContent: 'space-between',
												cursor: 'pointer',
												transition: 'all 0.15s ease',
											} }
										>
											<div style={ { display: 'flex', alignItems: 'center', gap: '12px' } }>
												<IconHarddrive style={ { width: 22, height: 22, color: isSelected ? 'var(--ml-teal)' : 'var(--ml-muted)' } } />
												<div>
													<div style={ { fontWeight: 600, color: 'var(--ml-ink)', fontSize: '14px' } }>
														{ fileName }
													</div>
													<div style={ { fontSize: '12.5px', color: 'var(--ml-muted)', marginTop: '2px' } }>
														{ arch.date } • { formatBytes( arch.size ) } • { arch.format?.toUpperCase() || 'TGZ' }
													</div>
												</div>
											</div>
											<StatusPill
												status={ arch.verified ? 'ready' : 'neutral' }
												label={ arch.verified ? __( 'Verified', 'migrate-lift' ) : __( 'Unverified', 'migrate-lift' ) }
											/>
										</div>
									);
								} ) }
							</div>
						) }

						<div style={ { display: 'flex', justifyContent: 'flex-end', marginTop: '24px' } }>
							<Button
								variant="primary"
								disabled={ ! selectedArchive || verifying }
								onClick={ handleVerifyStep }
							>
								{ verifying ? (
									<>
										<Spinner style={ { margin: '0 6px 0 0' } } />
										{ __( 'Verifying Archive…', 'migrate-lift' ) }
									</>
								) : (
									<>
										{ __( 'Proceed to Verify Archive', 'migrate-lift' ) }
										<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
									</>
								) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 2: VERIFY ARCHIVE */ }
			{ 2 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600 } }>
								{ __( 'Step 2: Archive Integrity & Manifest Verification', 'migrate-lift' ) }
							</h2>
							<StatusPill
								status={ verificationReport?.valid ? 'ready' : 'attention' }
								label={ verificationReport?.valid ? __( 'Passed Integrity', 'migrate-lift' ) : __( 'Verification Failed', 'migrate-lift' ) }
							/>
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						{ verifyError && (
							<Notice status="error" isDismissible={ false } style={ { marginBottom: '16px' } }>
								{ verifyError }
							</Notice>
						) }

						<div className="ml-verified-card">
							<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '14px' } }>
								<div style={ { display: 'flex', alignItems: 'center', gap: '8px' } }>
									<IconCheck style={ { width: 22, height: 22, color: 'var(--ml-teal)' } } />
									<span style={ { fontSize: '16px', fontWeight: 700, color: 'var(--ml-teal-deep)' } }>
										{ selectedArchive?.file || selectedArchive?.name }
									</span>
								</div>
								<StatusPill status="success" label={ __( 'Format 2.0 Validated', 'migrate-lift' ) } />
							</div>

							<div className="ml-verified-grid">
								<div className="ml-verified-grid-item">
									<label>{ __( 'Source Home URL', 'migrate-lift' ) }</label>
									<value>{ verificationReport?.manifest?.home || '—' }</value>
								</div>
								<div className="ml-verified-grid-item">
									<label>{ __( 'Database Prefix', 'migrate-lift' ) }</label>
									<value>{ verificationReport?.manifest?.prefix || '—' }</value>
								</div>
								<div className="ml-verified-grid-item">
									<label>{ __( 'WordPress Version', 'migrate-lift' ) }</label>
									<value>{ verificationReport?.manifest?.wp || '—' }</value>
								</div>
								<div className="ml-verified-grid-item">
									<label>{ __( 'PHP Version', 'migrate-lift' ) }</label>
									<value>{ verificationReport?.manifest?.php || '—' }</value>
								</div>
							</div>
						</div>

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '24px' } }>
							<Button variant="secondary" onClick={ () => setCurrentStep( 1 ) }>
								{ __( 'Back', 'migrate-lift' ) }
							</Button>
							<Button
								variant="primary"
								disabled={ ! verificationReport?.valid || runningPreflight }
								onClick={ handlePreflightStep }
							>
								{ runningPreflight ? (
									<>
										<Spinner style={ { margin: '0 6px 0 0' } } />
										{ __( 'Running Preflight Checks…', 'migrate-lift' ) }
									</>
								) : (
									<>
										{ __( 'Proceed to Preflight Checks', 'migrate-lift' ) }
										<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
									</>
								) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 3: DESTINATION PREFLIGHT */ }
			{ 3 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600 } }>
								{ __( 'Step 3: Destination Environment Preflight', 'migrate-lift' ) }
							</h2>
							<StatusPill
								status={ preflightReport?.passed ? 'ready' : 'attention' }
								label={ preflightReport?.passed ? __( 'Preflight Passed', 'migrate-lift' ) : __( 'Blockers Found', 'migrate-lift' ) }
							/>
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						{ /* Blockers Alert */ }
						{ preflightReport?.blockers?.length > 0 && (
							<Notice status="error" isDismissible={ false } style={ { marginBottom: '20px' } }>
								<p style={ { margin: '0 0 6px', fontWeight: 700 } }>
									{ __( 'Restore Blocked: Resolve the following issues before continuing:', 'migrate-lift' ) }
								</p>
								<ul style={ { margin: 0, paddingLeft: '18px' } }>
									{ preflightReport.blockers.map( ( b, i ) => (
										<li key={ i }>{ b }</li>
									) ) }
								</ul>
							</Notice>
						) }

						{ /* Warnings Alert */ }
						{ preflightReport?.warnings?.length > 0 && (
							<Notice status="warning" isDismissible={ false } style={ { marginBottom: '20px' } }>
								<p style={ { margin: '0 0 6px', fontWeight: 600 } }>
									{ __( 'Preflight Warnings (Non-blocking):', 'migrate-lift' ) }
								</p>
								<ul style={ { margin: 0, paddingLeft: '18px' } }>
									{ preflightReport.warnings.map( ( w, i ) => (
										<li key={ i }>{ w }</li>
									) ) }
								</ul>
							</Notice>
						) }

						{ /* Detailed Checks List */ }
						<div style={ { display: 'flex', flexDirection: 'column', gap: '8px' } }>
							{ preflightReport?.checks?.map( ( item ) => {
								const isPass = 'pass' === item.status;
								const isWarn = 'warn' === item.status;
								const isFail = 'fail' === item.status;
								return (
									<div
										key={ item.id }
										style={ {
											padding: '12px 16px',
											background: isFail ? 'var(--ml-red-soft)' : isWarn ? 'var(--ml-amber-soft)' : 'var(--ml-canvas)',
											border: `1px solid ${ isFail ? 'var(--ml-red-border)' : isWarn ? 'var(--ml-amber-border)' : 'var(--ml-line)' }`,
											borderRadius: 'var(--ml-radius-md)',
											display: 'flex',
											alignItems: 'flex-start',
											gap: '12px',
										} }
									>
										<div style={ { marginTop: '2px' } }>
											{ isPass && <IconCheck style={ { width: 18, height: 18, color: 'var(--ml-teal)' } } /> }
											{ isWarn && <IconAlertTriangle style={ { width: 18, height: 18, color: 'var(--ml-amber)' } } /> }
											{ isFail && <IconAlertTriangle style={ { width: 18, height: 18, color: 'var(--ml-red)' } } /> }
										</div>
										<div style={ { flex: 1 } }>
											<div style={ { fontWeight: 600, color: 'var(--ml-ink)', fontSize: '13.5px' } }>
												{ item.title }
											</div>
											<div style={ { fontSize: '12.5px', color: 'var(--ml-ink-secondary)', marginTop: '2px' } }>
												{ item.detail }
											</div>
										</div>
									</div>
								);
							} ) }
						</div>

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '24px' } }>
							<Button variant="secondary" onClick={ () => setCurrentStep( 2 ) }>
								{ __( 'Back', 'migrate-lift' ) }
							</Button>
							<Button
								variant="primary"
								disabled={ ! preflightReport?.passed }
								onClick={ () => setCurrentStep( 4 ) }
							>
								{ __( 'Proceed to Replacement Strategy', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 4: CHOOSE REPLACEMENT STRATEGY (PARTS 11 & 12 UNLOCKED) */ }
			{ 4 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600 } }>
								{ __( 'Step 4: Replacement Strategy & Options', 'migrate-lift' ) }
							</h2>
							<StatusPill status="success" label={ __( 'Safe Staging Enabled', 'migrate-lift' ) } />
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						{ diffError && (
							<Notice status="error" isDismissible onDismiss={ () => setDiffError( null ) } style={ { marginBottom: '16px' } }>
								{ diffError }
							</Notice>
						) }

						{ /* File Policy Section */ }
						<div style={ { border: '1px solid var(--ml-line)', borderRadius: 'var(--ml-radius-md)', padding: '18px', background: 'var(--ml-paper)', marginBottom: '18px' } }>
							<div style={ { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px' } }>
								<IconHarddrive style={ { width: 20, height: 20, color: 'var(--ml-teal)' } } />
								<h3 style={ { margin: 0, fontSize: '15px', fontWeight: 700 } }>
									{ __( 'File Replacement Policy', 'migrate-lift' ) }
								</h3>
							</div>

							<RadioControl
								selected={ fileMode }
								options={ [
									{
										label: __( 'Overwrite matching files, keep destination-only files (Recommended)', 'migrate-lift' ),
										value: 'overwrite_matching',
									},
									{
										label: __( 'Add missing only (Preserve all existing destination files)', 'migrate-lift' ),
										value: 'add_missing',
									},
									{
										label: __( 'Synchronize managed WordPress content (Prunes destination-only files in themes, plugins, uploads)', 'migrate-lift' ),
										value: 'sync_managed',
									},
								] }
								onChange={ ( val ) => {
									setFileMode( val );
									loadDiffPreview( val, dbMode );
								} }
							/>

							{ 'sync_managed' === fileMode && (
								<div style={ { marginTop: '12px', padding: '12px', background: 'var(--ml-amber-soft)', border: '1px solid var(--ml-amber-border)', borderRadius: 'var(--ml-radius-sm)' } }>
									<CheckboxControl
										label={ __( 'I understand and explicitly confirm that destination-only files inside themes, plugins, and uploads will be pruned.', 'migrate-lift' ) }
										checked={ confirmDelete }
										onChange={ setConfirmDelete }
									/>
								</div>
							) }
						</div>

						{ /* Database Policy Section */ }
						<div style={ { border: '1px solid var(--ml-line)', borderRadius: 'var(--ml-radius-md)', padding: '18px', background: 'var(--ml-paper)', marginBottom: '18px' } }>
							<div style={ { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px' } }>
								<IconServer style={ { width: 20, height: 20, color: 'var(--ml-teal)' } } />
								<h3 style={ { margin: 0, fontSize: '15px', fontWeight: 700 } }>
									{ __( 'Database Replacement Policy', 'migrate-lift' ) }
								</h3>
							</div>

							<RadioControl
								selected={ dbMode }
								options={ [
									{
										label: __( 'Replace mapped destination WordPress tables (Recommended: Staged mltmp_ isolation + Atomic swap)', 'migrate-lift' ),
										value: 'replace_mapped',
									},
									{
										label: __( 'Keep destination database (Restore files only, do not touch database)', 'migrate-lift' ),
										value: 'keep_destination',
									},
								] }
								onChange={ ( val ) => {
									setDbMode( val );
									loadDiffPreview( fileMode, val );
								} }
							/>

							{ 'keep_destination' !== dbMode && (
								<div style={ { marginTop: '12px' } }>
									<CheckboxControl
										label={ __( 'Preserve current administrator user account (Prevents session lockout and preserves your current admin login credentials)', 'migrate-lift' ) }
										checked={ preserveAdmin }
										onChange={ setPreserveAdmin }
									/>
								</div>
							) }
						</div>

						{ /* Live Diff Preview Card */ }
						<div style={ { border: '1px solid var(--ml-line)', borderRadius: 'var(--ml-radius-md)', padding: '16px', background: 'var(--ml-canvas)', marginBottom: '20px' } }>
							<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' } }>
								<span style={ { fontWeight: 700, fontSize: '13.5px', color: 'var(--ml-ink)' } }>
									{ __( 'Calculated Diff Preview', 'migrate-lift' ) }
								</span>
								{ loadingDiff && <Spinner /> }
							</div>

							{ diffPreview ? (
								<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '12px' } }>
									<div style={ { background: 'var(--ml-paper)', padding: '10px 14px', borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }>
										<div style={ { fontSize: '12px', color: 'var(--ml-muted)' } }>{ __( 'Files to Create', 'migrate-lift' ) }</div>
										<div style={ { fontSize: '18px', fontWeight: 700, color: 'var(--ml-teal)' } }>
											{ diffPreview.file_diff?.counts?.create ?? 0 }
										</div>
									</div>
									<div style={ { background: 'var(--ml-paper)', padding: '10px 14px', borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }>
										<div style={ { fontSize: '12px', color: 'var(--ml-muted)' } }>{ __( 'Files to Overwrite', 'migrate-lift' ) }</div>
										<div style={ { fontSize: '18px', fontWeight: 700, color: 'var(--ml-ink)' } }>
											{ diffPreview.file_diff?.counts?.update ?? 0 }
										</div>
									</div>
									<div style={ { background: 'var(--ml-paper)', padding: '10px 14px', borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }>
										<div style={ { fontSize: '12px', color: 'var(--ml-muted)' } }>{ __( 'Files to Prune', 'migrate-lift' ) }</div>
										<div style={ { fontSize: '18px', fontWeight: 700, color: diffPreview.file_diff?.counts?.delete > 0 ? 'var(--ml-red)' : 'var(--ml-muted)' } }>
											{ diffPreview.file_diff?.counts?.delete ?? 0 }
										</div>
									</div>
									<div style={ { background: 'var(--ml-paper)', padding: '10px 14px', borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }>
										<div style={ { fontSize: '12px', color: 'var(--ml-muted)' } }>{ __( 'Tables to Swap', 'migrate-lift' ) }</div>
										<div style={ { fontSize: '18px', fontWeight: 700, color: 'var(--ml-ink)' } }>
											{ diffPreview.db_diff?.count_to_replace ?? 0 }
										</div>
									</div>
								</div>
							) : (
								<div style={ { fontSize: '12.5px', color: 'var(--ml-muted)' } }>
									{ __( 'Loading diff calculations…', 'migrate-lift' ) }
								</div>
							) }
						</div>

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '24px' } }>
							<Button variant="secondary" onClick={ () => setCurrentStep( 3 ) }>
								{ __( 'Back', 'migrate-lift' ) }
							</Button>
							<Button
								variant="primary"
								disabled={ 'sync_managed' === fileMode && ( diffPreview?.file_diff?.counts?.delete > 0 ) && ! confirmDelete }
								onClick={ () => setCurrentStep( 5 ) }
							>
								{ __( 'Proceed to Explicit Confirmation', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 5: CONFIRM WITH EXPLICIT HOSTNAME */ }
			{ 5 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600, color: 'var(--ml-red)' } }>
							{ __( 'Step 5: Explicit Site Confirmation', 'migrate-lift' ) }
						</h2>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						<InlineNotice
							status="warning"
							title={ __( 'Destructive Operation Warning', 'migrate-lift' ) }
							style={ { marginBottom: '18px' } }
						>
							{ __(
								'Restoring an archive replaces your existing database tables and wp-content according to your selected policies. In the next step, a verified destination recovery rollback checkpoint will be captured before any files or tables are altered.',
								'migrate-lift'
							) }
						</InlineNotice>

						<div style={ { background: 'var(--ml-canvas)', padding: '16px 20px', borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', margin: '16px 0' } }>
							<p style={ { fontSize: '13.5px', margin: '0 0 12px', fontWeight: 600, color: 'var(--ml-ink)' } }>
								{ sprintf(
									__( 'To confirm, please type your destination site hostname "%s" below:', 'migrate-lift' ),
									expectedHost
								) }
							</p>

							<TextControl
								value={ confirmInput }
								onChange={ setConfirmInput }
								placeholder={ expectedHost }
								style={ { maxWidth: '380px' } }
							/>

							{ confirmInput && ! isConfirmationMatched && (
								<div style={ { color: 'var(--ml-red)', fontSize: '12.5px', marginTop: '6px' } }>
									{ sprintf( __( 'Hostname mismatch. Please type exactly "%s".', 'migrate-lift' ), expectedHost ) }
								</div>
							) }
						</div>

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '24px' } }>
							<Button variant="secondary" onClick={ () => setCurrentStep( 4 ) }>
								{ __( 'Back', 'migrate-lift' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								disabled={ ! isConfirmationMatched }
								onClick={ () => setCurrentStep( 6 ) }
							>
								{ __( 'Confirm & Proceed to Execution', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 6: EXECUTE RESTORATION */ }
			{ 6 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600 } }>
							{ __( 'Step 6: Execution & Checkpoint Rollback Protection', 'migrate-lift' ) }
						</h2>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						<p style={ { fontSize: '13.5px', color: 'var(--ml-ink-secondary)', marginTop: 0 } }>
							{ __(
								'MigrateLift will first capture a verified safety rollback snapshot of your live site, stage incoming files, import database tables into temporary prefixes, and perform the atomic rename cutover.',
								'migrate-lift'
							) }
						</p>

						{ executionError && (
							<Notice status="error" isDismissible onDismiss={ () => setExecutionError( null ) } style={ { marginBottom: '16px' } }>
								{ executionError }
							</Notice>
						) }

						<div style={ { padding: '28px', background: 'var(--ml-canvas)', borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', textAlign: 'center', margin: '20px 0' } }>
							<IconShield style={ { width: 44, height: 44, color: 'var(--ml-teal)', margin: '0 auto 12px' } } />

							{ executingRestore ? (
								<div>
									<h3 style={ { margin: '0 0 8px', fontSize: '16px', fontWeight: 700 } }>
										{ __( 'Executing Safe Restoration…', 'migrate-lift' ) }
									</h3>
									<div style={ { fontSize: '13px', color: 'var(--ml-teal-deep)', marginBottom: '16px' } }>
										{ restorePhase }
									</div>
									<div style={ { width: '100%', height: '8px', background: 'var(--ml-line)', borderRadius: '4px', overflow: 'hidden', maxWidth: '380px', margin: '0 auto' } }>
										<div
											style={ {
												width: `${ executionProgress }%`,
												height: '100%',
												background: 'var(--ml-teal)',
												transition: 'width 0.3s ease',
											} }
										/>
									</div>
								</div>
							) : (
								<div>
									<h3 style={ { margin: '0 0 6px', fontSize: '16px', fontWeight: 700 } }>
										{ __( 'Ready to Restore', 'migrate-lift' ) }
									</h3>
									<p style={ { margin: '0 auto 20px', fontSize: '13px', color: 'var(--ml-muted)', maxWidth: '440px' } }>
										{ __( 'All verifications passed. Click below to begin the safe restore process.', 'migrate-lift' ) }
									</p>
									<Button
										variant="primary"
										isDestructive
										onClick={ handleExecuteRestore }
									>
										{ __( 'Execute Safe Restore Now', 'migrate-lift' ) }
									</Button>
								</div>
							) }
						</div>

						{ ! executingRestore && (
							<div style={ { display: 'flex', justifyContent: 'flex-start', marginTop: '16px' } }>
								<Button variant="secondary" onClick={ () => setCurrentStep( 5 ) }>
									{ __( 'Back', 'migrate-lift' ) }
								</Button>
							</div>
						) }
					</CardBody>
				</Card>
			) }

			{ /* STEP 7: VERIFY & REPORT */ }
			{ 7 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600 } }>
								{ __( 'Step 7: Restoration Complete & Verified', 'migrate-lift' ) }
							</h2>
							<StatusPill status="success" label={ __( 'Restoration Success', 'migrate-lift' ) } />
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						{ /* Checkpoint Shield Banner */ }
						<div className="ml-verified-card" style={ { marginTop: 0 } }>
							<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '12px' } }>
								<div style={ { display: 'flex', alignItems: 'center', gap: '8px' } }>
									<IconShield style={ { width: 22, height: 22, color: 'var(--ml-teal)' } } />
									<span style={ { fontSize: '16px', fontWeight: 700, color: 'var(--ml-teal-deep)' } }>
										{ __( 'Safety Rollback Checkpoint Active & Protected', 'migrate-lift' ) }
									</span>
								</div>
								<StatusPill status="success" label={ __( 'Protected from Pruning', 'migrate-lift' ) } />
							</div>

							<div className="ml-verified-grid">
								<div className="ml-verified-grid-item">
									<label>{ __( 'Rollback Point', 'migrate-lift' ) }</label>
									<value>{ checkpointData?.file || '—' }</value>
								</div>
								<div className="ml-verified-grid-item">
									<label>{ __( 'Snapshot Size', 'migrate-lift' ) }</label>
									<value>{ formatBytes( checkpointData?.size ) }</value>
								</div>
								<div className="ml-verified-grid-item">
									<label>{ __( 'Files Applied', 'migrate-lift' ) }</label>
									<value>{ executionResults?.file_results?.total_applied ?? 0 }</value>
								</div>
								<div className="ml-verified-grid-item">
									<label>{ __( 'Database Tables Swapped', 'migrate-lift' ) }</label>
									<value>{ executionResults?.db_results?.tables_swapped ?? 0 }</value>
								</div>
							</div>
						</div>

						{ /* Session Notification */ }
						{ executionResults?.session_info?.admin_preserved ? (
							<Notice status="success" isDismissible={ false } style={ { margin: '20px 0 0' } }>
								{ __( 'Administrator Account Preserved: Your current administrator login credentials were retained. You remain logged in.', 'migrate-lift' ) }
							</Notice>
						) : (
							<Notice status="info" isDismissible={ false } style={ { margin: '20px 0 0' } }>
								{ __( 'Session Cutover: Database users have been updated from the archive. If your credentials differ, please log in using the administrator account from the restored archive.', 'migrate-lift' ) }
							</Notice>
						) }

						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '24px' } }>
							<Button
								variant="primary"
								onClick={ () => {
									setCurrentStep( 1 );
									if ( onComplete ) onComplete();
								} }
							>
								{ __( 'Finish & Return to Library', 'migrate-lift' ) }
							</Button>
							{ checkpointData?.download && (
								<Button
									variant="tertiary"
									href={ checkpointData.download }
								>
									<IconDownload style={ { width: 14, height: 14, marginRight: '6px' } } />
									{ __( 'Download Recovery Snapshot (.tgz)', 'migrate-lift' ) }
								</Button>
							) }
						</div>
					</CardBody>
				</Card>
			) }
		</div>
	);
}
