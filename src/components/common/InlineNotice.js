/**
 * InlineNotice Component
 * Scoped callout alert for warnings, errors, tips, and operational feedback.
 */
import { IconInfo, IconAlertTriangle, IconCheck, IconClose } from './Icons';

export default function InlineNotice( {
	status = 'info', // 'info' | 'success' | 'warning' | 'error'
	title,
	children,
	isDismissible = false,
	onDismiss,
	className = '',
} ) {
	const renderIcon = () => {
		switch ( status ) {
			case 'success':
				return <IconCheck />;
			case 'warning':
			case 'error':
				return <IconAlertTriangle />;
			case 'info':
			default:
				return <IconInfo />;
		}
	};

	return (
		<div className={ `ml-inline-notice ${ status } ${ className }`.trim() } role="alert">
			<div className="ml-inline-notice-icon">{ renderIcon() }</div>
			<div className="ml-inline-notice-content">
				{ title && <div className="ml-inline-notice-title">{ title }</div> }
				<div>{ children }</div>
			</div>
			{ isDismissible && (
				<button
					type="button"
					className="ml-inline-notice-dismiss"
					onClick={ onDismiss }
					aria-label="Dismiss notice"
				>
					<IconClose style={ { width: 14, height: 14 } } />
				</button>
			) }
		</div>
	);
}

