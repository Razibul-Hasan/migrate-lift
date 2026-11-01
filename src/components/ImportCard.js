import { useState, forwardRef, useImperativeHandle } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	FormFileUpload,
	Modal,
	Notice,
	ProgressBar,
	Flex,
	FlexItem,
	FlexBlock,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { post, runJob, uploadChunked } from '../utils/api';

const cfg = window.migrateLift || {};

const ImportCard = forwardRef( ( { isBusy, setBusy, onImportSuccess }, ref ) => {
	const [ selectedFile, setSelectedFile ] = useState( null );
	const [ deleteAfter, setDeleteAfter ] = useState( true );
	const [ progress, setProgress ] = useState( 0 );
	const [ status, setStatus ] = useState( '' );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ finished, setFinished ] = useState( false );
	const [ cleanupNote, setCleanupNote ] = useState( '' );

	// Confirm dialog state
	const [ confirmModalOpen, setConfirmModalOpen ] = useState( false );
	const [ importInfo, setImportInfo ] = useState( null );
	const [ pendingArchiveName, setPendingArchiveName ] = useState( null );

	const resetProgress = () => {
		setProgress( 0 );
		setStatus( '' );
		setError( null );
	};

	const executeImportProcess = async ( archiveName ) => {
		setConfirmModalOpen( false );
		setRunning( true );
		setBusy( true );
		setStatus( __( 'Starting import…', 'migrate-lift' ) );

		try {
			// Step 1: Run import job
			const result = await runJob(
				'migrate_lift_import',
				{ file: archiveName, delete_after: deleteAfter ? '1' : '0' },
				( update ) => {
					if ( update.status ) setStatus( update.status );
					if ( update.progress !== undefined ) setProgress( update.progress );
					if ( update.message ) setStatus( update.message );
				}
			);

			// Step 2: Run cleanup job
			setStatus( __( 'Imported. Cleaning up temporary files…', 'migrate-lift' ) );
			let note = '';
			try {
				await runJob(
					'migrate_lift_cleanup',
					{ token: result.token },
					( update ) => {
						if ( update.message ) {
							setStatus( __( 'Imported. ', 'migrate-lift' ) + update.message );
						}
					}
				);
			} catch ( cleanErr ) {
				note = ` Old temporary files could not all be removed (${ cleanErr.message }); log in and press "Remove temporary files".`;
				setCleanupNote( note );
			}

			setProgress( 100 );
			setFinished( true );
			if ( onImportSuccess ) {
				onImportSuccess();
			}
		} catch ( err ) {
			setError( err.message || __( 'Import failed.', 'migrate-lift' ) );
			setBusy( false );
		} finally {
			setRunning( false );
		}
	};

	const startImportFlow = async ( archiveName ) => {
		resetProgress();
		setRunning( true );
		setBusy( true );
		setStatus( __( 'Checking archive information…', 'migrate-lift' ) );

		try {
			const info = await post( 'migrate_lift_import_info', { file: archiveName } );
			setImportInfo( info );
			setPendingArchiveName( archiveName );
			setConfirmModalOpen( true );
		} catch ( err ) {
			setError( err.message || __( 'Could not read archive information.', 'migrate-lift' ) );
			setBusy( false );
		} finally {
			setRunning( false );
		}
	};

	useImperativeHandle( ref, () => ( {
		importExisting: ( archiveName ) => {
			startImportFlow( archiveName );
		},
	} ) );

	const handleFileSelect = ( event ) => {
		const files = event.target.files;
		if ( ! files || ! files.length ) return;

		const file = files[ 0 ];
		if ( ! /\.(tgz|tar\.gz)$/i.test( file.name ) ) {
			setError( __( 'Please select a .tgz archive created by Migrate Lift.', 'migrate-lift' ) );
			return;
		}

		setSelectedFile( file );
		setError( null );
	};

	const handleStartUploadAndImport = async () => {
		if ( ! selectedFile ) return;

		resetProgress();
		setRunning( true );
		setBusy( true );

		try {
			const finalFileName = await uploadChunked( selectedFile, ( update ) => {
				if ( update.progress !== undefined ) setProgress( update.progress );
				if ( update.message ) setStatus( update.message );
				if ( update.status ) setStatus( update.status );
			} );

			await startImportFlow( finalFileName );
		} catch ( err ) {
			setError( err.message || __( 'Failed to upload archive.', 'migrate-lift' ) );
			setRunning( false );
			setBusy( false );
		}
	};

	return (
		<Card elevation={ 0 } className="migrate-lift-card migrate-lift-import-card" style={ { width: '100%', borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', margin: '0 0 24px' } }>
			<CardHeader className="migrate-lift-card-header">
				<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
					{ __( 'Import Site', 'migrate-lift' ) }
				</h2>
			</CardHeader>
			<CardBody className="migrate-lift-card-body">
				{ finished ? (
					<div className="migrate-lift-finished-box">
						<Notice status="success" isDismissible={ false } style={ { marginBottom: '20px' } }>
							<p style={ { margin: 0, fontWeight: 600 } }>
								{ __( 'Site imported successfully!', 'migrate-lift' ) }
							</p>
							<p style={ { margin: '8px 0 0' } }>
								{ __(
									'Your site content and database have been replaced with the imported archive.',
									'migrate-lift'
								) }
								{ cleanupNote }
							</p>
						</Notice>

						<Flex justify="flex-start" gap={ 3 }>
							<FlexItem>
								<Button
									variant="primary"
									href={ cfg.home || '/' }
								>
									{ __( 'Visit Site', 'migrate-lift' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button
									variant="secondary"
									href={ cfg.loginUrl || '/wp-login.php' }
								>
									{ __( 'Log In Again', 'migrate-lift' ) }
								</Button>
							</FlexItem>
						</Flex>
					</div>
				) : (
					<>
						<p style={ { fontSize: '14px', lineHeight: '1.6', color: '#50575e', marginTop: 0 } }>
							{ __(
								'Upload a .tgz archive created by Migrate Lift to replace this site with the exported site. Old URLs will be automatically switched to this site.',
								'migrate-lift'
							) }
						</p>

						{ error && (
							<Notice status="error" isDismissible onDismiss={ () => setError( null ) } style={ { marginBottom: '16px' } }>
								{ error }
							</Notice>
						) }

						<Flex direction="column" gap={ 3 } style={ { marginTop: '16px', marginBottom: '16px' } }>
							<FlexItem>
								<Flex align="center" gap={ 3 } wrap>
									<FlexItem>
										<FormFileUpload
											accept=".tgz,.tar.gz"
											onChange={ handleFileSelect }
											variant="secondary"
											disabled={ isBusy }
										>
											{ selectedFile
												? __( 'Change Archive File…', 'migrate-lift' )
												: __( 'Select Archive (.tgz)…', 'migrate-lift' ) }
										</FormFileUpload>
									</FlexItem>

									{ selectedFile && (
										<FlexItem>
											<span style={ { fontSize: '13px', fontWeight: 500 } }>
												{ selectedFile.name } ({ ( selectedFile.size / ( 1024 * 1024 ) ).toFixed( 1 ) } MB)
											</span>
										</FlexItem>
									) }
								</Flex>
							</FlexItem>

							<FlexItem>
								<CheckboxControl
									label={ __( 'Delete archive file from server after successful import', 'migrate-lift' ) }
									checked={ deleteAfter }
									disabled={ isBusy }
									onChange={ setDeleteAfter }
								/>
							</FlexItem>

							{ selectedFile && ! running && (
								<FlexItem>
									<Button
										variant="primary"
										disabled={ isBusy }
										onClick={ handleStartUploadAndImport }
									>
										{ __( 'Upload & Import Site', 'migrate-lift' ) }
									</Button>
								</FlexItem>
							) }
						</Flex>

						{ running && (
							<div className="migrate-lift-progress-box" style={ { marginTop: '20px' } }>
								<ProgressBar value={ progress } />
								<Flex align="center" gap={ 2 } style={ { marginTop: '10px' } }>
									<Spinner />
									<span style={ { fontSize: '13px', color: '#50575e' } }>
										{ status || __( 'Processing…', 'migrate-lift' ) }
									</span>
								</Flex>
							</div>
						) }
					</>
				) }

				{ confirmModalOpen && importInfo && (
					<Modal
						title={ __( 'Confirm Site Replacement', 'migrate-lift' ) }
						onRequestClose={ () => {
							setConfirmModalOpen( false );
							setBusy( false );
						} }
						className="migrate-lift-modal"
						style={ { maxWidth: '520px' } }
					>
						<p style={ { fontSize: '14px', margin: '0 0 16px', color: '#1e1e1e' } }>
							{ __(
								'Importing this archive will completely replace this website’s database and wp-content files. This cannot be undone.',
								'migrate-lift'
							) }
						</p>

						<div style={ { background: '#f0f0f1', padding: '12px 16px', borderRadius: '4px', fontSize: '13px', marginBottom: '16px' } }>
							<div><strong>{ __( 'Archive:', 'migrate-lift' ) }</strong> { importInfo.file }</div>
							<div><strong>{ __( 'Original Site:', 'migrate-lift' ) }</strong> { importInfo.from }</div>
							<div><strong>{ __( 'This Site:', 'migrate-lift' ) }</strong> { importInfo.to }</div>
							<div><strong>{ __( 'Archive Date:', 'migrate-lift' ) }</strong> { importInfo.created }</div>
							<div><strong>{ __( 'Total Size:', 'migrate-lift' ) }</strong> { importInfo.size } ({ importInfo.files } { __( 'files', 'migrate-lift' ) })</div>
						</div>

						{ importInfo.warnings && importInfo.warnings.length > 0 && (
							<Notice status="warning" isDismissible={ false } style={ { marginBottom: '16px' } }>
								<ul style={ { margin: 0, paddingLeft: '16px' } }>
									{ importInfo.warnings.map( ( warn, idx ) => (
										<li key={ idx } style={ { fontSize: '12px' } }>{ warn }</li>
									) ) }
								</ul>
							</Notice>
						) }

						<Flex justify="flex-end" gap={ 2 } style={ { marginTop: '20px' } }>
							<FlexItem>
								<Button
									variant="tertiary"
									onClick={ () => {
										setConfirmModalOpen( false );
										setBusy( false );
									} }
								>
									{ __( 'Cancel', 'migrate-lift' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button
									variant="primary"
									isDestructive
									onClick={ () => executeImportProcess( pendingArchiveName ) }
								>
									{ __( 'Replace Site with Archive', 'migrate-lift' ) }
								</Button>
							</FlexItem>
						</Flex>
					</Modal>
				) }
			</CardBody>
		</Card>
	);
} );

export default ImportCard;
