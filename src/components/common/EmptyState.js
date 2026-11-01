/**
 * EmptyState Component
 * Truthful, accessible empty state presentation for unconfigured features or zero-data screens.
 */

export default function EmptyState( {
	icon,
	title,
	description,
	primaryAction,
	secondaryAction,
	className = '',
} ) {
	return (
		<div className={ `ml-empty-state ${ className }`.trim() }>
			{ icon && <div className="ml-empty-state-icon">{ icon }</div> }
			<h3 className="ml-empty-state-title">{ title }</h3>
			{ description && <p className="ml-empty-state-desc">{ description }</p> }
			{ ( primaryAction || secondaryAction ) && (
				<div className="ml-empty-state-actions">
					{ primaryAction }
					{ secondaryAction }
				</div>
			) }
		</div>
	);
}

