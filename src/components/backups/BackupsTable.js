/**
 * BackupsTable Component
 * Accessible, sortable data table for managing WordPress backup archives.
 *
 * @package MigrateLift
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	IconCheck,
	IconAlertTriangle,
	IconDownload,
	IconTrash,
	IconRefresh,
	IconInfo,
} from '../common/Icons';
import StatusPill from '../common/StatusPill';
import { formatBytes, formatRelativeTime } from '../../utils/api';

export default function BackupsTable( {
	archives = [],
	onVerify,
	onViewDetails,
	onDelete,
	verifyingFile = null,
	sortKey = 'time',
	sortDirection = 'desc',
	onSort,
} ) {
	const handleHeaderClick = ( key ) => {
		if ( onSort ) {
			onSort( key );
		}
	};

	const getAriaSort = ( key ) => {
		if ( sortKey !== key ) return 'none';
		return sortDirection === 'asc' ? 'ascending' : 'descending';
	};

	return (
		<div className="ml-table-responsive-wrapper">
			<table className="ml-table ml-backups-table" role="table" aria-label={ __( 'Backup archives list', 'migrate-lift' ) }>
				<thead>
					<tr>
						{ /* 1. Name & Date */ }
						<th
							scope="col"
							aria-sort={ getAriaSort( 'name' ) }
							onClick={ () => handleHeaderClick( 'name' ) }
							onKeyDown={ ( e ) => ( e.key === 'Enter' || e.key === ' ' ) && handleHeaderClick( 'name' ) }
							tabIndex={ 0 }
							style={ { cursor: 'pointer', minWidth: 220 } }
						>
							<div className="ml-th-content">
								<span>{ __( 'Archive / Date', 'migrate-lift' ) }</span>
								<span className="ml-sort-indicator" aria-hidden="true">
									{ sortKey === 'name' ? ( sortDirection === 'asc' ? '▲' : '▼' ) : '⇅' }
								</span>
							</div>
						</th>

						{ /* 2. Type */ }
						<th scope="col" style={ { width: 100 } }>
							{ __( 'Type', 'migrate-lift' ) }
						</th>

						{ /* 3. Raw Estimate */ }
						<th
							scope="col"
							aria-sort={ getAriaSort( 'raw_estimate' ) }
							onClick={ () => handleHeaderClick( 'raw_estimate' ) }
							onKeyDown={ ( e ) => ( e.key === 'Enter' || e.key === ' ' ) && handleHeaderClick( 'raw_estimate' ) }
							tabIndex={ 0 }
							style={ { cursor: 'pointer', width: 120 } }
						>
							<div className="ml-th-content">
								<span>{ __( 'Raw Estimate', 'migrate-lift' ) }</span>
								<span className="ml-sort-indicator" aria-hidden="true">
									{ sortKey === 'raw_estimate' ? ( sortDirection === 'asc' ? '▲' : '▼' ) : '⇅' }
								</span>
							</div>
						</th>

						{ /* 4. Archive Size */ }
						<th
							scope="col"
							aria-sort={ getAriaSort( 'size' ) }
							onClick={ () => handleHeaderClick( 'size' ) }
							onKeyDown={ ( e ) => ( e.key === 'Enter' || e.key === ' ' ) && handleHeaderClick( 'size' ) }
							tabIndex={ 0 }
							style={ { cursor: 'pointer', width: 130 } }
						>
							<div className="ml-th-content">
								<span>{ __( 'Archive Size', 'migrate-lift' ) }</span>
								<span className="ml-sort-indicator" aria-hidden="true">
									{ sortKey === 'size' ? ( sortDirection === 'asc' ? '▲' : '▼' ) : '⇅' }
								</span>
							</div>
						</th>

						{ /* 5. Verification */ }
						<th
							scope="col"
							aria-sort={ getAriaSort( 'verified' ) }
							onClick={ () => handleHeaderClick( 'verified' ) }
							onKeyDown={ ( e ) => ( e.key === 'Enter' || e.key === ' ' ) && handleHeaderClick( 'verified' ) }
							tabIndex={ 0 }
							style={ { cursor: 'pointer', width: 130 } }
						>
							<div className="ml-th-content">
								<span>{ __( 'Verification', 'migrate-lift' ) }</span>
								<span className="ml-sort-indicator" aria-hidden="true">
									{ sortKey === 'verified' ? ( sortDirection === 'asc' ? '▲' : '▼' ) : '⇅' }
								</span>
							</div>
						</th>

						{ /* 6. Storage Source */ }
						<th scope="col" style={ { width: 100 } }>
							{ __( 'Storage', 'migrate-lift' ) }
						</th>

						{ /* 7. Status */ }
						<th scope="col" style={ { width: 90 } }>
							{ __( 'Status', 'migrate-lift' ) }
						</th>

						{ /* 8. Actions */ }
						<th scope="col" style={ { textAlign: 'right', minWidth: 180 } }>
							{ __( 'Actions', 'migrate-lift' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ archives.map( ( archive ) => {
						const isVerified = archive.verified === true;
						const isDamaged = archive.status === 'damaged';
						const isThisVerifying = verifyingFile === archive.name;

						return (
							<tr key={ archive.name } className="ml-table-row">
								{ /* 1. Name & Date */ }
								<td className="ml-cell-name">
									<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
										<span className="ml-format-badge">
											{ archive.format ? archive.format.toUpperCase() : 'TGZ' }
										</span>
										<button
											type="button"
											className="ml-table-filename-btn"
											onClick={ () => onViewDetails( archive.name ) }
											title={ __( 'View manifest details', 'migrate-lift' ) }
										>
											{ archive.name }
										</button>
									</div>
									<div className="ml-cell-subtext">
										{ archive.date } • { formatRelativeTime( archive.time ) }
									</div>
								</td>

								{ /* 2. Type */ }
								<td>
									<span className="ml-badge-type">
										{ archive.type === 'scheduled' ? __( 'Daily Scheduled', 'migrate-lift' ) : __( 'Full Site', 'migrate-lift' ) }
									</span>
								</td>

								{ /* 3. Raw Estimate */ }
								<td>
									<span className="ml-cell-value">
										{ formatBytes( archive.raw_estimate || archive.size ) }
									</span>
								</td>

								{ /* 4. Archive Size */ }
								<td>
									<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
										<strong className="ml-cell-value">
											{ formatBytes( archive.size ) }
										</strong>
										{ archive.reduction_percentage > 0 && (
											<span className="ml-reduction-tag" title={ __( 'Compression savings', 'migrate-lift' ) }>
												-{ Math.round( archive.reduction_percentage ) }%
											</span>
										)}
									</div>
								</td>

								{ /* 5. Verification */ }
								<td>
									<StatusPill
										status={ isVerified ? 'success' : isDamaged ? 'danger' : 'warning' }
										label={ isVerified ? __( 'Verified', 'migrate-lift' ) : isDamaged ? __( 'Damaged', 'migrate-lift' ) : __( 'Unverified', 'migrate-lift' ) }
									/>
								</td>

								{ /* 6. Storage Source */ }
								<td>
									<span className="ml-badge-storage">
										{ __( 'Local', 'migrate-lift' ) }
									</span>
								</td>

								{ /* 7. Status */ }
								<td>
									<StatusPill
										status={ archive.status === 'damaged' ? 'danger' : 'success' }
										label={ archive.status === 'damaged' ? __( 'Damaged', 'migrate-lift' ) : __( 'Ready', 'migrate-lift' ) }
									/>
								</td>

								{ /* 8. Actions Menu / Buttons */ }
								<td style={ { textAlign: 'right' } }>
									<div className="ml-table-actions-group">
										{ /* Verify Button */ }
										<button
											type="button"
											className="ml-action-btn"
											disabled={ isThisVerifying }
											onClick={ () => onVerify( archive.name ) }
											title={ __( 'Verify archive integrity and checksums', 'migrate-lift' ) }
										>
											<IconRefresh className={ isThisVerifying ? 'ml-spin' : '' } />
											<span>{ isThisVerifying ? __( 'Verifying…', 'migrate-lift' ) : __( 'Verify', 'migrate-lift' ) }</span>
										</button>

										{ /* Details Button */ }
										<button
											type="button"
											className="ml-action-btn"
											onClick={ () => onViewDetails( archive.name ) }
											title={ __( 'View manifest details & components', 'migrate-lift' ) }
										>
											<IconInfo />
											<span>{ __( 'Details', 'migrate-lift' ) }</span>
										</button>

										{ /* Download Link */ }
										{ archive.download && (
											<a
												href={ archive.download }
												className="ml-action-btn"
												download
												title={ __( 'Download archive (authenticated)', 'migrate-lift' ) }
											>
												<IconDownload />
												<span>{ __( 'Download', 'migrate-lift' ) }</span>
											</a>
										)}

										{ /* Delete Button */ }
										<button
											type="button"
											className="ml-action-btn danger"
											onClick={ () => onDelete( archive ) }
											disabled={ !! archive.is_protected }
											title={
												archive.is_protected
													? __( 'Recovery checkpoint is protected from deletion', 'migrate-lift' )
													: __( 'Permanently delete this backup', 'migrate-lift' )
											}
										>
											<IconTrash />
										</button>
									</div>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}

