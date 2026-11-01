/**
 * Skeleton Component
 * Loading placeholders for text, cards, metrics, and lists.
 */

export default function Skeleton( {
	variant = 'text', // 'text' | 'circle' | 'rect' | 'card'
	width,
	height,
	count = 1,
	className = '',
} ) {
	const style = {};
	if ( width ) style.width = width;
	if ( height ) style.height = height;

	const elements = [];
	for ( let i = 0; i < count; i++ ) {
		elements.push(
			<div
				key={ i }
				className={ `ml-skeleton ${ variant } ${ className }`.trim() }
				style={ style }
				aria-hidden="true"
			/>
		);
	}

	return count === 1 ? elements[ 0 ] : <>{ elements }</>;
}

