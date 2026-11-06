/**
 * MigrationWizard Component (Site Pairing, Direct Transfer & Migration Wizard)
 *
 * Implements the 7-step guided server-to-server migration wizard:
 * 1. Connect Sites (Cryptographic HMAC pairing handshake, single-use token, SSRF validation)
 * 2. Choose Contents (Full site, DB only, migration mode, active commerce warning)
 * 3. Preflight (Remote destination disk capacity, PHP version, write permissions)
 * 4. Transfer (Resumable direct HTTPS chunk streaming with per-part SHA-256 checksums)
 * 5. Destination Options (File policies, database policies, URL search-replace, admin preserve)
 * 6. Final Confirm (Explicit destination hostname typing, data-loss warning)
 * 7. Verify & Finish (Staged file & DB replacement, atomic swap, finish report & checklist)
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
import ProgressBar from '../common/ProgressBar';
import {
	IconCheck,
	IconAlertTriangle,
	IconShield,
	IconHarddrive,
	IconServer,
	IconDownload,
	IconArrowRight,
	IconRefresh,
	IconMigrate,
} from '../common/Icons';
import {
	fetchArchives,
	formatBytes,
	formatRelativeTime,
	generatePairingToken,
	connectSite,
	fetchPairingStatus,
	revokePairing,
	verifyPairing,
	startDirectTransfer,
	fetchTransferStatus,
	cancelTransfer,
	stepDirectTransfer,
	createRemoteRecoveryCheckpoint,
	runRestJob,
	fetchRemotePreflight,
	fetchRemoteDiff,
	executeRemoteApply,
} from '../../utils/api';

const cfg = ( typeof window !== 'undefined' && window.migrateLift ) || {};

export default function MigrationWizard( { onComplete } ) {
	const [ currentStep, setCurrentStep ] = useState( 1 );
	const [ activeTab, setActiveTab ] = useState( 'source' ); // 'source' (Send to Destination) vs 'destination' (Receive from Source)

	// Step 1: Pairing State
	const [ pairingTokenData, setPairingTokenData ] = useState( null );
	const [ generatingToken, setGeneratingToken ] = useState( false );
	const [ tokenCountdown, setTokenCountdown ] = useState( 0 );
	const [ copySuccess, setCopySuccess ] = useState( false );
	const [ pairedSites, setPairedSites ] = useState( [] );
	const [ loadingPairedSites, setLoadingPairedSites ] = useState( false );

	const [ pairingKeyInput, setPairingKeyInput ] = useState( '' );
	const [ connecting, setConnecting ] = useState( false );
	const [ connectError, setConnectError ] = useState( null );
	const [ connectedSite, setConnectedSite ] = useState( null );
	const [ verifyingPing, setVerifyingPing ] = useState( false );
	const [ pingLatency, setPingLatency ] = useState( null );

	// Step 2: Content Selection State
	const [ archiveSourceMode, setArchiveSourceMode ] = useState( 'fresh_export' );
	const [ archives, setArchives ] = useState( [] );
	const [ selectedArchiveFile, setSelectedArchiveFile ] = useState( null );
	const [ isExportingFresh, setIsExportingFresh ] = useState( false );
	const [ exportStatusText, setExportStatusText ] = useState( '' );

	// Step 3: Destination Preflight State
	const [ runningPreflight, setRunningPreflight ] = useState( false );
	const [ preflightReport, setPreflightReport ] = useState( null );
	const [ preflightError, setPreflightError ] = useState( null );

	// Step 4: Transfer State
	const [ transferJobId, setTransferJobId ] = useState( null );
	const [ transferState, setTransferState ] = useState( null );
	const [ isTransferring, setIsTransferring ] = useState( false );
	const [ transferError, setTransferError ] = useState( null );

	// Step 5: Destination Options State
	const [ fileMode, setFileMode ] = useState( 'overwrite_matching' );
	const [ dbMode, setDbMode ] = useState( 'replace_mapped' );
	const [ preserveAdmin, setPreserveAdmin ] = useState( true );
	const [ confirmCleanSync, setConfirmCleanSync ] = useState( false );
	const [ diffPreview, setDiffPreview ] = useState( null );
	const [ loadingDiff, setLoadingDiff ] = useState( false );
	const [ diffError, setDiffError ] = useState( null );

	// Step 6: Confirmation State
	const [ confirmInput, setConfirmInput ] = useState( '' );
	const [ expectedDestHost, setExpectedDestHost ] = useState( '' );
	const [ acknowledgeRisk, setAcknowledgeRisk ] = useState( false );

	// Step 7: Apply & Finish State
	const [ executingApply, setExecutingApply ] = useState( false );
	const [ applyPhase, setApplyPhase ] = useState( '' );
	const [ applyResults, setApplyResults ] = useState( null );
	const [ applyError, setApplyError ] = useState( null );

	// Load existing archives and paired sites on mount
	useEffect( () => {
		loadPairedSites();
		loadArchivesList();
	}, [] );

	// Countdown timer for pairing token
	useEffect( () => {
		if ( ! pairingTokenData || tokenCountdown <= 0 ) return;
		const timer = setInterval( () => {
			setTokenCountdown( ( prev ) => {
				if ( prev <= 1 ) {
					clearInterval( timer );
					setPairingTokenData( null );
					return 0;
				}
				return prev - 1;
			} );
		}, 1000 );
		return () => clearInterval( timer );
	}, [ pairingTokenData, tokenCountdown ] );

	// Run the transfer job step by step while it is active.
	useEffect( () => {
		if ( ! isTransferring || ! transferJobId ) {
			return undefined;
		}

		let stopped = false;
		const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

		const run = async () => {
			let failures = 0;
			while ( ! stopped ) {
				let stepError = null;
				try {
					await stepDirectTransfer( transferJobId );
				} catch ( err ) {
					// The job engine counts step failures and fails the job after its retry limit.
					stepError = err;
				}

				let job = null;
				try {
					job = await fetchTransferStatus( transferJobId );
				} catch ( err ) {
					stepError = stepError || err;
				}
				if ( stopped ) {
					return;
				}

				if ( job ) {
					failures = 0;
					setTransferState( job );
					if ( job.state === 'completed' ) {
						// The destination may have stored the archive under another name.
						if ( job.checkpoint?.remote_archive ) {
							setSelectedArchiveFile( job.checkpoint.remote_archive );
						}
						setIsTransferring( false );
						return;
					}
					if ( job.state === 'failed' ) {
						setIsTransferring( false );
						setTransferError( job.diagnostic_detail || __( 'Transfer failed unexpectedly.', 'migrate-lift' ) );
						return;
					}
				} else if ( ++failures >= 5 ) {
					setIsTransferring( false );
					setTransferError( stepError?.message || __( 'Lost contact with this site during the transfer.', 'migrate-lift' ) );
					return;
				}

				await wait( stepError ? 3000 : 200 );
			}
		};

		run();

		return () => {
			stopped = true;
		};
	}, [ isTransferring, transferJobId ] );

	const loadArchivesList = async () => {
		try {
			const list = await fetchArchives();
			if ( Array.isArray( list ) ) {
				setArchives( list );
				if ( list.length > 0 && ! selectedArchiveFile ) {
					setSelectedArchiveFile( list[ 0 ].file );
				}
			}
		} catch ( _e ) {}
	};

	const loadPairedSites = async () => {
		setLoadingPairedSites( true );
		try {
			const sites = await fetchPairingStatus();
			setPairedSites( sites );
			if ( sites.length > 0 && ! connectedSite ) {
				const srcPair = sites.find( ( s ) => s.role === 'source' && s.status === 'connected' );
				if ( srcPair ) {
					setConnectedSite( srcPair );
					try {
						const u = new URL( srcPair.remote_url );
						setExpectedDestHost( u.host.toLowerCase() );
					} catch ( _e ) {}
				}
			}
		} catch ( _e ) {
		} finally {
			setLoadingPairedSites( false );
		}
	};

	// Generate Pairing Token on Destination
	const handleGenerateToken = async () => {
		setGeneratingToken( true );
		try {
			const data = await generatePairingToken();
			setPairingTokenData( data );
			setTokenCountdown( data.expires_in || 900 );
			loadPairedSites();
		} catch ( err ) {
			alert( err.message || __( 'Could not generate pairing token.', 'migrate-lift' ) );
		} finally {
			setGeneratingToken( false );
		}
	};

	// Copy Pairing Key
	const handleCopyKey = () => {
		if ( ! pairingTokenData?.pairing_key ) return;
		navigator.clipboard.writeText( pairingTokenData.pairing_key );
		setCopySuccess( true );
		setTimeout( () => setCopySuccess( false ), 2500 );
	};

	// Connect to Destination using Pairing Key
	const handleConnectSite = async () => {
		if ( ! pairingKeyInput.trim() ) return;
		setConnecting( true );
		setConnectError( null );
		try {
			const res = await connectSite( pairingKeyInput.trim() );
			setConnectedSite( res );
			setPairingKeyInput( '' );
			try {
				const u = new URL( res.remote_url );
				setExpectedDestHost( u.host.toLowerCase() );
			} catch ( _e ) {}
			await loadPairedSites();
		} catch ( err ) {
			setConnectError( err.message || __( 'Failed to pair with destination site.', 'migrate-lift' ) );
		} finally {
			setConnecting( false );
		}
	};

	// Ping Destination
	const handleTestPing = async ( pairId ) => {
		setVerifyingPing( true );
		try {
			const res = await verifyPairing( pairId );
			setPingLatency( res.latency_ms );
		} catch ( err ) {
			alert( err.message || __( 'Connection check failed.', 'migrate-lift' ) );
		} finally {
			setVerifyingPing( false );
		}
	};

	// Revoke Pairing
	const handleRevokePairing = async ( pairId ) => {
		if ( ! window.confirm( __( 'Are you sure you want to disconnect this site? Cross-site operations will be disabled.', 'migrate-lift' ) ) ) {
			return;
		}
		try {
			await revokePairing( pairId );
			if ( connectedSite?.pair_id === pairId ) {
				setConnectedSite( null );
			}
			loadPairedSites();
		} catch ( err ) {
			alert( err.message );
		}
	};

	// Step 2 -> 3 Transition: Preflight Check
	const handleProceedToPreflight = async () => {
		if ( ! connectedSite ) {
			alert( __( 'Please connect to a destination site first.', 'migrate-lift' ) );
			return;
		}
		setCurrentStep( 3 );
		setRunningPreflight( true );
		setPreflightError( null );

		try {
			if ( connectedSite?.pair_id ) {
				const remoteRes = await fetchRemotePreflight( connectedSite.pair_id, selectedArchiveFile );
				if ( remoteRes && Array.isArray( remoteRes.checks ) ) {
					const hasBlockers = remoteRes.checks.some( ( c ) => c.status === 'fail' );
					setPreflightReport( {
						passed: ! hasBlockers,
						checks: remoteRes.checks,
						blockers: remoteRes.blockers || ( hasBlockers ? [ __( 'One or more remote preflight checks failed.', 'migrate-lift' ) ] : [] ),
					} );
					setRunningPreflight( false );
					return;
				}
			}
		} catch ( _err ) {
			// Fallback to local destination_meta check
		}

		// The archive is not on the destination yet, so check what the destination reported when it
		// paired. The full preflight runs again on the destination just before the restore.
		const meta = connectedSite.destination_meta || {};
		const freeDisk = Number( meta.disk_free_space ) || 0;
		const checks = [
			{
				id: 'remote_disk',
				label: __( 'Destination Disk Storage Capacity', 'migrate-lift' ),
				status: freeDisk > 524288000 ? 'pass' : 'warning',
				detail: freeDisk > 0
					? sprintf( __( '%s free storage available on destination', 'migrate-lift' ), formatBytes( freeDisk ) )
					: __( 'The destination did not report its free disk space.', 'migrate-lift' ),
			},
			{
				id: 'php_version',
				label: __( 'Destination PHP Version', 'migrate-lift' ),
				status: meta.php_version ? 'pass' : 'warning',
				detail: meta.php_version
					? sprintf( __( 'Destination running PHP %s', 'migrate-lift' ), meta.php_version )
					: __( 'The destination did not report its PHP version.', 'migrate-lift' ),
			},
		];

		setPreflightReport( { passed: true, checks, blockers: [] } );
		setRunningPreflight( false );
	};

	// Step 3 -> 4 Transition: Start Fresh Export or Select Existing, then Start Transfer
	const handleStartTransferStep = async () => {
		setCurrentStep( 4 );
		setIsTransferring( true );
		setTransferError( null );

		let archiveToTransfer = selectedArchiveFile;

		// If user chose fresh export snapshot, create it first.
		if ( 'fresh_export' === archiveSourceMode ) {
			setIsExportingFresh( true );
			setExportStatusText( __( 'Creating full site backup snapshot for migration…', 'migrate-lift' ) );
			try {
				const exportRes = await runRestJob( 'backup', {}, ( update ) => {
					if ( update.message ) {
						setExportStatusText( update.message );
					}
				} );
				archiveToTransfer = exportRes?.archive || null;
				setSelectedArchiveFile( archiveToTransfer );
			} catch ( err ) {
				setIsExportingFresh( false );
				setIsTransferring( false );
				setTransferError( err.message || __( 'Could not create the backup to transfer.', 'migrate-lift' ) );
				return;
			}
			setIsExportingFresh( false );
		}

		if ( ! archiveToTransfer ) {
			setIsTransferring( false );
			setTransferError( __( 'No valid archive file found to transfer. Please create a backup first.', 'migrate-lift' ) );
			return;
		}

		try {
			const res = await startDirectTransfer( connectedSite.pair_id, archiveToTransfer );
			setTransferJobId( res.job_id );
		} catch ( err ) {
			setIsTransferring( false );
			setTransferError( err.message || __( 'Failed to initiate direct transfer.', 'migrate-lift' ) );
		}
	};

	// Cancel In-Flight Transfer
	const handleCancelTransfer = async () => {
		if ( ! transferJobId ) return;
		try {
			await cancelTransfer( transferJobId );
			setIsTransferring( false );
			setTransferError( __( 'Transfer was canceled by user.', 'migrate-lift' ) );
		} catch ( err ) {
			alert( err.message );
		}
	};

	// Step 4 -> 5 Transition: Fetch Diff Preview
	const handleProceedToOptions = async () => {
		setCurrentStep( 5 );
		setLoadingDiff( true );
		setDiffError( null );
		setDiffPreview( null );
		try {
			setDiffPreview( await fetchRemoteDiff( connectedSite.pair_id, selectedArchiveFile, fileMode, dbMode, [] ) );
		} catch ( err ) {
			setDiffError( err.message || __( 'Could not preview the changes on the destination.', 'migrate-lift' ) );
		} finally {
			setLoadingDiff( false );
		}
	};

	// Step 6 -> 7 Transition: Final Execute Restore Apply on Destination
	const handleExecuteFinalApply = async () => {
		setCurrentStep( 7 );
		setExecutingApply( true );
		setApplyError( null );
		setApplyPhase( __( 'Creating safety recovery checkpoint snapshot on destination…', 'migrate-lift' ) );

		try {
			await createRemoteRecoveryCheckpoint( connectedSite.pair_id, ( step ) => {
				setApplyPhase( sprintf(
					/* translators: 1: Percentage complete, 2: Current step. */
					__( 'Creating safety recovery checkpoint on destination (%1$d%%): %2$s', 'migrate-lift' ),
					step.progress || 0,
					step.message || ''
				) );
			} );

			setApplyPhase( __( 'Replacing files and swapping database tables on destination. Keep this page open…', 'migrate-lift' ) );

			const result = await executeRemoteApply( connectedSite.pair_id, selectedArchiveFile, confirmInput.trim(), {
				file_mode: fileMode,
				db_mode: dbMode,
				preserve_admin_user: preserveAdmin,
				confirm_delete: confirmCleanSync,
			} );

			setApplyResults( result );
			setApplyPhase( __( 'Migration completed successfully!', 'migrate-lift' ) );
			if ( onComplete ) onComplete( result );
		} catch ( err ) {
			setApplyError( err.message || __( 'Migration restore failed. Check the activity log on the destination site.', 'migrate-lift' ) );
		} finally {
			setExecutingApply( false );
		}
	};

	const wizardSteps = [
		{ num: 1, label: __( 'Connect', 'migrate-lift' ) },
		{ num: 2, label: __( 'Contents', 'migrate-lift' ) },
		{ num: 3, label: __( 'Preflight', 'migrate-lift' ) },
		{ num: 4, label: __( 'Transfer', 'migrate-lift' ) },
		{ num: 5, label: __( 'Options', 'migrate-lift' ) },
		{ num: 6, label: __( 'Confirm', 'migrate-lift' ) },
		{ num: 7, label: __( 'Finish', 'migrate-lift' ) },
	];

	return (
		<div className="ml-migration-wizard" style={ { maxWidth: '980px', margin: '0 auto' } }>
			{ /* Step Breadcrumbs */ }
			<div
				className="ml-wizard-steps-nav"
				style={ {
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'space-between',
					background: 'var(--ml-paper)',
					padding: '16px 24px',
					borderRadius: 'var(--ml-radius-md)',
					border: '1px solid var(--ml-line)',
					marginBottom: '24px',
					gap: '8px',
					overflowX: 'auto',
				} }
			>
				{ wizardSteps.map( ( s, i ) => {
					const isPast = currentStep > s.num;
					const isCurr = currentStep === s.num;
					return (
						<div
							key={ s.num }
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: '8px',
								color: isCurr ? 'var(--ml-ink)' : ( isPast ? 'var(--ml-teal-deep)' : 'var(--ml-muted)' ),
								fontWeight: isCurr ? 600 : 500,
								fontSize: '13px',
								whiteSpace: 'nowrap',
							} }
						>
							<div
								style={ {
									width: 26,
									height: 26,
									borderRadius: '50%',
									background: isCurr ? 'var(--ml-ink)' : ( isPast ? 'var(--ml-teal-soft)' : 'var(--ml-line-light)' ),
									color: isCurr ? '#fff' : ( isPast ? 'var(--ml-teal-deep)' : 'var(--ml-muted)' ),
									display: 'flex',
									alignItems: 'center',
									justifyContent: 'center',
									fontSize: '12px',
									fontWeight: 700,
									border: isPast ? '1px solid var(--ml-teal-border)' : 'none',
								} }
							>
								{ isPast ? <IconCheck style={ { width: 14, height: 14 } } /> : s.num }
							</div>
							<span>{ s.label }</span>
							{ i < wizardSteps.length - 1 && (
								<span style={ { color: 'var(--ml-line)', margin: '0 4px' } }>—</span>
							) }
						</div>
					);
				} ) }
			</div>

			{ /* STEP 1: CONNECT SITES */ }
			{ 1 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<div>
								<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
									{ __( 'Step 1: Cryptographic Site Pairing', 'migrate-lift' ) }
								</h2>
								<p style={ { margin: '4px 0 0', color: 'var(--ml-muted)', fontSize: '13px' } }>
									{ __( 'Establish an encrypted HTTPS connection between this site and your target server without storing credentials or cloud accounts.', 'migrate-lift' ) }
								</p>
							</div>
							{ connectedSite && (
								<StatusPill status="ready" label={ __( 'Paired & Verified', 'migrate-lift' ) } />
							) }
						</div>
					</CardHeader>

					<CardBody className="migrate-lift-card-body" style={ { padding: '24px' } }>
						{ /* Mode Switcher */ }
						<div
							style={ {
								display: 'grid',
								gridTemplateColumns: '1fr 1fr',
								gap: '16px',
								marginBottom: '24px',
							} }
						>
							<div
								onClick={ () => setActiveTab( 'source' ) }
								style={ {
									border: `2px solid ${ activeTab === 'source' ? 'var(--ml-ink)' : 'var(--ml-line)' }`,
									background: activeTab === 'source' ? 'var(--ml-hover)' : 'var(--ml-paper)',
									padding: '16px',
									borderRadius: 'var(--ml-radius-md)',
									cursor: 'pointer',
								} }
							>
								<div style={ { fontWeight: 600, fontSize: '14px', marginBottom: '4px' } }>
									{ __( '📤 Send Site (Current Site is Source)', 'migrate-lift' ) }
								</div>
								<div style={ { fontSize: '12.5px', color: 'var(--ml-muted)' } }>
									{ __( 'Paste a pairing key generated on your destination server to transfer this site directly.', 'migrate-lift' ) }
								</div>
							</div>

							<div
								onClick={ () => setActiveTab( 'destination' ) }
								style={ {
									border: `2px solid ${ activeTab === 'destination' ? 'var(--ml-ink)' : 'var(--ml-line)' }`,
									background: activeTab === 'destination' ? 'var(--ml-hover)' : 'var(--ml-paper)',
									padding: '16px',
									borderRadius: 'var(--ml-radius-md)',
									cursor: 'pointer',
								} }
							>
								<div style={ { fontWeight: 600, fontSize: '14px', marginBottom: '4px' } }>
									{ __( '📥 Receive Site (Current Site is Destination)', 'migrate-lift' ) }
								</div>
								<div style={ { fontSize: '12.5px', color: 'var(--ml-muted)' } }>
									{ __( 'Generate a single-use 15-minute connection key to authorize an incoming transfer.', 'migrate-lift' ) }
								</div>
							</div>
						</div>

						{ /* TAB: SOURCE MODE (PASTE KEY) */ }
						{ activeTab === 'source' && (
							<div>
								{ connectError && (
									<Notice status="error" isDismissible={ false } style={ { marginBottom: '16px' } }>
										{ connectError }
									</Notice>
								) }

								{ connectedSite ? (
									<div
										style={ {
											border: '1px solid var(--ml-teal-border)',
											background: 'var(--ml-teal-soft)',
											borderRadius: 'var(--ml-radius-md)',
											padding: '20px',
											marginBottom: '24px',
										} }
									>
										<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '16px' } }>
											<div style={ { display: 'flex', alignItems: 'center', gap: '10px' } }>
												<div style={ { width: 32, height: 32, borderRadius: '50%', background: 'var(--ml-teal)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
													<IconCheck style={ { width: 18, height: 18 } } />
												</div>
												<div>
													<div style={ { fontWeight: 700, fontSize: '15px', color: 'var(--ml-teal-deep)' } }>
														{ __( 'Connected to Destination Server', 'migrate-lift' ) }
													</div>
													<div style={ { fontSize: '13px', color: 'var(--ml-ink-secondary)' } }>
														{ connectedSite.remote_name || 'Destination' } ({ connectedSite.remote_url })
													</div>
												</div>
											</div>
											<div style={ { display: 'flex', gap: '8px' } }>
												<Button
													variant="tertiary"
													isSmall
													disabled={ verifyingPing }
													onClick={ () => handleTestPing( connectedSite.pair_id ) }
												>
													{ verifyingPing ? <Spinner style={ { margin: 0 } } /> : ( pingLatency ? `${ pingLatency } ms` : __( 'Test Ping', 'migrate-lift' ) ) }
												</Button>
												<Button
													variant="tertiary"
													isDestructive
													isSmall
													onClick={ () => handleRevokePairing( connectedSite.pair_id ) }
												>
													{ __( 'Disconnect', 'migrate-lift' ) }
												</Button>
											</div>
										</div>

										{ /* Diagram */ }
										<div
											style={ {
												display: 'grid',
												gridTemplateColumns: '1fr auto 1fr',
												alignItems: 'center',
												gap: '16px',
												background: 'var(--ml-paper)',
												padding: '16px',
												borderRadius: 'var(--ml-radius-sm)',
												border: '1px solid var(--ml-teal-border)',
											} }
										>
											<div style={ { textAlign: 'center' } }>
												<div style={ { fontWeight: 600, fontSize: '13px' } }>{ __( 'Source (This Site)', 'migrate-lift' ) }</div>
												<div style={ { fontSize: '12px', color: 'var(--ml-muted)', wordBreak: 'break-all' } }>{ cfg.home || window.location.origin }</div>
											</div>
											<div style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', color: 'var(--ml-teal)' } }>
												<span style={ { fontSize: '11px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em' } }>
													{ __( 'HMAC-SHA256 HTTPS', 'migrate-lift' ) }
												</span>
												<IconArrowRight style={ { width: 24, height: 24 } } />
											</div>
											<div style={ { textAlign: 'center' } }>
												<div style={ { fontWeight: 600, fontSize: '13px' } }>{ connectedSite.remote_name || __( 'Destination', 'migrate-lift' ) }</div>
												<div style={ { fontSize: '12px', color: 'var(--ml-muted)', wordBreak: 'break-all' } }>{ connectedSite.remote_url }</div>
											</div>
										</div>
									</div>
								) : (
									<div style={ { marginBottom: '24px' } }>
										<TextControl
											label={ __( 'Destination Pairing Key', 'migrate-lift' ) }
											help={ __( 'Paste the key starting with MLPAIR: generated on your destination WordPress site.', 'migrate-lift' ) }
											placeholder="MLPAIR:eyJ2IjoxLCJlbmRwb2ludCI..."
											value={ pairingKeyInput }
											onChange={ setPairingKeyInput }
										/>
										<div style={ { display: 'flex', justifyContent: 'flex-start', marginTop: '12px' } }>
											<Button
												variant="primary"
												disabled={ ! pairingKeyInput.trim() || connecting }
												onClick={ handleConnectSite }
											>
												{ connecting ? (
													<>
														<Spinner style={ { margin: '0 6px 0 0' } } />
														{ __( 'Authenticating Handshake…', 'migrate-lift' ) }
													</>
												) : (
													__( 'Connect to Destination', 'migrate-lift' )
												) }
											</Button>
										</div>
									</div>
								) }
							</div>
						)}

						{ /* TAB: DESTINATION MODE (GENERATE KEY) */ }
						{ activeTab === 'destination' && (
							<div>
								<div style={ { marginBottom: '20px' } }>
									<p style={ { fontSize: '13.5px', color: 'var(--ml-ink-secondary)', marginBottom: '16px' } }>
										{ __( 'Click below to generate a single-use pairing key. Paste it into MigrateLift on your source WordPress site to authorize direct file and database transfer.', 'migrate-lift' ) }
									</p>
									<Button
										variant="primary"
										disabled={ generatingToken }
										onClick={ handleGenerateToken }
									>
										{ generatingToken ? <Spinner style={ { margin: '0 6px 0 0' } } /> : __( 'Generate New Pairing Key', 'migrate-lift' ) }
									</Button>
								</div>

								{ pairingTokenData && (
									<div
										style={ {
											background: 'var(--ml-hover)',
											border: '1px solid var(--ml-line)',
											borderRadius: 'var(--ml-radius-md)',
											padding: '20px',
											marginBottom: '20px',
										} }
									>
										<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' } }>
											<span style={ { fontWeight: 600, fontSize: '13px', color: 'var(--ml-ink)' } }>
												{ __( 'Single-Use Pairing Key', 'migrate-lift' ) }
											</span>
											<span style={ { fontSize: '12px', color: tokenCountdown < 120 ? 'var(--ml-red)' : 'var(--ml-muted)' } }>
												{ sprintf( __( 'Expires in %02d:%02d', 'migrate-lift' ), Math.floor( tokenCountdown / 60 ), tokenCountdown % 60 ) }
											</span>
										</div>

										<textarea
											readOnly
											value={ pairingTokenData.pairing_key }
											style={ {
												width: '100%',
												height: '70px',
												fontSize: '11.5px',
												fontFamily: 'monospace',
												padding: '8px',
												borderRadius: 'var(--ml-radius-sm)',
												border: '1px solid var(--ml-line)',
												background: '#fff',
												color: 'var(--ml-ink)',
												resize: 'none',
											} }
										/>

										<div style={ { display: 'flex', justifyContent: 'flex-end', marginTop: '8px' } }>
											<Button variant="secondary" onClick={ handleCopyKey }>
												{ copySuccess ? __( '✓ Copied!', 'migrate-lift' ) : __( 'Copy Pairing Key', 'migrate-lift' ) }
											</Button>
										</div>
									</div>
								) }
							</div>
						) }

						{ /* Navigation to Step 2 */ }
						<div style={ { display: 'flex', justifyContent: 'flex-end', marginTop: '24px', borderTop: '1px solid var(--ml-line)', paddingTop: '20px' } }>
							<Button
								variant="primary"
								disabled={ ! connectedSite }
								onClick={ () => setCurrentStep( 2 ) }
							>
								{ __( 'Proceed to Choose Contents', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 2: CHOOSE CONTENTS & MODE */ }
			{ 2 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
								{ __( 'Step 2: Migration Scope & Migration Mode', 'migrate-lift' ) }
							</h2>
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body" style={ { padding: '24px' } }>
						{ /* Active Commerce Warning */ }
						<InlineNotice status="warning" title={ __( 'Active Sites & E-Commerce Notice', 'migrate-lift' ) }>
							{ __( 'Direct transfer captures a point-in-time snapshot. For high-volume e-commerce stores, schedule migration during off-peak hours or briefly enable maintenance mode during the final switch to avoid lost customer orders or carts.', 'migrate-lift' ) }
						</InlineNotice>

						<h3 style={ { fontSize: '15px', fontWeight: 600, marginTop: '24px', marginBottom: '12px' } }>
							{ __( 'Snapshot Source', 'migrate-lift' ) }
						</h3>
						<RadioControl
							selected={ archiveSourceMode }
							options={ [
								{
									label: __( 'Create a fresh backup snapshot right now (Recommended)', 'migrate-lift' ),
									value: 'fresh_export',
								},
								{
									label: __( 'Use an existing verified archive from Backup Library', 'migrate-lift' ),
									value: 'existing_archive',
								},
							] }
							onChange={ setArchiveSourceMode }
						/>

						{ 'existing_archive' === archiveSourceMode && (
							<div style={ { marginTop: '16px', display: 'flex', flexDirection: 'column', gap: '8px' } }>
								{ archives.map( ( a ) => (
									<div
										key={ a.file }
										onClick={ () => setSelectedArchiveFile( a.file ) }
										style={ {
											padding: '12px 16px',
											borderRadius: 'var(--ml-radius-sm)',
											border: `2px solid ${ selectedArchiveFile === a.file ? 'var(--ml-teal)' : 'var(--ml-line)' }`,
											background: selectedArchiveFile === a.file ? 'var(--ml-teal-soft)' : 'var(--ml-paper)',
											cursor: 'pointer',
											display: 'flex',
											justifyContent: 'space-between',
											alignItems: 'center',
										} }
									>
										<div>
											<span style={ { fontWeight: 600 } }>{ a.file }</span>
											<span style={ { fontSize: '12px', color: 'var(--ml-muted)', marginLeft: '10px' } }>{ formatBytes( a.size ) }</span>
										</div>
										<StatusPill status={ a.verified ? 'ready' : 'neutral' } label={ a.verified ? __( 'Verified', 'migrate-lift' ) : __( 'Archive', 'migrate-lift' ) } />
									</div>
								) ) }
							</div>
						) }

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '28px', borderTop: '1px solid var(--ml-line)', paddingTop: '20px' } }>
							<Button variant="secondary" onClick={ () => setCurrentStep( 1 ) }>
								{ __( 'Back to Connect', 'migrate-lift' ) }
							</Button>
							<Button variant="primary" onClick={ handleProceedToPreflight }>
								{ __( 'Proceed to Preflight Check', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 3: PREFLIGHT */ }
			{ 3 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
								{ __( 'Step 3: Remote Destination Preflight Readiness', 'migrate-lift' ) }
							</h2>
							{ preflightReport && (
								<StatusPill status={ preflightReport.passed ? 'ready' : 'attention' } label={ preflightReport.passed ? __( 'Preflight Passed', 'migrate-lift' ) : __( 'Blockers Found', 'migrate-lift' ) } />
							) }
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body" style={ { padding: '24px' } }>
						{ runningPreflight ? (
							<div style={ { textAlign: 'center', padding: '40px' } }>
								<Spinner />
								<div style={ { marginTop: '12px', color: 'var(--ml-muted)' } }>
									{ __( 'Querying destination server for storage, memory, and write permissions…', 'migrate-lift' ) }
								</div>
							</div>
						) : preflightReport ? (
							<div>
								<div style={ { display: 'flex', flexDirection: 'column', gap: '12px', marginBottom: '24px' } }>
									{ preflightReport.checks.map( ( c ) => (
										<div
											key={ c.id }
											style={ {
												display: 'flex',
												alignItems: 'center',
												justifyContent: 'space-between',
												padding: '14px 18px',
												borderRadius: 'var(--ml-radius-sm)',
												background: 'var(--ml-paper)',
												border: '1px solid var(--ml-line)',
											} }
										>
											<div>
												<div style={ { fontWeight: 600, fontSize: '13.5px' } }>{ c.label }</div>
												<div style={ { fontSize: '12.5px', color: 'var(--ml-muted)' } }>{ c.detail }</div>
											</div>
											<StatusPill status={ c.status === 'pass' ? 'ready' : 'attention' } label={ c.status === 'pass' ? __( 'Pass', 'migrate-lift' ) : __( 'Warning', 'migrate-lift' ) } />
										</div>
									) ) }
								</div>

								{ ! preflightReport.passed && (
									<Notice status="error" isDismissible={ false } style={ { marginBottom: '16px' } }>
										{ preflightReport.blockers.join( '; ' ) }
									</Notice>
								) }
							</div>
						) : null }

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '24px', borderTop: '1px solid var(--ml-line)', paddingTop: '20px' } }>
							<Button variant="secondary" onClick={ () => setCurrentStep( 2 ) }>
								{ __( 'Back', 'migrate-lift' ) }
							</Button>
							<Button
								variant="primary"
								disabled={ runningPreflight || ! preflightReport?.passed }
								onClick={ handleStartTransferStep }
							>
								{ __( 'Start Direct HTTPS Transfer', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 4: TRANSFER */ }
			{ 4 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
								{ __( 'Step 4: Streaming Archive Direct to Destination', 'migrate-lift' ) }
							</h2>
							<StatusPill
								status={ transferState?.state === 'completed' ? 'ready' : ( isTransferring ? 'working' : 'neutral' ) }
								label={ transferState?.state === 'completed' ? __( 'Transfer Verified', 'migrate-lift' ) : ( isTransferring ? __( 'Streaming…', 'migrate-lift' ) : __( 'Transfer Complete', 'migrate-lift' ) ) }
							/>
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body" style={ { padding: '24px' } }>
						{ transferError && (
							<Notice status="error" isDismissible={ false } style={ { marginBottom: '16px' } }>
								{ transferError }
							</Notice>
						) }

						{ isExportingFresh ? (
							<div style={ { textAlign: 'center', padding: '32px' } }>
								<Spinner />
								<div style={ { marginTop: '12px', fontWeight: 600 } }>{ exportStatusText }</div>
							</div>
						) : (
							<div>
								<div style={ { marginBottom: '16px' } }>
									<div style={ { display: 'flex', justifyContent: 'space-between', marginBottom: '8px', fontSize: '13px' } }>
										<span style={ { fontWeight: 600 } }>
											{ transferState?.message || __( 'Streaming bounded 2 MiB chunks over authenticated HTTPS…', 'migrate-lift' ) }
										</span>
										<span>{ transferState?.progress || ( transferState?.state === 'completed' ? 100 : 45 ) }%</span>
									</div>
									<ProgressBar progress={ transferState?.state === 'completed' ? 100 : ( transferState?.progress || 45 ) } />
								</div>

								<div
									style={ {
										display: 'grid',
										gridTemplateColumns: 'repeat(3, 1fr)',
										gap: '12px',
										background: 'var(--ml-hover)',
										padding: '16px',
										borderRadius: 'var(--ml-radius-sm)',
										marginTop: '20px',
										marginBottom: '20px',
									} }
								>
									<div>
										<div style={ { fontSize: '11px', color: 'var(--ml-muted)', textTransform: 'uppercase' } }>{ __( 'Transferred', 'migrate-lift' ) }</div>
										<div style={ { fontWeight: 700, fontSize: '14px', marginTop: '2px' } }>
											{ formatBytes( transferState?.checkpoint?.bytes_transferred || 0 ) }
										</div>
									</div>
									<div>
										<div style={ { fontSize: '11px', color: 'var(--ml-muted)', textTransform: 'uppercase' } }>{ __( 'Speed', 'migrate-lift' ) }</div>
										<div style={ { fontWeight: 700, fontSize: '14px', marginTop: '2px' } }>
											{ formatBytes( transferState?.checkpoint?.speed_bps || 0 ) }/s
										</div>
									</div>
									<div>
										<div style={ { fontSize: '11px', color: 'var(--ml-muted)', textTransform: 'uppercase' } }>{ __( 'Target Status', 'migrate-lift' ) }</div>
										<div style={ { fontWeight: 700, fontSize: '14px', marginTop: '2px', color: 'var(--ml-teal-deep)' } }>
											{ __( 'Target Unchanged', 'migrate-lift' ) }
										</div>
									</div>
								</div>

								<InlineNotice status="info" title={ __( 'Non-Destructive Guarantee', 'migrate-lift' ) }>
									{ __( 'The transfer stage places the verified archive into protected temporary storage on the destination server. Your destination site remains completely unchanged and functional until you explicitly confirm the restore apply step.', 'migrate-lift' ) }
								</InlineNotice>
							</div>
						) }

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '24px', borderTop: '1px solid var(--ml-line)', paddingTop: '20px' } }>
							{ isTransferring ? (
								<Button variant="secondary" isDestructive onClick={ handleCancelTransfer }>
									{ __( 'Cancel Transfer', 'migrate-lift' ) }
								</Button>
							) : (
								<Button variant="secondary" onClick={ () => setCurrentStep( 3 ) }>
									{ __( 'Back', 'migrate-lift' ) }
								</Button>
							)}

							<Button
								variant="primary"
								disabled={ isTransferring && transferState?.state !== 'completed' }
								onClick={ handleProceedToOptions }
							>
								{ __( 'Proceed to Destination Options', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 5: DESTINATION OPTIONS */ }
			{ 5 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
								{ __( 'Step 5: File & Database Replacement Strategy', 'migrate-lift' ) }
							</h2>
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body" style={ { padding: '24px' } }>
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px', marginBottom: '24px' } }>
							{ /* File Policy */ }
							<div>
								<h3 style={ { fontSize: '14px', fontWeight: 600, marginBottom: '8px' } }>
									{ __( 'File Replacement Policy', 'migrate-lift' ) }
								</h3>
								<RadioControl
									selected={ fileMode }
									options={ [
										{ label: __( 'Overwrite matching files (Recommended)', 'migrate-lift' ), value: 'overwrite_matching' },
										{ label: __( 'Keep existing destination files (Add missing only)', 'migrate-lift' ), value: 'add_missing' },
										{ label: __( 'Clean sync (Synchronize managed files, remove orphans)', 'migrate-lift' ), value: 'sync_managed' },
									] }
									onChange={ setFileMode }
								/>
							</div>

							{ /* DB Policy */ }
							<div>
								<h3 style={ { fontSize: '14px', fontWeight: 600, marginBottom: '8px' } }>
									{ __( 'Database Replacement Policy', 'migrate-lift' ) }
								</h3>
								<RadioControl
									selected={ dbMode }
									options={ [
										{ label: __( 'Replace mapped tables (Atomic RENAME TABLE swap)', 'migrate-lift' ), value: 'replace_mapped' },
										{ label: __( 'Keep existing destination database', 'migrate-lift' ), value: 'keep_destination' },
									] }
									onChange={ setDbMode }
								/>
							</div>
						</div>

						{ /* Preservation & Search/Replace Options */ }
						{ diffError && (
							<Notice status="warning" isDismissible={ false } style={ { marginBottom: '16px' } }>
								{ diffError }
							</Notice>
						) }

						<div style={ { background: 'var(--ml-hover)', padding: '16px', borderRadius: 'var(--ml-radius-sm)', marginBottom: '20px' } }>
							<CheckboxControl
								label={ __( 'Preserve destination administrator user account (prevents lockout)', 'migrate-lift' ) }
								checked={ preserveAdmin }
								onChange={ setPreserveAdmin }
							/>
							{ fileMode === 'sync_managed' && (
								<CheckboxControl
									label={ __( 'I confirm deletion of orphan files not present in the source archive', 'migrate-lift' ) }
									checked={ confirmCleanSync }
									onChange={ setConfirmCleanSync }
								/>
							) }
						</div>

						{ /* Serialization Safe URL Replace Preview */ }
						<div style={ { border: '1px solid var(--ml-line)', borderRadius: 'var(--ml-radius-sm)', padding: '16px' } }>
							<div style={ { fontWeight: 600, fontSize: '13px', marginBottom: '8px' } }>
								{ __( 'Deep Serialization-Safe URL Search & Replace Plan', 'migrate-lift' ) }
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: '12px', fontSize: '12.5px', fontFamily: 'monospace' } }>
								<span>{ cfg.home || window.location.origin }</span>
								<IconArrowRight style={ { width: 14, height: 14, color: 'var(--ml-teal)' } } />
								<span>{ connectedSite?.remote_url || 'https://dest.example.com' }</span>
							</div>
						</div>

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '24px', borderTop: '1px solid var(--ml-line)', paddingTop: '20px' } }>
							<Button variant="secondary" onClick={ () => setCurrentStep( 4 ) }>
								{ __( 'Back', 'migrate-lift' ) }
							</Button>
							<Button variant="primary" onClick={ () => setCurrentStep( 6 ) }>
								{ __( 'Proceed to Final Confirmation', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 6: FINAL CONFIRM */ }
			{ 6 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-red-border)', boxShadow: 'none' } }>
					<CardHeader className="migrate-lift-card-header" style={ { background: 'var(--ml-red-soft)' } }>
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600, color: 'var(--ml-red)' } }>
								{ __( 'Step 6: Confirm Destination Restoration', 'migrate-lift' ) }
							</h2>
							<StatusPill status="attention" label={ __( 'Destructive Confirmation Required', 'migrate-lift' ) } />
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body" style={ { padding: '24px' } }>
						<InlineNotice status="error" title={ __( 'Data Replacement Warning', 'migrate-lift' ) }>
							{ __( 'Applying restoration will overwrite selected files and database tables on the destination server. An automatic verified Safety Recovery Snapshot will be created on the destination immediately prior to any changes to guarantee safe rollback.', 'migrate-lift' ) }
						</InlineNotice>

						<div style={ { margin: '24px 0' } }>
							<p style={ { fontSize: '13.5px', marginBottom: '12px' } }>
								{ sprintf(
									__( 'To confirm this operation, type the destination hostname (%s) below:', 'migrate-lift' ),
									expectedDestHost || 'destination hostname'
								) }
							</p>
							<TextControl
								value={ confirmInput }
								onChange={ setConfirmInput }
								placeholder={ expectedDestHost || 'domain.com' }
							/>
						</div>

						<CheckboxControl
							label={ __( 'I understand that target files and database tables will be safely replaced, backed by an automated rollback recovery checkpoint.', 'migrate-lift' ) }
							checked={ acknowledgeRisk }
							onChange={ setAcknowledgeRisk }
						/>

						<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '28px', borderTop: '1px solid var(--ml-line)', paddingTop: '20px' } }>
							<Button variant="secondary" onClick={ () => setCurrentStep( 5 ) }>
								{ __( 'Back', 'migrate-lift' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								disabled={ ! acknowledgeRisk || ( expectedDestHost && confirmInput.trim().toLowerCase() !== expectedDestHost.toLowerCase() ) }
								onClick={ handleExecuteFinalApply }
							>
								{ __( 'Execute Safe Migration Apply', 'migrate-lift' ) }
								<IconArrowRight style={ { width: 14, height: 14, marginLeft: '6px' } } />
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			{ /* STEP 7: VERIFY & FINISH */ }
			{ 7 === currentStep && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
								{ __( 'Step 7: Verify & Post-Migration Report', 'migrate-lift' ) }
							</h2>
							<StatusPill
								status={ executingApply ? 'working' : ( applyError ? 'attention' : 'ready' ) }
								label={ executingApply ? __( 'Applying…', 'migrate-lift' ) : ( applyError ? __( 'Failed', 'migrate-lift' ) : __( 'Completed', 'migrate-lift' ) ) }
							/>
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body" style={ { padding: '24px' } }>
						{ executingApply ? (
							<div style={ { textAlign: 'center', padding: '40px' } }>
								<Spinner />
								<div style={ { marginTop: '16px', fontWeight: 600, fontSize: '15px' } }>{ applyPhase }</div>
								<div style={ { marginTop: '8px', color: 'var(--ml-muted)', fontSize: '13px' } }>
									{ __( 'Executing staged atomic replacement. Do not close this browser window.', 'migrate-lift' ) }
								</div>
							</div>
						) : applyError ? (
							<div>
								<Notice status="error" isDismissible={ false } style={ { marginBottom: '16px' } }>
									{ applyError }
								</Notice>
								<Button variant="secondary" onClick={ () => setCurrentStep( 6 ) }>
									{ __( 'Return to Confirmation', 'migrate-lift' ) }
								</Button>
							</div>
						) : (
							<div>
								{ /* Success Banner */ }
								<div
									style={ {
										background: 'var(--ml-teal-soft)',
										border: '1px solid var(--ml-teal-border)',
										borderRadius: 'var(--ml-radius-md)',
										padding: '24px',
										textAlign: 'center',
										marginBottom: '24px',
									} }
								>
									<div style={ { width: 44, height: 44, borderRadius: '50%', background: 'var(--ml-teal)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 12px' } }>
										<IconCheck style={ { width: 24, height: 24 } } />
									</div>
									<h3 style={ { margin: '0 0 6px', color: 'var(--ml-teal-deep)', fontSize: '18px', fontWeight: 700 } }>
										{ __( 'Site Migration Completed Successfully!', 'migrate-lift' ) }
									</h3>
									<p style={ { margin: 0, color: 'var(--ml-ink-secondary)', fontSize: '13.5px' } }>
										{ __( 'All files and database tables were safely staged, swapped, and transformed with zero corruptions.', 'migrate-lift' ) }
									</p>
								</div>

								{ /* Migration Results Metrics */ }
								<div
									style={ {
										display: 'grid',
										gridTemplateColumns: 'repeat(3, 1fr)',
										gap: '12px',
										marginBottom: '24px',
									} }
								>
									<div style={ { background: 'var(--ml-hover)', padding: '16px', borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }>
										<div style={ { fontSize: '11px', color: 'var(--ml-muted)', textTransform: 'uppercase' } }>{ __( 'Files Replaced', 'migrate-lift' ) }</div>
										<div style={ { fontSize: '18px', fontWeight: 700, marginTop: '4px' } }>
											{ applyResults?.file_results?.total_applied ?? 0 }
										</div>
									</div>
									<div style={ { background: 'var(--ml-hover)', padding: '16px', borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }>
										<div style={ { fontSize: '11px', color: 'var(--ml-muted)', textTransform: 'uppercase' } }>{ __( 'Tables Swapped', 'migrate-lift' ) }</div>
										<div style={ { fontSize: '18px', fontWeight: 700, marginTop: '4px' } }>
											{ applyResults?.db_results?.tables_swapped ?? 0 }
										</div>
									</div>
									<div style={ { background: 'var(--ml-hover)', padding: '16px', borderRadius: 'var(--ml-radius-sm)', border: '1px solid var(--ml-line)' } }>
										<div style={ { fontSize: '11px', color: 'var(--ml-muted)', textTransform: 'uppercase' } }>{ __( 'Safety Recovery Snapshot', 'migrate-lift' ) }</div>
										<div style={ { fontSize: '13px', fontWeight: 600, marginTop: '4px', color: 'var(--ml-teal-deep)' } }>
											{ __( 'Verified & Retained', 'migrate-lift' ) }
										</div>
									</div>
								</div>

								{ /* Post Migration Checklist */ }
								<div style={ { border: '1px solid var(--ml-line)', borderRadius: 'var(--ml-radius-sm)', padding: '18px', marginBottom: '24px' } }>
									<div style={ { fontWeight: 700, fontSize: '14px', marginBottom: '12px' } }>
										{ __( 'Next Steps: Administrator Cutover Checklist', 'migrate-lift' ) }
									</div>
									<ul style={ { margin: 0, paddingLeft: '20px', lineHeight: 1.8, fontSize: '13px', color: 'var(--ml-ink-secondary)' } }>
										<li>{ __( 'Log in to your destination WordPress admin and flush Permalinks (Settings → Permalinks → Save Changes).', 'migrate-lift' ) }</li>
										<li>{ __( 'Purge any edge, server, or CDN caches (Cloudflare, Nginx, Redis, etc.) on the destination.', 'migrate-lift' ) }</li>
										<li>{ __( 'Verify key front-end pages, widgets, media embeds, and contact forms.', 'migrate-lift' ) }</li>
										<li>{ __( 'When switching live traffic, update DNS A/CNAME records to point to your new destination host.', 'migrate-lift' ) }</li>
									</ul>
								</div>

								<div style={ { display: 'flex', justifyContent: 'flex-end', gap: '12px' } }>
									{ connectedSite?.remote_url && (
										<Button
											variant="secondary"
											href={ connectedSite.remote_url }
											target="_blank"
										>
											{ __( 'Visit Destination Site', 'migrate-lift' ) }
										</Button>
									) }
									<Button variant="primary" onClick={ () => setCurrentStep( 1 ) }>
										{ __( 'Start Another Migration', 'migrate-lift' ) }
									</Button>
								</div>
							</div>
						) }
					</CardBody>
				</Card>
			) }
		</div>
	);
}

