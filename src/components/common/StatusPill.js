/**
 * StatusPill Component
 * Clean badge indicating health, verification, or operational state.
 */

export default function StatusPill( {
	status = 'neutral', // 'success' | 'warning' | 'error' | 'info' | 'neutral'
	label,
	icon,
	showDot = true,
	className = '',
} ) {
	return (
		<span className={ `ml-status-pill ${ status } ${ className }`.trim() }>
			{ showDot && ! icon && <span className="ml-status-pill-dot" aria-hidden="true" /> }
			{ icon && <span className="ml-status-pill-icon">{ icon }</span> }
			<span>{ label }</span>
		</span>
	);
}

