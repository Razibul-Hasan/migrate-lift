/**
 * BackupsView Component
 * Comprehensive management interface for full WordPress site backups:
 * - Table / Card listings with sort, search, filters, and pagination
 * - Background job creation drawer with live stepping
 * - Manifest details drawer with checksums and storage limits
 * - Safe typed-confirmation delete protection
 * - Authenticated download stream links and integrity verification
 *
 * @package MigrateLift
 */
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import PageHeader from '../components/common/PageHeader';
import SectionCard from '../components/common/SectionCard';
import EmptyState from '../components/common/EmptyState';
import InlineNotice from '../components/common/InlineNotice';
import Skeleton from '../components/common/Skeleton';
import {
	IconBackup,
	IconPlus,
	IconRefresh,
	IconAlertTriangle,
	IconCheck,
} from '../components/common/Icons';
import BackupsTable from '../components/backups/BackupsTable';
import BackupsCardList from '../components/backups/BackupsCardList';
import BackupJobDrawer from '../components/backups/BackupJobDrawer';
import BackupDetailsDrawer from '../components/backups/BackupDetailsDrawer';
import SafeDeleteModal from '../components/backups/SafeDeleteModal';
import {
	fetchArchives,
	verifyBackup,
	deleteArchive,
	getActiveBackupJob,
} from '../utils/api';

export default function BackupsView( {
	archives: initialArchives = [],
	refreshArchives,
	onImportArchive,
	isBusy,
	setBusy,
} ) {
	const [ archivesList, setArchivesList ] = useState( initialArchives );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ actionNotice, setActionNotice ] = useState( null );

	// Filter & Sort State
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ typeFilter, setTypeFilter ] = useState( 'all' );
	const [ statusFilter, setStatusFilter ] = useState( 'all' );
	const [ sortKey, setSortKey ] = useState( 'time' );
	const [ sortDirection, setSortDirection ] = useState( 'desc' );

	// Pagination State
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ itemsPerPage, setItemsPerPage ] = useState( 10 );

	// Drawers & Modals
	const [ isJobDrawerOpen, setIsJobDrawerOpen ] = useState( false );
	const [ activeBackupJob, setActiveBackupJob ] = useState( null );
	const [ detailFileName, setDetailFileName ] = useState( null );
	const [ deletingArchive, setDeletingArchive ] = useState( null );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ verifyingFile, setVerifyingFile ] = useState( null );

	// Sync when initialArchives changes from parent
	useEffect( () => {
		if ( Array.isArray( initialArchives ) && initialArchives.length > 0 ) {
			setArchivesList( initialArchives );
		}
	}, [ initialArchives ] );

	// Check for active background backup job on mount
	const checkActiveJob = useCallback( async () => {
		const job = await getActiveBackupJob();
		if ( job ) {
			setActiveBackupJob( job );
		} else {
			setActiveBackupJob( null );
		}
	}, [] );

	useEffect( () => {
		checkActiveJob();
		const interval = setInterval( checkActiveJob, 10000 );
		return () => clearInterval( interval );
	}, [ checkActiveJob ] );

	// Fetch archives list
	const loadArchives = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		try {
			const data = await fetchArchives();
			setArchivesList( data );
			if ( refreshArchives ) {
				refreshArchives( data );
			}
		} catch ( err ) {
			setError( err.message || __( 'Could not load backup archives.', 'migrate-lift' ) );
		} finally {
			setIsLoading( false );
		}
	}, [ refreshArchives ] );

	// Handle sort click
	const handleSort = ( key ) => {
		if ( sortKey === key ) {
			setSortDirection( ( prev ) => ( prev === 'asc' ? 'desc' : 'asc' ) );
		} else {
			setSortKey( key );
			setSortDirection( 'desc' );
		}
		setCurrentPage( 1 );
	};

	// Handle archive verification
	const handleVerifyArchive = async ( fileName ) => {
		setVerifyingFile( fileName );
		setActionNotice( null );
		try {
			const res = await verifyBackup( fileName );
			if ( res?.archives ) {
				setArchivesList( res.archives );
				if ( refreshArchives ) refreshArchives( res.archives );
			} else {
				await loadArchives();
			}
			const isValid = res?.report?.valid;
			setActionNotice( {
				status: isValid ? 'success' : 'error',
				message: isValid
					? sprintf( __( 'Archive "%s" checksum and manifest verified successfully.', 'migrate-lift' ), fileName )
					: sprintf( __( 'Archive "%s" failed verification.', 'migrate-lift' ), fileName ),
			} );
		} catch ( err ) {
			setActionNotice( {
				status: 'error',
				message: err.message || __( 'Verification failed.', 'migrate-lift' ),
			} );
		} finally {
			setVerifyingFile( null );
		}
	};

	// Handle delete confirmation
	const handleConfirmDelete = async ( fileName ) => {
		setIsDeleting( true );
		setActionNotice( null );
		try {
			const res = await deleteArchive( fileName );
			setDeletingArchive( null );
			if ( detailFileName === fileName ) {
				setDetailFileName( null );
			}
			if ( res?.data ) {
				setArchivesList( res.data );
				if ( refreshArchives ) refreshArchives( res.data );
			} else {
				await loadArchives();
			}
			setActionNotice( {
				status: 'success',
				message: res?.message || sprintf( __( 'Archive "%s" permanently deleted.', 'migrate-lift' ), fileName ),
			} );
		} catch ( err ) {
			setActionNotice( {
				status: 'error',
				message: err.message || __( 'Could not delete archive.', 'migrate-lift' ),
			} );
		} finally {
			setIsDeleting( false );
		}
	};

	// Filter & Sort computation
	const filteredArchives = useMemo( () => {
		let list = [ ...( archivesList || [] ) ];

		// Search
		if ( searchQuery.trim() ) {
			const q = searchQuery.toLowerCase().trim();
			list = list.filter( ( a ) =>
				( a.name || '' ).toLowerCase().includes( q ) ||
				( a.date || '' ).toLowerCase().includes( q )
			);
		}

		// Type Filter
		if ( typeFilter !== 'all' ) {
			list = list.filter( ( a ) => ( a.type || 'full' ) === typeFilter );
		}

		// Status / Verification Filter
		if ( statusFilter === 'verified' ) {
			list = list.filter( ( a ) => a.verified === true );
		} else if ( statusFilter === 'unverified' ) {
			list = list.filter( ( a ) => ! a.verified && a.status !== 'damaged' );
		} else if ( statusFilter === 'damaged' ) {
			list = list.filter( ( a ) => a.status === 'damaged' );
		}

		// Sort
		list.sort( ( a, b ) => {
			let valA = a[ sortKey ];
			let valB = b[ sortKey ];

			if ( sortKey === 'size' || sortKey === 'raw_estimate' || sortKey === 'time' ) {
				valA = Number( valA ) || 0;
				valB = Number( valB ) || 0;
			} else if ( sortKey === 'verified' ) {
				valA = a.verified ? 1 : 0;
				valB = b.verified ? 1 : 0;
			} else {
				valA = String( valA || '' ).toLowerCase();
				valB = String( valB || '' ).toLowerCase();
			}

			if ( valA < valB ) return sortDirection === 'asc' ? -1 : 1;
			if ( valA > valB ) return sortDirection === 'asc' ? 1 : -1;
			return 0;
		} );

		return list;
	}, [ archivesList, searchQuery, typeFilter, statusFilter, sortKey, sortDirection ] );

	// Pagination computation
	const totalItems = filteredArchives.length;
	const totalPages = Math.max( 1, Math.ceil( totalItems / itemsPerPage ) );
	const paginatedArchives = useMemo( () => {
		const start = ( currentPage - 1 ) * itemsPerPage;
		return filteredArchives.slice( start, start + itemsPerPage );
	}, [ filteredArchives, currentPage, itemsPerPage ] );

	const hasActiveFilters = searchQuery !== '' || typeFilter !== 'all' || statusFilter !== 'all';

	const handleResetFilters = () => {
		setSearchQuery( '' );
		setTypeFilter( 'all' );
		setStatusFilter( 'all' );
		setCurrentPage( 1 );
	};

	return (
		<div className="ml-backups-view">
			{ /* Page Header */ }
			<PageHeader
				overline={ __( 'Data Protection', 'migrate-lift' ) }
				title={ __( 'Backups & Archives', 'migrate-lift' ) }
				description={ __(
					'Generate full snapshots of your WordPress site (database + wp-content) and manage stored archives.',
					'migrate-lift'
				) }
				actions={
					<>
						<button
							type="button"
							className="ml-btn"
							onClick={ loadArchives }
							disabled={ isLoading }
							title={ __( 'Refresh archives list from server', 'migrate-lift' ) }
						>
							<IconRefresh className={ isLoading ? 'ml-spin' : '' } />
							<span>{ __( 'Refresh', 'migrate-lift' ) }</span>
						</button>
						<button
							type="button"
							className="ml-btn primary"
							onClick={ () => setIsJobDrawerOpen( true ) }
						>
							<IconPlus />
							<span>
								{ activeBackupJob
									? __( 'Backup In Progress…', 'migrate-lift' )
									: __( 'Create Backup', 'migrate-lift' ) }
							</span>
						</button>
					</>
				}
			/>

			{ /* Active Background Job Floating Banner */ }
			{ activeBackupJob && (
				<div className="ml-active-job-banner">
					<div style={ { display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 } }>
						<div className="ml-spin" style={ { color: 'var(--ml-teal)' } }>
							<IconRefresh style={ { width: 18, height: 18 } } />
						</div>
						<div style={ { minWidth: 0 } }>
							<div style={ { fontWeight: 700, fontSize: 13, color: 'var(--ml-ink)' } }>
								{ __( 'Backup snapshot in progress', 'migrate-lift' ) } ({ Math.round( activeBackupJob.percentage || 0 ) }%)
							</div>
							<div style={ { fontSize: 12, color: 'var(--ml-muted)', textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' } }>
								{ activeBackupJob.diagnostic_detail || activeBackupJob.phase || __( 'Running tasks…', 'migrate-lift' ) }
							</div>
						</div>
					</div>
					<button
						type="button"
						className="ml-btn sm primary"
						onClick={ () => setIsJobDrawerOpen( true ) }
					>
						<span>{ __( 'View Progress', 'migrate-lift' ) }</span>
					</button>
				</div>
			) }

			{ /* Action Notice / Alert */ }
			{ actionNotice && (
				<InlineNotice
					status={ actionNotice.status === 'success' ? 'success' : 'error' }
					onDismiss={ () => setActionNotice( null ) }
				>
					{ actionNotice.message }
				</InlineNotice>
			) }

			{ /* Error Alert */ }
			{ error && (
				<InlineNotice status="error" onDismiss={ () => setError( null ) }>
					<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16 } }>
						<span>{ error }</span>
						<button type="button" className="ml-btn sm" onClick={ loadArchives }>
							<IconRefresh style={ { width: 12, height: 12 } } />
							<span>{ __( 'Retry', 'migrate-lift' ) }</span>
						</button>
					</div>
				</InlineNotice>
			) }

			{ /* Backups Main Content Card */ }
			<SectionCard
				title={ __( 'Server Archives', 'migrate-lift' ) }
				badge={ filteredArchives.length > 0 ? String( filteredArchives.length ) : undefined }
				description={ __( 'Archives stored in your protected wp-content/migrate-lift-files directory.', 'migrate-lift' ) }
			>
				{ /* Filter & Search Toolbar */ }
				<div className="ml-backups-toolbar">
					<div className="ml-toolbar-search-box">
						<input
							type="text"
							value={ searchQuery }
							onChange={ ( e ) => {
								setSearchQuery( e.target.value );
								setCurrentPage( 1 );
							} }
							placeholder={ __( 'Search by filename or date…', 'migrate-lift' ) }
							className="ml-search-input"
						/>
						{ searchQuery && (
							<button
								type="button"
								className="ml-search-clear-btn"
								onClick={ () => setSearchQuery( '' ) }
								aria-label={ __( 'Clear search', 'migrate-lift' ) }
							>
								×
							</button>
						)}
					</div>

					<div className="ml-toolbar-filters">
						{ /* Verification Filter */ }
						<select
							value={ statusFilter }
							onChange={ ( e ) => {
								setStatusFilter( e.target.value );
								setCurrentPage( 1 );
							} }
							className="ml-select"
							aria-label={ __( 'Filter by verification status', 'migrate-lift' ) }
						>
							<option value="all">{ __( 'All Statuses', 'migrate-lift' ) }</option>
							<option value="verified">{ __( 'Verified Only', 'migrate-lift' ) }</option>
							<option value="unverified">{ __( 'Unverified Only', 'migrate-lift' ) }</option>
							<option value="damaged">{ __( 'Damaged Only', 'migrate-lift' ) }</option>
						</select>

						{ /* Type Filter */ }
						<select
							value={ typeFilter }
							onChange={ ( e ) => {
								setTypeFilter( e.target.value );
								setCurrentPage( 1 );
							} }
							className="ml-select"
							aria-label={ __( 'Filter by backup type', 'migrate-lift' ) }
						>
							<option value="all">{ __( 'All Types', 'migrate-lift' ) }</option>
							<option value="full">{ __( 'Full Site', 'migrate-lift' ) }</option>
							<option value="scheduled">{ __( 'Daily Scheduled', 'migrate-lift' ) }</option>
						</select>

						{ hasActiveFilters && (
							<button
								type="button"
								className="ml-btn sm"
								onClick={ handleResetFilters }
							>
								<span>{ __( 'Reset Filters', 'migrate-lift' ) }</span>
							</button>
						)}
					</div>
				</div>

				{ /* Content Area */ }
				{ isLoading && archivesList.length === 0 ? (
					<div style={ { padding: '24px 0' } }>
						<Skeleton variant="card" count={ 3 } />
					</div>
				) : archivesList.length === 0 ? (
					/* Completely Empty State */
					<EmptyState
						icon={ <IconBackup /> }
						title={ __( 'No backups created yet', 'migrate-lift' ) }
						description={ __(
							'Create your first full site backup to protect your WordPress database, themes, plugins, and uploads.',
							'migrate-lift'
						) }
						actionLabel={ __( 'Create your first backup', 'migrate-lift' ) }
						onAction={ () => setIsJobDrawerOpen( true ) }
					/>
				) : filteredArchives.length === 0 ? (
					/* Filter Empty State */
					<div className="ml-filter-empty-box">
						<IconAlertTriangle style={ { width: 32, height: 32, color: 'var(--ml-amber)' } } />
						<h4>{ __( 'No backups match your filters', 'migrate-lift' ) }</h4>
						<p>{ __( 'Try adjusting your search query or reset the filters to see all available archives.', 'migrate-lift' ) }</p>
						<button
							type="button"
							className="ml-btn primary"
							onClick={ handleResetFilters }
						>
							{ __( 'Reset All Filters', 'migrate-lift' ) }
						</button>
					</div>
				) : (
					<>
						{ /* Desktop Table View (Hidden on mobile) */ }
						<div className="ml-desktop-only">
							<BackupsTable
								archives={ paginatedArchives }
								onVerify={ handleVerifyArchive }
								onViewDetails={ ( name ) => setDetailFileName( name ) }
								onDelete={ ( archive ) => setDeletingArchive( archive ) }
								verifyingFile={ verifyingFile }
								sortKey={ sortKey }
								sortDirection={ sortDirection }
								onSort={ handleSort }
							/>
						</div>

						{ /* Mobile Cards View (Hidden on desktop) */ }
						<div className="ml-mobile-only">
							<BackupsCardList
								archives={ paginatedArchives }
								onVerify={ handleVerifyArchive }
								onViewDetails={ ( name ) => setDetailFileName( name ) }
								onDelete={ ( archive ) => setDeletingArchive( archive ) }
								verifyingFile={ verifyingFile }
							/>
						</div>

						{ /* Pagination Bar */ }
						<div className="ml-pagination-bar">
							<div className="ml-pagination-info">
								{ sprintf(
									__( 'Showing %1$d–%2$d of %3$d archives', 'migrate-lift' ),
									Math.min( totalItems, ( currentPage - 1 ) * itemsPerPage + 1 ),
									Math.min( totalItems, currentPage * itemsPerPage ),
									totalItems
								) }
							</div>

							<div className="ml-pagination-controls">
								<select
									value={ itemsPerPage }
									onChange={ ( e ) => {
										setItemsPerPage( Number( e.target.value ) );
										setCurrentPage( 1 );
									} }
									className="ml-select sm"
									aria-label={ __( 'Rows per page', 'migrate-lift' ) }
								>
									<option value={ 10 }>10 / page</option>
									<option value={ 25 }>25 / page</option>
									<option value={ 50 }>50 / page</option>
								</select>

								<div style={ { display: 'flex', gap: 6 } }>
									<button
										type="button"
										className="ml-btn sm"
										disabled={ currentPage <= 1 }
										onClick={ () => setCurrentPage( ( p ) => Math.max( 1, p - 1 ) ) }
									>
										{ __( 'Previous', 'migrate-lift' ) }
									</button>
									<button
										type="button"
										className="ml-btn sm"
										disabled={ currentPage >= totalPages }
										onClick={ () => setCurrentPage( ( p ) => Math.min( totalPages, p + 1 ) ) }
									>
										{ __( 'Next', 'migrate-lift' ) }
									</button>
								</div>
							</div>
						</div>
					</>
				)}
			</SectionCard>

			{ /* Backup Job Creation Drawer */ }
			<BackupJobDrawer
				isOpen={ isJobDrawerOpen }
				activeJob={ activeBackupJob }
				onClose={ () => {
					setIsJobDrawerOpen( false );
					checkActiveJob();
					loadArchives();
				} }
				onSuccess={ () => {
					loadArchives();
					checkActiveJob();
				} }
				onViewDetails={ ( name ) => {
					setIsJobDrawerOpen( false );
					setDetailFileName( name );
				} }
			/>

			{ /* Manifest Details Drawer */ }
			<BackupDetailsDrawer
				isOpen={ !! detailFileName }
				fileName={ detailFileName }
				onClose={ () => setDetailFileName( null ) }
				onDelete={ ( archive ) => setDeletingArchive( archive ) }
			/>

			{ /* Safe Delete Confirmation Modal */ }
			<SafeDeleteModal
				isOpen={ !! deletingArchive }
				archive={ deletingArchive }
				isBusy={ isDeleting }
				onConfirm={ handleConfirmDelete }
				onCancel={ () => setDeletingArchive( null ) }
			/>
		</div>
	);
}
