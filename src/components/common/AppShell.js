/**
 * AppShell Component
 * Top header with brand and site link, a single row of underlined tabs, and the page content below.
 * Horizontal navigation avoids stacking a second sidebar next to the WordPress admin menu.
 */
import { __ } from '@wordpress/i18n';
import { Button, TabPanel } from '@wordpress/components';
import { IconMigrate } from './Icons';

const cfg = window.migrateLift || {};

const EXPERT_URL =
	'https://bestwebexpert.com/?utm_source=migrate-lift&utm_medium=plugin&utm_campaign=dashboard&utm_content=hire-an-expert';

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

	const expertButton = (
		<Button
			className="ml-expert-chip"
			href={ EXPERT_URL }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ __( 'Hire an Expert', 'migrate-lift' ) }
			<span className="ml-expert-chip-arrow" aria-hidden="true">
				↗
			</span>
		</Button>
	);

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

					<div className="ml-header-bottom">
						{ /* TabPanel is used only for the tab strip; page content is rendered by the router below. */ }
						<TabPanel
							key={ activeRoute }
							className="ml-tabs"
							initialTabName={ activeRoute }
							onSelect={ handleNavClick }
							tabs={ navItems.map( ( item ) => ( {
								name: item.id,
								title: (
									<>
										{ item.label }
										{ item.count !== undefined && (
											<span className="ml-tab-count">{ item.count }</span>
										) }
									</>
								),
							} ) ) }
						>
							{ () => null }
						</TabPanel>
						{ expertButton }
					</div>
				</div>
			</header>

			<main className="ml-content-container" id="migrate-lift-main-content">
				{ children }
			</main>
		</div>
	);
}
