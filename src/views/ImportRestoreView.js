/**
 * ImportRestoreView Component (Import Engine & Restore Wizard)
 *
 * Implements:
 * - Three distinct tabs:
 *   1. "Upload from browser" (Chunked resumable upload)
 *   2. "Server staging (SFTP / cPanel)" (Private dedicated directory)
 *   3. "Restore Wizard (Preflight & Recovery)" (7-step guided preflight & rollback snapshot)
 * - Complete quarantine inspection pipeline
 */

import { useState, useEffect, useRef, useCallback, forwardRef, useImperativeHandle } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Spinner,
	Notice,
	ProgressBar,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import PageHeader from '../components/common/PageHeader';
import InlineNotice from '../components/common/InlineNotice';
import StatusPill from '../components/common/StatusPill';
import RestoreWizard from '../components/restore/RestoreWizard';
import {
	IconImport,
	IconServer,
	IconHarddrive,
	IconCheck,
	IconRefresh,
	IconAlertTriangle,
	IconShield,
} from '../components/common/Icons';
import {
	uploadChunked,
	cancelChunkUpload,
	fetchImportStaging,
	rescanImportStaging,
	verifyStagedArchive,
	refreshExposureCheck,
	formatBytes,
} from '../utils/api';

const cfg = ( typeof window !== 'undefined' && window.migrateLift ) || {};

const ImportRestoreView = forwardRef( function ImportRestoreView(
	{ isBusy, setBusy, onImportSuccess },
	ref
) {
	// Tab State: 'browser', 'server', or 'wizard'
	const [ activeTab, setActiveTab ] = useState( 'browser' );
	const [ selectedWizardArchive, setSelectedWizardArchive ] = useState( null );

	// Browser Upload State
	const [ selectedFile, setSelectedFile ] = useState( null );
	const [ isDragging, setIsDragging ] = useState( false );
	const [ uploading, setUploading ] = useState( false );
	const [ uploadProgress, setUploadProgress ] = useState( 0 );
	const [ uploadedBytes, setUploadedBytes ] = useState( 0 );
	const [ totalBytes, setTotalBytes ] = useState( 0 );
	const [ uploadSpeed, setUploadSpeed ] = useState( 0 );
	const [ uploadReceipt, setUploadReceipt ] = useState( '' );
	const [ uploadStatusText, setUploadStatusText ] = useState( '' );
	const [ isPaused, setIsPaused ] = useState( false );
	const [ uploadError, setUploadError ] = useState( null );
	const [ verifiedArchive, setVerifiedArchive ] = useState( null );

	const uploadControllerRef = useRef( { paused: false, cancelled: false } );
	const fileInputRef = useRef( null );

	// Server Staging State (cPanel / SFTP)
	const [ stagingInfo, setStagingInfo ] = useState( null );
	const [ stagedArchives, setStagedArchives ] = useState( [] );
	const [ stagingLoading, setStagingLoading ] = useState( false );
	const [ stagingError, setStagingError ] = useState( null );
	const [ verifyingFile, setVerifyingFile ] = useState( null );
	const [ copiedPath, setCopiedPath ] = useState( false );
	const [ checkingExposure, setCheckingExposure ] = useState( false );
	const [ serverImportSuccess, setServerImportSuccess ] = useState( null );

	// Load staging folder info when switching to server tab
	const loadStagingData = useCallback( async ( forceScan = false ) => {
		setStagingLoading( true );
		setStagingError( null );
		try {
			if ( forceScan ) {
				const scanRes = await rescanImportStaging();
				setStagedArchives( scanRes.archives || [] );
			} else {
				const data = await fetchImportStaging();
				setStagingInfo( data.staging || null );
				setStagedArchives( data.archives || [] );
			}
		} catch ( err ) {
			setStagingError( err.message || __( 'Could not read staging directory.', 'migrate-lift' ) );
		} finally {
			setStagingLoading( false );
		}
	}, [] );

	useEffect( () => {
		if ( 'server' === activeTab && ! stagingInfo ) {
			loadStagingData();
		}
	}, [ activeTab, stagingInfo, loadStagingData ] );

	// Expose imperative handle for external navigation (e.g. from BackupsView "Restore" action)
	useImperativeHandle( ref, () => ( {
		importExisting: ( archiveName ) => {
			setSelectedWizardArchive( archiveName );
			setActiveTab( 'wizard' );
		},
	} ) );

	// File Selection Handlers
	const handleFileSelect = ( file ) => {
		if ( ! file ) return;
		if ( ! /\.(tgz|tar\.gz|zip)$/i.test( file.name ) ) {
			setUploadError( __( 'Please select a valid .tgz or .zip archive created by MigrateLift.', 'migrate-lift' ) );
			return;
		}
		setSelectedFile( file );
		setUploadError( null );
		setVerifiedArchive( null );
	};

	const handleDrop = ( e ) => {
		e.preventDefault();
		setIsDragging( false );
		if ( e.dataTransfer.files && e.dataTransfer.files.length > 0 ) {
			handleFileSelect( e.dataTransfer.files[ 0 ] );
		}
	};

	const handleDragOver = ( e ) => {
		e.preventDefault();
		setIsDragging( true );
	};

	const handleDragLeave = () => {
		setIsDragging( false );
	};

	// Chunked Upload Start
	const handleStartUpload = async () => {
		if ( ! selectedFile ) return;

		setUploading( true );
		setBusy( true );
		setUploadError( null );
		setVerifiedArchive( null );
		setIsPaused( false );
		setUploadProgress( 0 );
		setUploadStatusText( __( 'Initializing chunked transfer…', 'migrate-lift' ) );

		uploadControllerRef.current = { paused: false, cancelled: false };

		try {
			const finalFileName = await uploadChunked(
				selectedFile,
				( update ) => {
					if ( update.progress !== undefined ) setUploadProgress( update.progress );
					if ( update.uploadedBytes !== undefined ) setUploadedBytes( update.uploadedBytes );
					if ( update.totalBytes !== undefined ) setTotalBytes( update.totalBytes );
					if ( update.speed !== undefined ) setUploadSpeed( update.speed );
					if ( update.receipt ) setUploadReceipt( update.receipt );
					if ( update.message ) setUploadStatusText( update.message );
					if ( update.status ) setUploadStatusText( update.status );
				},
				uploadControllerRef.current
			);

			setUploadStatusText( __( 'Quarantine verification complete! Archive ready for restore.', 'migrate-lift' ) );
			setVerifiedArchive( {
				name: finalFileName,
				file: finalFileName,
				size: selectedFile.size,
				source: 'browser_upload',
			} );
			setSelectedFile( null );

			if ( onImportSuccess ) {
				onImportSuccess();
			}
		} catch ( err ) {
			if ( ! uploadControllerRef.current.cancelled ) {
				setUploadError( err.message || __( 'Upload failed.', 'migrate-lift' ) );
			}
		} finally {
			setUploading( false );
			setBusy( false );
		}
	};

	// Pause / Resume Upload
	const handleTogglePause = () => {
		const newPaused = ! isPaused;
		setIsPaused( newPaused );
		uploadControllerRef.current.paused = newPaused;
		if ( newPaused ) {
			setUploadStatusText( __( 'Upload paused by user.', 'migrate-lift' ) );
		}
	};

	// Cancel Upload
	const handleCancelUpload = async () => {
		uploadControllerRef.current.cancelled = true;
		if ( selectedFile ) {
			await cancelChunkUpload( selectedFile.name ).catch( () => null );
		}
		setUploading( false );
		setBusy( false );
		setIsPaused( false );
		setUploadProgress( 0 );
		setUploadStatusText( __( 'Upload cancelled.', 'migrate-lift' ) );
	};

	// Copy Staging Path
	const handleCopyPath = () => {
		if ( stagingInfo?.staging_dir ) {
			navigator.clipboard.writeText( stagingInfo.staging_dir );
			setCopiedPath( true );
			setTimeout( () => setCopiedPath( false ), 2500 );
		}
	};

	// Refresh Web Exposure Check
	const handleRefreshExposure = async () => {
		setCheckingExposure( true );
		try {
			const res = await refreshExposureCheck();
			setStagingInfo( ( prev ) => ( {
				...prev,
				web_exposed: !! res.exposed,
				web_warning: res.warning || '',
			} ) );
		} catch ( err ) {
			// Maintain current state
		} finally {
			setCheckingExposure( false );
		}
	};

	// Verify Staged Archive (SFTP)
	const handleVerifyStaged = async ( filename ) => {
		setVerifyingFile( filename );
		setStagingError( null );
		setServerImportSuccess( null );

		try {
			const result = await verifyStagedArchive( filename );
			setServerImportSuccess( result );
			loadStagingData( true );
			if ( onImportSuccess ) {
				onImportSuccess();
			}
		} catch ( err ) {
			setStagingError( err.message || __( 'Archive verification failed.', 'migrate-lift' ) );
		} finally {
			setVerifyingFile( null );
		}
	};

	return (
		<div className="ml-import-view">
			<PageHeader
				overline={ __( 'Import & Restore', 'migrate-lift' ) }
				title={ __( 'Import & Restore Wizard', 'migrate-lift' ) }
				description={ __(
					'Import backup archives via chunked browser upload or private server staging. Run destination preflight checks and capture a verified recovery checkpoint before restoration.',
					'migrate-lift'
				) }
			/>

			{ /* Safety Lifecycle Clarity Notice */ }
			<InlineNotice
				status="info"
				title={ __( 'Import Lifecycle: Verification without Immediate Replacement', 'migrate-lift' ) }
				style={ { marginBottom: '20px' } }
			>
				{ __(
					'Importing an archive validates its signature and registers it into your Backup Library without immediately replacing your live site. To replace and restore, use the dedicated Restore Wizard tab.',
					'migrate-lift'
				) }
			</InlineNotice>

			{ /* 3 Tabs Navigation */ }
			<div className="ml-import-tabs" role="tablist">
				<button
					type="button"
					role="tab"
					aria-selected={ 'browser' === activeTab }
					className={ `ml-import-tab-btn ${ 'browser' === activeTab ? 'ml-active' : '' }` }
					onClick={ () => setActiveTab( 'browser' ) }
					disabled={ isBusy }
				>
					<IconImport style={ { width: 16, height: 16 } } />
					{ __( 'Upload from browser', 'migrate-lift' ) }
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ 'server' === activeTab }
					className={ `ml-import-tab-btn ${ 'server' === activeTab ? 'ml-active' : '' }` }
					onClick={ () => setActiveTab( 'server' ) }
					disabled={ isBusy }
				>
					<IconServer style={ { width: 16, height: 16 } } />
					{ __( 'Already uploaded to server (cPanel / SFTP)', 'migrate-lift' ) }
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ 'wizard' === activeTab }
					className={ `ml-import-tab-btn ${ 'wizard' === activeTab ? 'ml-active' : '' }` }
					onClick={ () => setActiveTab( 'wizard' ) }
					disabled={ isBusy }
				>
					<IconShield style={ { width: 16, height: 16 } } />
					{ __( 'Restore Wizard (Preflight & Recovery)', 'migrate-lift' ) }
				</button>
			</div>

			{ /* TAB 1: BROWSER UPLOAD */ }
			{ 'browser' === activeTab && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600 } }>
							{ __( 'Browser Chunked Uploader', 'migrate-lift' ) }
						</h2>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						{ uploadError && (
							<Notice status="error" isDismissible onDismiss={ () => setUploadError( null ) } style={ { marginBottom: '16px' } }>
								{ uploadError }
							</Notice>
						) }

						{ verifiedArchive && (
							<div className="ml-verified-card">
								<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '12px' } }>
									<div style={ { display: 'flex', alignItems: 'center', gap: '8px' } }>
										<IconCheck style={ { width: 22, height: 22, color: 'var(--ml-teal)' } } />
										<span style={ { fontSize: '16px', fontWeight: 700, color: 'var(--ml-teal-deep)' } }>
											{ __( 'Archive Verified & Ready for Restore', 'migrate-lift' ) }
										</span>
									</div>
									<StatusPill status="ready" label={ __( 'Ready for restore', 'migrate-lift' ) } />
								</div>
								<p style={ { fontSize: '13.5px', color: 'var(--ml-ink-secondary)', margin: '0 0 14px' } }>
									{ sprintf(
										__( 'Archive "%s" (%s) successfully passed magic-byte verification, path traversal checks, and expansion limits. It has been registered into your Backup Library.', 'migrate-lift' ),
										verifiedArchive.name,
										formatBytes( verifiedArchive.size )
									) }
								</p>
								<div style={ { display: 'flex', gap: '10px', marginTop: '16px' } }>
									<Button
										variant="primary"
										onClick={ () => {
											setSelectedWizardArchive( verifiedArchive.name );
											setActiveTab( 'wizard' );
										} }
									>
										{ __( 'Launch Restore Wizard', 'migrate-lift' ) }
									</Button>
									<Button
										variant="secondary"
										onClick={ () => setVerifiedArchive( null ) }
									>
										{ __( 'Upload Another Archive', 'migrate-lift' ) }
									</Button>
								</div>
							</div>
						) }

						{ ! verifiedArchive && ! uploading && (
							<>
								<p style={ { fontSize: '13.5px', color: 'var(--ml-muted)', marginTop: 0 } }>
									{ __(
										'Select or drag a .tgz or .zip archive. The uploader slices files into 2 MB chunks with automatic retry and checksum verification, bypassing web server upload ceilings.',
										'migrate-lift'
									) }
								</p>

								{ /* Drag & Drop Target */ }
								<div
									className={ `ml-dropzone ${ isDragging ? 'ml-dropzone-dragover' : '' }` }
									onDrop={ handleDrop }
									onDragOver={ handleDragOver }
									onDragLeave={ handleDragLeave }
									onClick={ () => fileInputRef.current?.click() }
									role="button"
									tabIndex={ 0 }
									onKeyDown={ ( e ) => {
										if ( 'Enter' === e.key || ' ' === e.key ) {
											fileInputRef.current?.click();
										}
									} }
								>
									<input
										ref={ fileInputRef }
										type="file"
										accept=".tgz,.tar.gz,.zip"
										style={ { display: 'none' } }
										onChange={ ( e ) => {
											if ( e.target.files && e.target.files[ 0 ] ) {
												handleFileSelect( e.target.files[ 0 ] );
											}
										} }
									/>
									<IconImport className="ml-dropzone-icon" />
									<div style={ { fontSize: '15px', fontWeight: 600, color: 'var(--ml-ink)' } }>
										{ __( 'Choose backup archive or drop here', 'migrate-lift' ) }
									</div>
									<div style={ { fontSize: '12.5px', color: 'var(--ml-muted)', marginTop: '4px' } }>
										{ __( 'Supports .tgz, .tar.gz, and .zip created by MigrateLift', 'migrate-lift' ) }
									</div>
								</div>

								{ selectedFile && (
									<div className="ml-file-selected-card">
										<div>
											<div style={ { fontWeight: 600, color: 'var(--ml-ink)' } }>
												{ selectedFile.name }
											</div>
											<div style={ { fontSize: '12.5px', color: 'var(--ml-muted)', marginTop: '2px' } }>
												{ formatBytes( selectedFile.size ) } • { __( 'Ready to upload', 'migrate-lift' ) }
											</div>
										</div>
										<div style={ { display: 'flex', gap: '8px' } }>
											<Button
												variant="primary"
												onClick={ handleStartUpload }
											>
												{ __( 'Start Chunked Upload', 'migrate-lift' ) }
											</Button>
											<Button
												variant="tertiary"
												onClick={ () => setSelectedFile( null ) }
											>
												{ __( 'Remove', 'migrate-lift' ) }
											</Button>
										</div>
									</div>
								) }
							</>
						) }

						{ /* Active Upload Progress View */ }
						{ uploading && (
							<div style={ { marginTop: '12px' } }>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' } }>
									<div style={ { fontWeight: 600, fontSize: '14px', color: 'var(--ml-ink)' } }>
										{ selectedFile?.name }
									</div>
									<div style={ { fontWeight: 700, fontSize: '14px', color: 'var(--ml-teal)' } }>
										{ uploadProgress }%
									</div>
								</div>

								<ProgressBar value={ uploadProgress } />

								<div className="ml-upload-stats">
									<div>
										{ formatBytes( uploadedBytes ) } { __( 'of', 'migrate-lift' ) } { formatBytes( totalBytes ) }
										{ uploadSpeed > 0 && ` (${ formatBytes( uploadSpeed ) }/s)` }
									</div>
									{ uploadReceipt && (
										<div className="ml-upload-receipt">
											{ __( 'Receipt:', 'migrate-lift' ) } { uploadReceipt }
										</div>
									) }
								</div>

								<div style={ { display: 'flex', alignItems: 'center', gap: '8px', marginTop: '14px', color: 'var(--ml-muted)', fontSize: '13px' } }>
									<Spinner style={ { margin: 0 } } />
									<span>{ uploadStatusText }</span>
								</div>

								<div style={ { display: 'flex', gap: '10px', marginTop: '18px' } }>
									<Button
										variant="secondary"
										onClick={ handleTogglePause }
									>
										{ isPaused ? __( 'Resume Upload', 'migrate-lift' ) : __( 'Pause Upload', 'migrate-lift' ) }
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ handleCancelUpload }
									>
										{ __( 'Cancel Upload', 'migrate-lift' ) }
									</Button>
								</div>
							</div>
						) }
					</CardBody>
				</Card>
			) }

			{ /* TAB 2: ALREADY UPLOADED TO SERVER (cPanel / SFTP) */ }
			{ 'server' === activeTab && (
				<Card className="migrate-lift-card" style={ { borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', marginBottom: '24px' } }>
					<CardHeader className="migrate-lift-card-header">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
							<h2 style={ { margin: 0, fontSize: '17px', fontWeight: 600 } }>
								{ __( 'SFTP / cPanel Direct Staging Importer', 'migrate-lift' ) }
							</h2>
							<Button
								variant="secondary"
								isSmall
								disabled={ stagingLoading }
								onClick={ () => loadStagingData( true ) }
							>
								<IconRefresh style={ { width: 14, height: 14, marginRight: '4px' } } />
								{ __( 'Scan Staging Folder', 'migrate-lift' ) }
							</Button>
						</div>
					</CardHeader>
					<CardBody className="migrate-lift-card-body">
						{ stagingError && (
							<Notice status="error" isDismissible onDismiss={ () => setStagingError( null ) } style={ { marginBottom: '16px' } }>
								{ stagingError }
							</Notice>
						) }

						{ serverImportSuccess && (
							<Notice status="success" isDismissible onDismiss={ () => setServerImportSuccess( null ) } style={ { marginBottom: '16px' } }>
								<p style={ { margin: 0, fontWeight: 600 } }>
									{ serverImportSuccess.message }
								</p>
								<div style={ { marginTop: '8px' } }>
									<Button
										variant="secondary"
										isSmall
										onClick={ () => {
											setSelectedWizardArchive( serverImportSuccess.file );
											setActiveTab( 'wizard' );
										} }
									>
										{ __( 'Open in Restore Wizard', 'migrate-lift' ) }
									</Button>
								</div>
							</Notice>
						) }

						<p style={ { fontSize: '13.5px', color: 'var(--ml-ink-secondary)', marginTop: 0 } }>
							{ __(
								'For very large websites, uploading through a browser can be slow. You can drop archives directly onto your server via SFTP, SSH, or cPanel File Manager into the dedicated private staging folder below:',
								'migrate-lift'
							) }
						</p>

						<div className="ml-staging-code-box">
							<code>{ stagingInfo?.staging_dir || 'wp-content/migrate-lift-files/import/' }</code>
							<Button
								variant="secondary"
								isSmall
								onClick={ handleCopyPath }
								style={ { background: '#334155', color: '#fff', border: 'none', marginLeft: '12px' } }
							>
								{ copiedPath ? __( 'Copied!', 'migrate-lift' ) : __( 'Copy Path', 'migrate-lift' ) }
							</Button>
						</div>

						{ stagingInfo?.web_exposed && (
							<InlineNotice
								status="warning"
								title={ __( 'Web-Root Exposure Warning', 'migrate-lift' ) }
								style={ { marginBottom: '18px' } }
							>
								{ stagingInfo.web_warning || __( 'The storage directory may be publicly accessible over HTTP. Ensure your server configuration denies direct requests to wp-content/migrate-lift-files/.', 'migrate-lift' ) }
								<div style={ { marginTop: '8px' } }>
									<Button
										variant="link"
										isSmall
										disabled={ checkingExposure }
										onClick={ handleRefreshExposure }
									>
										{ checkingExposure ? __( 'Checking…', 'migrate-lift' ) : __( 'Re-check Protection', 'migrate-lift' ) }
									</Button>
								</div>
							</InlineNotice>
						) }

						<h3 style={ { fontSize: '15px', fontWeight: 600, margin: '20px 0 12px', color: 'var(--ml-ink)' } }>
							{ __( 'Discovered Staged Archives', 'migrate-lift' ) }
						</h3>

						{ stagingLoading ? (
							<div style={ { display: 'flex', alignItems: 'center', gap: '8px', padding: '24px 0', color: 'var(--ml-muted)' } }>
								<Spinner />
								<span>{ __( 'Scanning staging directory…', 'migrate-lift' ) }</span>
							</div>
						) : stagedArchives.length === 0 ? (
							<div style={ { padding: '24px', background: 'var(--ml-canvas)', borderRadius: 'var(--ml-radius-md)', textAlign: 'center', color: 'var(--ml-muted)', border: '1px dashed var(--ml-line)' } }>
								<IconHarddrive style={ { width: 32, height: 32, margin: '0 auto 8px', color: 'var(--ml-muted-light)' } } />
								<div style={ { fontWeight: 500 } }>
									{ __( 'No archives currently found in staging folder.', 'migrate-lift' ) }
								</div>
								<div style={ { fontSize: '12.5px', marginTop: '4px' } }>
									{ __( 'Upload a .tgz or .zip file to the path above, then click "Scan Staging Folder".', 'migrate-lift' ) }
								</div>
							</div>
						) : (
							<div className="ml-table-responsive-wrapper">
								<table className="ml-backups-table" style={ { width: '100%' } }>
									<thead>
										<tr>
											<th>{ __( 'Archive File', 'migrate-lift' ) }</th>
											<th>{ __( 'Size', 'migrate-lift' ) }</th>
											<th>{ __( 'Uploaded / Modified', 'migrate-lift' ) }</th>
											<th>{ __( 'Status', 'migrate-lift' ) }</th>
											<th style={ { textAlign: 'right' } }>{ __( 'Actions', 'migrate-lift' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ stagedArchives.map( ( item ) => {
											const isThisVerifying = verifyingFile === item.filename;
											return (
												<tr key={ item.filename }>
													<td>
														<span style={ { fontWeight: 600, color: 'var(--ml-ink)' } }>
															{ item.filename }
														</span>
													</td>
													<td>{ item.size_formatted }</td>
													<td>{ item.date }</td>
													<td>
														<StatusPill status="neutral" label={ __( 'Staged (Unverified)', 'migrate-lift' ) } />
													</td>
													<td style={ { textAlign: 'right' } }>
														<Button
															variant="primary"
															isSmall
															disabled={ isBusy || isThisVerifying }
															onClick={ () => handleVerifyStaged( item.filename ) }
														>
															{ isThisVerifying ? (
																<>
																	<Spinner style={ { margin: '0 4px 0 0' } } />
																	{ __( 'Verifying…', 'migrate-lift' ) }
																</>
															) : (
																__( 'Verify & Import to Library', 'migrate-lift' )
															) }
														</Button>
													</td>
												</tr>
											);
										} ) }
									</tbody>
								</table>
							</div>
						) }
					</CardBody>
				</Card>
			) }

			{ /* TAB 3: RESTORE WIZARD (PREFLIGHT & RECOVERY CHECKPOINT) */ }
			{ 'wizard' === activeTab && (
				<RestoreWizard
					initialArchive={ selectedWizardArchive }
					onComplete={ () => {
						if ( onImportSuccess ) onImportSuccess();
					} }
				/>
			) }
		</div>
	);
} );

export default ImportRestoreView;
