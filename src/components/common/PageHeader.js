/**
 * PageHeader Component
 * Standard page title row with optional overline, description, and action buttons.
 */

export default function PageHeader( {
	title,
	overline,
	description,
	actions,
	className = '',
} ) {
	return (
		<div className={ `ml-page-header ${ className }`.trim() }>
			<div className="ml-page-header-text">
				{ overline && <div className="ml-page-header-overline">{ overline }</div> }
				<h1 className="ml-page-header-title">{ title }</h1>
				{ description && <p className="ml-page-header-desc">{ description }</p> }
			</div>
			{ actions && <div className="ml-page-header-actions">{ actions }</div> }
		</div>
	);
}

