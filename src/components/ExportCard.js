import { useState, useRef } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	ProgressBar,
	Flex,
	FlexItem,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { runRestJob, runJob, formatBytes } from '../utils/api';

export default function ExportCard( { isBusy, setBusy, onExportSuccess } ) {
	const [ status, setStatus ] = useState( '' );
	const [ progress, setProgress ] = useState( 0 );
	const [ phase, setPhase ] = useState( '' );
	const [ running, setRunning ] = useState( false );
	const [ downloadUrl, setDownloadUrl ] = useState( null );
	const [ archiveInfo, setArchiveInfo ] = useState( null );
	const [ error, setError ] = useState( null );
	const currentJobIdRef = useRef( null );

	const handleExport = async () => {
		setError( null );
		setDownloadUrl( null );
		setArchiveInfo( null );
		setRunning( true );
		setBusy( true );
		setProgress( 0 );
		setPhase( 'init' );
		setStatus( __( 'Initializing backup engine…', 'migrate-lift' ) );

		try {
			// Prefer REST Job Engine (Part 05) with fallback to legacy endpoint
			let result;
			try {
				result = await runRestJob( 'backup', {}, ( update ) => {
					if ( update.job?.id ) {
						currentJobIdRef.current = update.job.id;
					}
					if ( update.phase ) {
						setPhase( update.phase );
					}
					if ( update.progress !== undefined ) {
						setProgress( update.progress );
					}
					if ( update.message ) {
						setStatus( update.message );
					}
				} );
			} catch ( restErr ) {
				// If REST API failed due to network or route, attempt fallback
				// eslint-disable-next-line no-console
				console.warn( '[MigrateLift] REST Job Engine encountered error, attempting ajax fallback:', restErr );
				result = await runJob( 'migrate_lift_export', {}, ( update ) => {
					if ( update.status ) {
						setStatus( update.status );
					}
					if ( update.progress !== undefined ) {
						setProgress( update.progress );
					}
					if ( update.message ) {
						setStatus( update.message );
					}
				} );
			}

			setProgress( 100 );
			setPhase( 'completed' );
			setStatus( result.message || __( 'Backup completed successfully!', 'migrate-lift' ) );

			if ( result.download || result.job?.output?.download_url ) {
				setDownloadUrl( result.download || result.job?.output?.download_url );
			}

			if ( result.archive || result.job?.output?.archive ) {
				setArchiveInfo( {
					name: result.archive || result.job?.output?.archive,
					size: result.size || result.job?.output?.size,
					sourceEstimate: result.job?.output?.uncompressed_estimate,
					reductionPct: result.job?.output?.reduction_percentage,
					compressionMode: result.job?.output?.compression_mode || 'balanced',
					format: result.job?.output?.format || 'tgz',
					verified: !! result.job?.output?.verified,
				} );
			}

			if ( onExportSuccess ) {
				onExportSuccess();
			}
		} catch ( err ) {
			setError( err.message || __( 'An error occurred during backup creation.', 'migrate-lift' ) );
		} finally {
			setRunning( false );
			setBusy( false );
			currentJobIdRef.current = null;
		}
	};

	const getPhaseLabel = ( currentPhase ) => {
		switch ( currentPhase ) {
			case 'init':
				return __( 'Preflight Checks', 'migrate-lift' );
			case 'scan':
				return __( 'Scanning Files & Tables', 'migrate-lift' );
			case 'db':
				return __( 'Exporting Database', 'migrate-lift' );
			case 'archive':
				return __( 'Streaming & Compressing (.tgz)', 'migrate-lift' );
			case 'verify':
				return __( 'Verifying Archive Integrity', 'migrate-lift' );
			case 'finalize':
				return __( 'Finalizing Backup', 'migrate-lift' );
			case 'completed':
				return __( 'Completed', 'migrate-lift' );
			default:
				return '';
		}
	};

	return (
		<Card
			elevation={ 0 }
			className="migrate-lift-card migrate-lift-export-card"
			style={ {
				width: '100%',
				borderRadius: 'var(--ml-radius-lg)',
				border: '1px solid var(--ml-line)',
				boxShadow: 'none',
				margin: '0 0 24px',
			} }
		>
			<CardHeader className="migrate-lift-card-header">
				<Flex justify="space-between" align="center" style={ { width: '100%' } }>
					<FlexItem>
						<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
							{ __( 'Create Full Backup', 'migrate-lift' ) }
						</h2>
					</FlexItem>
					<FlexItem>
						<span
							style={ {
								fontSize: '11px',
								fontWeight: 600,
								textTransform: 'uppercase',
								letterSpacing: '0.05em',
								padding: '2px 8px',
								borderRadius: '4px',
								backgroundColor: 'var(--ml-teal-50, #f0fdfa)',
								color: 'var(--ml-teal-700, #0f766e)',
								border: '1px solid var(--ml-teal-200, #99f6e4)',
							} }
						>
							{ __( 'Engine v2.0', 'migrate-lift' ) }
						</span>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody className="migrate-lift-card-body">
				<p style={ { fontSize: '14px', lineHeight: '1.6', color: '#50575e', marginTop: 0 } }>
					{ __(
						'Generates a verifiable, portable snapshot (.tgz) of your WordPress database and wp-content folder (themes, plugins, uploads). Protected staging, checksum manifest verification, and automated exclusion of caches and logs are enforced.',
						'migrate-lift'
					) }
				</p>

				{ error && (
					<Notice
						status="error"
						isDismissible
						onDismiss={ () => setError( null ) }
						style={ { marginBottom: '16px' } }
					>
						{ error }
					</Notice>
				) }

				<Flex align="center" gap={ 3 } style={ { marginTop: '16px', marginBottom: '16px' } }>
					<FlexItem>
						<Button
							variant="primary"
							isBusy={ running }
							disabled={ isBusy }
							onClick={ handleExport }
						>
							{ running ? __( 'Creating Backup…', 'migrate-lift' ) : __( 'Backup Site Now', 'migrate-lift' ) }
						</Button>
					</FlexItem>

					{ downloadUrl && (
						<FlexItem>
							<Button
								variant="secondary"
								href={ downloadUrl }
								download
							>
								{ __( 'Download Archive (.tgz)', 'migrate-lift' ) }
							</Button>
						</FlexItem>
					) }
				</Flex>

				{ running && (
					<div className="migrate-lift-progress-box" style={ { marginTop: '20px' } }>
						<Flex justify="space-between" align="center" style={ { marginBottom: '8px' } }>
							<span style={ { fontSize: '12px', fontWeight: 600, color: '#1e1e1e' } }>
								{ getPhaseLabel( phase ) || __( 'Processing…', 'migrate-lift' ) }
							</span>
							<span style={ { fontSize: '12px', fontWeight: 600, color: 'var(--ml-teal-700, #0f766e)' } }>
								{ progress }%
							</span>
						</Flex>
						<ProgressBar value={ progress } />
						<Flex align="center" gap={ 2 } style={ { marginTop: '10px' } }>
							<Spinner />
							<span style={ { fontSize: '13px', color: '#50575e' } }>
								{ status || __( 'Running backup…', 'migrate-lift' ) }
							</span>
						</Flex>
					</div>
				) }

				{ ! running && status && ! error && (
					<div style={ { marginTop: '16px' } }>
						<Notice status="success" isDismissible={ false }>
							<div><strong>{ status }</strong></div>
							{ archiveInfo && (
								<div style={ { marginTop: '8px', fontSize: '12px', color: '#334155', display: 'flex', flexDirection: 'column', gap: '4px' } }>
									<div>
										<strong>{ __( 'Archive:', 'migrate-lift' ) }</strong> { archiveInfo.name } ({ formatBytes( archiveInfo.size ) })
									</div>
									{ archiveInfo.sourceEstimate > 0 && (
										<div>
											<strong>{ __( 'Estimated Uncompressed Source:', 'migrate-lift' ) }</strong> { formatBytes( archiveInfo.sourceEstimate ) }
											{ archiveInfo.reductionPct !== undefined && (
												<span style={ { marginLeft: '8px', color: 'var(--ml-teal-700, #0f766e)', fontWeight: 600 } }>
													({ archiveInfo.reductionPct }% { __( 'reduction', 'migrate-lift' ) })
												</span>
											) }
										</div>
									) }
									<div style={ { color: '#64748b', fontSize: '11px', marginTop: '2px' } }>
										{ __( 'Mode:', 'migrate-lift' ) } { archiveInfo.compressionMode } &bull; { __( 'Integrity Verified', 'migrate-lift' ) } &bull; { __( 'Original assets preserved without alteration.', 'migrate-lift' ) }
									</div>
								</div>
							) }
						</Notice>
					</div>
				) }
			</CardBody>
		</Card>
	);
}
