/**
 * MigrateLift Main Application
 * Integrates AppShell with lightweight hash routing, design system components, and view state.
 */
import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import AppShell from './components/common/AppShell';
import DashboardView from './views/DashboardView';
import BackupsView from './views/BackupsView';
import ScheduleView from './views/ScheduleView';
import MigrateView from './views/MigrateView';
import ImportRestoreView from './views/ImportRestoreView';
import ActivityView from './views/ActivityView';
import SettingsView from './views/SettingsView';
import { post } from './utils/api';

const cfg = window.migrateLift || {};

const VALID_ROUTES = [
	'dashboard',
	'backups',
	'schedule',
	'migrate',
	'import',
	'activity',
	'settings',
];

function getInitialRoute() {
	if ( typeof window !== 'undefined' && window.location.hash ) {
		const hash = window.location.hash.replace( /^#\/?/, '' ).toLowerCase();
		if ( VALID_ROUTES.includes( hash ) ) {
			return hash;
		}
	}
	return 'dashboard';
}

export default function App() {
	const [ currentRoute, setCurrentRoute ] = useState( getInitialRoute );
	const [ isBusy, setBusy ] = useState( false );
	const [ archives, setArchives ] = useState( cfg.archives || [] );
	const importCardRef = useRef( null );

	// Listen to browser hash changes for back/forward navigation
	useEffect( () => {
		const handleHashChange = () => {
			const hash = window.location.hash.replace( /^#\/?/, '' ).toLowerCase();
			if ( VALID_ROUTES.includes( hash ) && hash !== currentRoute ) {
				setCurrentRoute( hash );
			}
		};

		window.addEventListener( 'hashchange', handleHashChange );
		return () => window.removeEventListener( 'hashchange', handleHashChange );
	}, [ currentRoute ] );

	const handleNavigate = useCallback( ( route ) => {
		if ( VALID_ROUTES.includes( route ) ) {
			setCurrentRoute( route );
			if ( typeof window !== 'undefined' && window.location.hash !== `#${ route }` ) {
				window.location.hash = route;
			}
		}
	}, [] );

	const refreshArchives = useCallback( async ( newList = null ) => {
		if ( Array.isArray( newList ) ) {
			setArchives( newList );
			return;
		}
		try {
			const list = await post( 'migrate_lift_list' );
			if ( Array.isArray( list ) ) {
				setArchives( list );
			}
		} catch ( e ) {
			// Maintain current list if error occurs
		}
	}, [] );

	const handleImportArchive = useCallback( ( archiveName ) => {
		handleNavigate( 'import' );
		setTimeout( () => {
			if ( importCardRef.current ) {
				importCardRef.current.importExisting( archiveName );
			}
		}, 60 );
	}, [ handleNavigate ] );

	if ( cfg.isMultisite ) {
		return (
			<div className="migrate-lift-app">
				<div style={ { maxWidth: '720px', margin: '40px auto', padding: '24px' } }>
					<h1 style={ { fontSize: '24px', fontWeight: 800, marginBottom: '16px', color: 'var(--ml-ink)' } }>
						{ __( 'MigrateLift – WordPress Migration, Backup & Restore', 'migrate-lift' ) }
					</h1>
					<Notice status="error" isDismissible={ false }>
						{ __(
							'WordPress Multisite networks are not supported by MigrateLift. Please activate MigrateLift on a standard single-site installation.',
							'migrate-lift'
						) }
					</Notice>
				</div>
			</div>
		);
	}

	return (
		<div className="migrate-lift-app">
			<AppShell
				activeRoute={ currentRoute }
				onRouteChange={ handleNavigate }
				archivesCount={ archives.length }
			>
				{ currentRoute === 'dashboard' && (
					<DashboardView
						archives={ archives }
						onNavigate={ handleNavigate }
					/>
				) }

				{ currentRoute === 'backups' && (
					<BackupsView
						archives={ archives }
						refreshArchives={ refreshArchives }
						onImportArchive={ handleImportArchive }
						isBusy={ isBusy }
						setBusy={ setBusy }
					/>
				) }

				{ currentRoute === 'schedule' && (
					<ScheduleView />
				) }

				{ currentRoute === 'migrate' && (
					<MigrateView />
				) }

				{ currentRoute === 'import' && (
					<ImportRestoreView
						ref={ importCardRef }
						isBusy={ isBusy }
						setBusy={ setBusy }
						onImportSuccess={ () => refreshArchives() }
					/>
				) }

				{ currentRoute === 'activity' && (
					<ActivityView />
				) }

				{ currentRoute === 'settings' && (
					<SettingsView
						isBusy={ isBusy }
						setBusy={ setBusy }
					/>
				) }
			</AppShell>
		</div>
	);
}
