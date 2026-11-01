/**
 * AppShell Component
 * Top header with brand and site link, a single row of underlined tabs, and the page content below.
 * Horizontal navigation avoids stacking a second sidebar next to the WordPress admin menu.
 */
import { __ } from '@wordpress/i18n';
import { IconMigrate } from './Icons';

const cfg = window.migrateLift || {};

function getSiteHost() {
	try {
		return new URL( cfg.home ).host;
	} catch ( e ) {
		return '';
	}
}

export default function AppShell( {
	activeRoute = 'dashboard',
	onRouteChange,
	archivesCount = 0,
	children,
} ) {
	const siteHost = getSiteHost();

	const navItems = [
		{ id: 'dashboard', label: __( 'Overview', 'migrate-lift' ) },
		{
			id: 'backups',
			label: __( 'Backups', 'migrate-lift' ),
			count: archivesCount > 0 ? archivesCount : undefined,
		},
		{ id: 'schedule', label: __( 'Schedule', 'migrate-lift' ) },
		{ id: 'import', label: __( 'Import & Restore', 'migrate-lift' ) },
		{ id: 'migrate', label: __( 'Migrate Site', 'migrate-lift' ) },
		{ id: 'activity', label: __( 'Activity', 'migrate-lift' ) },
		{ id: 'settings', label: __( 'Settings', 'migrate-lift' ) },
	];

	const handleNavClick = ( routeId ) => {
		if ( typeof onRouteChange === 'function' ) {
			onRouteChange( routeId );
		}
	};

	return (
		<div className="ml-layout">
			<header className="ml-header">
				<div className="ml-header-inner">
					<div className="ml-header-top">
						<div className="ml-brand">
							{ cfg.logoUrl ? (
								<img className="ml-brand-logo-img" src={ cfg.logoUrl } alt="" width="32" height="32" />
							) : (
								<div className="ml-brand-logo" aria-hidden="true">
									<IconMigrate />
								</div>
							) }
							<span className="ml-brand-title">
								Migrate<span className="ml-brand-title-accent">Lift</span>
							</span>
							{ cfg.pluginVersion && (
								<span className="ml-brand-version">v{ cfg.pluginVersion }</span>
							) }
						</div>

						{ siteHost && (
							<a
								className="ml-site-chip"
								href={ cfg.home }
								target="_blank"
								rel="noopener noreferrer"
								title={ __( 'Visit site', 'migrate-lift' ) }
							>
								<span className="ml-site-chip-dot" aria-hidden="true" />
								{ siteHost }
							</a>
						) }
					</div>

					<nav className="ml-tabs" aria-label={ __( 'MigrateLift Navigation', 'migrate-lift' ) }>
						<ul className="ml-tabs-list">
							{ navItems.map( ( item ) => {
								const isActive = activeRoute === item.id;
								return (
									<li key={ item.id }>
										<button
											type="button"
											className={ `ml-tab ${ isActive ? 'is-active' : '' }` }
											onClick={ () => handleNavClick( item.id ) }
											aria-current={ isActive ? 'page' : undefined }
										>
											{ item.label }
											{ item.count !== undefined && (
												<span className="ml-tab-count">{ item.count }</span>
											) }
											{ item.badge && (
												<span className="ml-nav-badge-soon">{ item.badge }</span>
											) }
										</button>
									</li>
								);
							} ) }
						</ul>
					</nav>
				</div>
			</header>

			<main className="ml-content-container" id="migrate-lift-main-content">
				{ children }
			</main>
		</div>
	);
}
