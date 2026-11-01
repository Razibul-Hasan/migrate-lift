/**
 * BackupsCardList Component
 * Responsive card presentation of backup archives for mobile and tablet viewports.
 *
 * @package MigrateLift
 */
import { __ } from '@wordpress/i18n';
import {
	IconDownload,
	IconTrash,
	IconRefresh,
	IconInfo,
} from '../common/Icons';
import StatusPill from '../common/StatusPill';
import { formatBytes, formatRelativeTime } from '../../utils/api';

export default function BackupsCardList( {
	archives = [],
	onVerify,
	onViewDetails,
	onDelete,
	verifyingFile = null,
} ) {
	return (
		<div className="ml-backups-card-list">
			{ archives.map( ( archive ) => {
				const isVerified = archive.verified === true;
				const isDamaged = archive.status === 'damaged';
				const isThisVerifying = verifyingFile === archive.name;

				return (
					<div key={ archive.name } className="ml-backup-mobile-card">
						<div className="ml-mobile-card-top">
							<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
								<span className="ml-format-badge">
									{ archive.format ? archive.format.toUpperCase() : 'TGZ' }
								</span>
								<span className="ml-badge-type">
									{ archive.type === 'scheduled' ? __( 'Scheduled', 'migrate-lift' ) : __( 'Full Site', 'migrate-lift' ) }
								</span>
							</div>
							<StatusPill
								status={ isVerified ? 'success' : isDamaged ? 'danger' : 'warning' }
								label={ isVerified ? __( 'Verified', 'migrate-lift' ) : isDamaged ? __( 'Damaged', 'migrate-lift' ) : __( 'Unverified', 'migrate-lift' ) }
							/>
						</div>

						<h4 className="ml-mobile-card-filename" onClick={ () => onViewDetails( archive.name ) }>
							{ archive.name }
						</h4>

						<div className="ml-mobile-card-meta">
							{ archive.date } • { formatRelativeTime( archive.time ) }
						</div>

						<div className="ml-mobile-card-stats">
							<div className="ml-mobile-stat">
								<span className="ml-mobile-stat-label">{ __( 'Size', 'migrate-lift' ) }</span>
								<strong className="ml-mobile-stat-val">
									{ formatBytes( archive.size ) }
									{ archive.reduction_percentage > 0 && (
										<span className="ml-reduction-tag" style={ { marginLeft: 4 } }>
											-{ Math.round( archive.reduction_percentage ) }%
										</span>
									)}
								</strong>
							</div>
							<div className="ml-mobile-stat">
								<span className="ml-mobile-stat-label">{ __( 'Raw Estimate', 'migrate-lift' ) }</span>
								<span className="ml-mobile-stat-val">
									{ formatBytes( archive.raw_estimate || archive.size ) }
								</span>
							</div>
							<div className="ml-mobile-stat">
								<span className="ml-mobile-stat-label">{ __( 'Storage', 'migrate-lift' ) }</span>
								<span className="ml-mobile-stat-val">{ __( 'Local', 'migrate-lift' ) }</span>
							</div>
						</div>

						<div className="ml-mobile-card-actions">
							<button
								type="button"
								className="ml-btn sm"
								disabled={ isThisVerifying }
								onClick={ () => onVerify( archive.name ) }
							>
								<IconRefresh className={ isThisVerifying ? 'ml-spin' : '' } style={ { width: 13, height: 13 } } />
								<span>{ isThisVerifying ? __( 'Verifying…', 'migrate-lift' ) : __( 'Verify', 'migrate-lift' ) }</span>
							</button>

							<button
								type="button"
								className="ml-btn sm"
								onClick={ () => onViewDetails( archive.name ) }
							>
								<IconInfo style={ { width: 13, height: 13 } } />
								<span>{ __( 'Details', 'migrate-lift' ) }</span>
							</button>

							{ archive.download && (
								<a
									href={ archive.download }
									className="ml-btn sm"
									download
								>
									<IconDownload style={ { width: 13, height: 13 } } />
									<span>{ __( 'Download', 'migrate-lift' ) }</span>
								</a>
							)}

							<button
								type="button"
								className="ml-btn sm danger"
								onClick={ () => onDelete( archive ) }
								disabled={ !! archive.is_protected }
								title={
									archive.is_protected
										? __( 'Recovery checkpoint is protected from deletion', 'migrate-lift' )
										: __( 'Delete archive', 'migrate-lift' )
								}
							>
								<IconTrash style={ { width: 13, height: 13 } } />
							</button>
						</div>
					</div>
				);
			} ) }
		</div>
	);
}

