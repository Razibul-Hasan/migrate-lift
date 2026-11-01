/**
 * MetricCard Component
 * A single stat cell: quiet label, prominent value, and a short status line.
 * Designed to sit inside `.ml-metric-grid`, which draws the shared frame and dividers.
 */

export default function MetricCard( {
	title,
	value,
	meta,
	metaHighlight,
	tone = 'neutral', // 'success' | 'warning' | 'neutral'
	className = '',
	onClick,
} ) {
	const isClickable = typeof onClick === 'function';
	const Tag = isClickable ? 'button' : 'div';

	return (
		<Tag
			type={ isClickable ? 'button' : undefined }
			className={ `ml-metric-card ${ isClickable ? 'is-clickable' : '' } ${ className }`.trim() }
			onClick={ onClick }
		>
			<span className="ml-metric-card-title">{ title }</span>
			<span className="ml-metric-card-value">{ value }</span>
			{ ( meta || metaHighlight ) && (
				<span className="ml-metric-card-meta">
					{ metaHighlight && (
						<strong className={ `ml-metric-card-highlight ${ tone }` }>{ metaHighlight }</strong>
					) }
					{ meta && <span>{ meta }</span> }
				</span>
			) }
		</Tag>
	);
}
