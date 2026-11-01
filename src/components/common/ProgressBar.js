/**
 * ProgressBar Component
 * Displays smooth operation progress with percentage, labels, and indeterminate state.
 */

export default function ProgressBar( {
	value = 0,
	label,
	caption,
	isIndeterminate = false,
	status = 'primary', // 'primary' | 'success' | 'error'
	className = '',
} ) {
	const clampedValue = Math.min( 100, Math.max( 0, Math.round( value ) ) );

	return (
		<div className={ `ml-progress-bar ${ isIndeterminate ? 'is-indeterminate' : '' } ${ className }`.trim() }>
			{ ( label || ! isIndeterminate ) && (
				<div className="ml-progress-bar-header">
					<span className="ml-progress-bar-label">{ label }</span>
					{ ! isIndeterminate && (
						<span className="ml-progress-bar-percent">{ clampedValue }%</span>
					) }
				</div>
			) }
			<div
				className="ml-progress-bar-track"
				role="progressbar"
				aria-valuenow={ isIndeterminate ? undefined : clampedValue }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-label={ label }
			>
				<div
					className="ml-progress-bar-fill"
					style={ ! isIndeterminate ? { width: `${ clampedValue }%` } : undefined }
				/>
			</div>
			{ caption && <div className="ml-progress-bar-caption">{ caption }</div> }
		</div>
	);
}

