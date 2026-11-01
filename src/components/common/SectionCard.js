/**
 * SectionCard Component
 * Consistent container card with header, subtitle, actions, content, and footer.
 */

export default function SectionCard( {
	title,
	subtitle,
	headerActions,
	children,
	footer,
	noPadding = false,
	className = '',
} ) {
	return (
		<div className={ `ml-section-card ${ className }`.trim() }>
			{ ( title || subtitle || headerActions ) && (
				<div className="ml-section-card-header">
					<div className="ml-section-card-header-info">
						{ title && <h2 className="ml-section-card-title">{ title }</h2> }
						{ subtitle && <p className="ml-section-card-subtitle">{ subtitle }</p> }
					</div>
					{ headerActions && (
						<div className="ml-section-card-actions">{ headerActions }</div>
					) }
				</div>
			) }
			<div className={ `ml-section-card-body ${ noPadding ? 'no-padding' : '' }`.trim() }>
				{ children }
			</div>
			{ footer && <div className="ml-section-card-footer">{ footer }</div> }
		</div>
	);
}

