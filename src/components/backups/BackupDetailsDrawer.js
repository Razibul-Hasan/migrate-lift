/**
 * BackupDetailsDrawer Component
 * Slide-over drawer displaying full manifest components, counts, exclusions,
 * database checksums, and storage limits for an archive.
 *
 * @package MigrateLift
 */
import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	IconClose,
	IconCheck,
	IconAlertTriangle,
	IconDownload,
	IconTrash,
	IconRefresh,
	IconHarddrive,
	IconServer,
	IconShield,
} from '../common/Icons';
import StatusPill from '../common/StatusPill';
import Skeleton from '../common/Skeleton';
import { formatBytes, fetchArchiveDetails, verifyBackup } from '../../utils/api';

export default function BackupDetailsDrawer( {
	isOpen,
	fileName,
	onClose,
	onDownload,
	onDelete,
} ) {
	const [ details, setDetails ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isVerifying, setIsVerifying ] = useState( false );
	const [ copySuccess, setCopySuccess ] = useState( false );
	const [ error, setError ] = useState( null );

	const loadDetails = async () => {
		if ( ! fileName ) return;
		setIsLoading( true );
		setError( null );
		try {
			const data = await fetchArchiveDetails( fileName );
			setDetails( data );
		} catch ( err ) {
			setError( err.message || __( 'Could not load archive details.', 'migrate-lift' ) );
		} finally {
			setIsLoading( false );
		}
	};

	useEffect( () => {
		if ( isOpen && fileName ) {
			loadDetails();
		}
	}, [ isOpen, fileName ] );

	const handleVerify = async () => {
		if ( ! fileName || isVerifying ) return;
		setIsVerifying( true );
		try {
			await verifyBackup( fileName );
			await loadDetails();
		} catch ( err ) {
			setError( err.message );
		} finally {
			setIsVerifying( false );
		}
	};

	const handleCopyChecksum = ( text ) => {
		if ( ! navigator?.clipboard ) return;
		navigator.clipboard.writeText( text );
		setCopySuccess( true );
		setTimeout( () => setCopySuccess( false ), 2000 );
	};

	if ( ! isOpen ) return null;

	const manifest = details?.manifest || {};
	const env = manifest.source_environment || {};
	const comp = manifest.component_inventory || {};
	const db = manifest.database_sections || {};
	const compression = manifest.compression || {};
	const storage = details?.storage || {};

	const isVerified = details?.verified === true;
	const isDamaged = details?.status === 'damaged';

	return (
		<div className="ml-slide-drawer-backdrop" onClick={ onClose } role="presentation">
			<div
				className="ml-drawer-panel ml-details-drawer"
				onClick={ ( e ) => e.stopPropagation() }
				role="dialog"
				aria-modal="true"
				aria-labelledby="ml-details-drawer-title"
			>
				{ /* Drawer Header */ }
				<div className="ml-drawer-header">
					<div style={ { minWidth: 0 } }>
						<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 } }>
							<StatusPill
								status={ isVerified ? 'success' : isDamaged ? 'danger' : 'warning' }
								label={ isVerified ? __( 'Verified', 'migrate-lift' ) : isDamaged ? __( 'Damaged', 'migrate-lift' ) : __( 'Unverified', 'migrate-lift' ) }
							/>
							<span style={ { fontSize: 11, color: 'var(--ml-muted)', textTransform: 'uppercase', fontWeight: 700 } }>
								{ details?.format ? details.format.toUpperCase() : 'TGZ' }
							</span>
						</div>
						<h3
							id="ml-details-drawer-title"
							className="ml-drawer-title"
							style={ { wordBreak: 'break-all', fontFamily: 'monospace', fontSize: 15 } }
						>
							{ fileName }
						</h3>
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
					{ isLoading ? (
						<div style={ { display: 'flex', flexDirection: 'column', gap: 16 } }>
							<Skeleton variant="card" count={ 2 } />
							<Skeleton variant="text" width="60%" />
							<Skeleton variant="text" width="90%" />
							<Skeleton variant="text" width="75%" />
						</div>
					) : error ? (
						<div className="ml-job-error-box">
							<div style={ { display: 'flex', alignItems: 'center', gap: 10, color: 'var(--ml-red)', marginBottom: 8 } }>
								<IconAlertTriangle />
								<strong style={ { fontSize: 14 } }>{ __( 'Failed to Load Details', 'migrate-lift' ) }</strong>
							</div>
							<p style={ { fontSize: 13, color: 'var(--ml-ink-secondary)', margin: '0 0 16px' } }>{ error }</p>
							<button type="button" className="ml-btn primary" onClick={ loadDetails }>
								<IconRefresh />
								<span>{ __( 'Retry', 'migrate-lift' ) }</span>
							</button>
						</div>
					) : (
						<div className="ml-details-content">
							{ /* Top Quick Metrics */ }
							<div className="ml-details-metrics-grid">
								<div className="ml-detail-metric">
									<span className="ml-detail-metric-label">{ __( 'Archive Size', 'migrate-lift' ) }</span>
									<strong className="ml-detail-metric-val">{ formatBytes( details?.size ) }</strong>
								</div>
								<div className="ml-detail-metric">
									<span className="ml-detail-metric-label">{ __( 'Raw Estimate', 'migrate-lift' ) }</span>
									<strong className="ml-detail-metric-val">{ formatBytes( details?.raw_estimate ) }</strong>
								</div>
								<div className="ml-detail-metric">
									<span className="ml-detail-metric-label">{ __( 'Reduction', 'migrate-lift' ) }</span>
									<strong className="ml-detail-metric-val" style={ { color: 'var(--ml-teal)' } }>
										{ details?.reduction_percentage ? `${ details.reduction_percentage.toFixed( 1 ) }%` : '0%' }
									</strong>
								</div>
								<div className="ml-detail-metric">
									<span className="ml-detail-metric-label">{ __( 'Created', 'migrate-lift' ) }</span>
									<span className="ml-detail-metric-sub">{ details?.date || '—' }</span>
								</div>
							</div>

							{ /* Integrity & Verification Card */ }
							<div className="ml-detail-section-card">
								<div className="ml-detail-section-header">
									<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
										<IconShield style={ { width: 16, height: 16, color: 'var(--ml-teal)' } } />
										<h4>{ __( 'Integrity & Verification', 'migrate-lift' ) }</h4>
									</div>
									<button
										type="button"
										className="ml-btn sm"
										disabled={ isVerifying }
										onClick={ handleVerify }
									>
										<IconRefresh style={ { width: 12, height: 12 } } />
										<span>{ isVerifying ? __( 'Verifying…', 'migrate-lift' ) : __( 'Verify Now', 'migrate-lift' ) }</span>
									</button>
								</div>
								<div className="ml-detail-rows">
									<div className="ml-detail-row">
										<span>{ __( 'Verification Status', 'migrate-lift' ) }</span>
										<StatusPill
											status={ isVerified ? 'success' : isDamaged ? 'danger' : 'warning' }
											label={ isVerified ? __( 'Checksum & Manifest Verified', 'migrate-lift' ) : isDamaged ? __( 'Integrity Check Failed', 'migrate-lift' ) : __( 'Pending Verification', 'migrate-lift' ) }
										/>
									</div>
									{ details?.checksum && (
										<div className="ml-detail-row">
											<span>{ __( 'Database SHA-256', 'migrate-lift' ) }</span>
											<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
												<code className="ml-code-hash" title={ details.checksum }>
													{ details.checksum.slice( 0, 12 ) }…{ details.checksum.slice( -8 ) }
												</code>
												<button
													type="button"
													className="ml-btn sm"
													onClick={ () => handleCopyChecksum( details.checksum ) }
													title={ __( 'Copy full hash to clipboard', 'migrate-lift' ) }
												>
													{ copySuccess ? __( 'Copied!', 'migrate-lift' ) : __( 'Copy', 'migrate-lift' ) }
												</button>
											</div>
										</div>
									)}
									{ details?.errors && details.errors.length > 0 && (
										<div className="ml-detail-row-errors">
											<strong style={ { color: 'var(--ml-red)', fontSize: 12 } }>
												{ __( 'Verification Errors:', 'migrate-lift' ) }
											</strong>
											<ul style={ { margin: '4px 0 0', paddingLeft: 18, fontSize: 12, color: 'var(--ml-red)' } }>
												{ details.errors.map( ( err, idx ) => (
													<li key={ idx }>{ err }</li>
												) ) }
											</ul>
										</div>
									)}
								</div>
							</div>

							{ /* Origin Site Environment */ }
							<div className="ml-detail-section-card">
								<div className="ml-detail-section-header">
									<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
										<IconServer style={ { width: 16, height: 16, color: 'var(--ml-blue)' } } />
										<h4>{ __( 'Origin Site Environment', 'migrate-lift' ) }</h4>
									</div>
								</div>
								<div className="ml-detail-rows">
									<div className="ml-detail-row">
										<span>{ __( 'Home URL', 'migrate-lift' ) }</span>
										<code>{ env.home_url || details?.manifest?.home || '—' }</code>
									</div>
									<div className="ml-detail-row">
										<span>{ __( 'Site URL', 'migrate-lift' ) }</span>
										<code>{ env.site_url || details?.manifest?.siteurl || '—' }</code>
									</div>
									<div className="ml-detail-row">
										<span>{ __( 'Table Prefix', 'migrate-lift' ) }</span>
										<code>{ env.table_prefix || details?.manifest?.prefix || 'wp_' }</code>
									</div>
									<div className="ml-detail-row">
										<span>{ __( 'WordPress / PHP / MySQL', 'migrate-lift' ) }</span>
										<span>
											WP { env.wp_version || '—' } • PHP { env.php_version || '—' } • MySQL { env.mysql_version || '—' }
										</span>
									</div>
								</div>
							</div>

							{ /* Database & File Inventory */ }
							<div className="ml-detail-section-card">
								<div className="ml-detail-section-header">
									<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
										<IconHarddrive style={ { width: 16, height: 16, color: 'var(--ml-purple)' } } />
										<h4>{ __( 'Component Inventory', 'migrate-lift' ) }</h4>
									</div>
								</div>
								<div className="ml-detail-rows">
									<div className="ml-detail-row">
										<span>{ __( 'Database Tables', 'migrate-lift' ) }</span>
										<strong>{ db.tables?.length || comp.tables_count || '—' } { __( 'tables', 'migrate-lift' ) }</strong>
									</div>
									<div className="ml-detail-row">
										<span>{ __( 'Database SQL Dump', 'migrate-lift' ) }</span>
										<span>{ formatBytes( db.sql_size ) } { db.total_rows ? `(${ db.total_rows.toLocaleString() } rows)` : '' }</span>
									</div>
									<div className="ml-detail-row">
										<span>{ __( 'Files Included', 'migrate-lift' ) }</span>
										<strong>{ comp.files_count ? comp.files_count.toLocaleString() : '—' } { __( 'files', 'migrate-lift' ) }</strong>
									</div>
									<div className="ml-detail-row">
										<span>{ __( 'Uncompressed Files Size', 'migrate-lift' ) }</span>
										<span>{ formatBytes( comp.files_bytes ) }</span>
									</div>
									<div className="ml-detail-row">
										<span>{ __( 'Exclusions Applied', 'migrate-lift' ) }</span>
										<span style={ { fontSize: 12, color: 'var(--ml-muted)' } }>
											{ __( 'Caches, logs, other backup folders (.git, node_modules)', 'migrate-lift' ) }
										</span>
									</div>
								</div>
							</div>

							{ /* Storage & Server Limits */ }
							<div className="ml-detail-section-card">
								<div className="ml-detail-section-header">
									<h4>{ __( 'Storage Location & Host Limits', 'migrate-lift' ) }</h4>
								</div>
								<div className="ml-detail-rows">
									<div className="ml-detail-row">
										<span>{ __( 'Storage Path', 'migrate-lift' ) }</span>
										<code>wp-content/{ storage.directory || 'migrate-lift-files' }/</code>
									</div>
									<div className="ml-detail-row">
										<span>{ __( 'Storage Writability', 'migrate-lift' ) }</span>
										<StatusPill
											status={ storage.writable ? 'success' : 'danger' }
											label={ storage.writable ? __( 'Writable', 'migrate-lift' ) : __( 'Read Only', 'migrate-lift' ) }
										/>
									</div>
									{ storage.disk_free_bytes && (
										<div className="ml-detail-row">
											<span>{ __( 'Server Free Disk Space', 'migrate-lift' ) }</span>
											<strong>{ formatBytes( storage.disk_free_bytes ) }</strong>
										</div>
									)}
								</div>
							</div>
						</div>
					)}
				</div>

				{ /* Drawer Footer */ }
				<div className="ml-drawer-footer" style={ { justifyContent: 'space-between' } }>
					<button
						type="button"
						className="ml-btn danger"
						onClick={ () => onDelete( details || { name: fileName } ) }
					>
						<IconTrash />
						<span>{ __( 'Delete Backup', 'migrate-lift' ) }</span>
					</button>

					<div style={ { display: 'flex', gap: 8 } }>
						<button
							type="button"
							className="ml-btn"
							onClick={ onClose }
						>
							{ __( 'Close', 'migrate-lift' ) }
						</button>
						{ details?.download && (
							<a
								href={ details.download }
								className="ml-btn primary"
								download
							>
								<IconDownload />
								<span>{ __( 'Download', 'migrate-lift' ) }</span>
							</a>
						)}
					</div>
				</div>
			</div>
		</div>
	);
}

