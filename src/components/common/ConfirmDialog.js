/**
 * ConfirmDialog Component
 * Accessible confirmation modal for sensitive or destructive operations.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { IconAlertTriangle } from './Icons';

export default function ConfirmDialog( {
	isOpen,
	title,
	description,
	confirmLabel = __( 'Confirm', 'migrate-lift' ),
	cancelLabel = __( 'Cancel', 'migrate-lift' ),
	isDestructive = false,
	isBusy = false,
	onConfirm,
	onCancel,
} ) {
	const confirmBtnRef = useRef( null );

	useEffect( () => {
		if ( ! isOpen ) return;

		const handleKeyDown = ( e ) => {
			if ( e.key === 'Escape' && ! isBusy ) {
				onCancel();
			}
		};

		window.addEventListener( 'keydown', handleKeyDown );
		// Auto-focus confirm or cancel button
		if ( confirmBtnRef.current ) {
			confirmBtnRef.current.focus();
		}

		return () => {
			window.removeEventListener( 'keydown', handleKeyDown );
		};
	}, [ isOpen, isBusy, onCancel ] );

	if ( ! isOpen ) {
		return null;
	}

	return (
		<div
			className="ml-modal-backdrop"
			onClick={ ( e ) => {
				if ( e.target === e.currentTarget && ! isBusy ) {
					onCancel();
				}
			} }
			role="presentation"
		>
			<div
				className="ml-modal-card"
				role="dialog"
				aria-modal="true"
				aria-labelledby="ml-confirm-title"
				aria-describedby="ml-confirm-desc"
			>
				<div className="ml-modal-header">
					{ isDestructive && (
						<div className="ml-modal-icon-danger">
							<IconAlertTriangle />
						</div>
					) }
					<h3 id="ml-confirm-title" className="ml-modal-title">
						{ title }
					</h3>
				</div>
				<div id="ml-confirm-desc" className="ml-modal-body">
					{ description }
				</div>
				<div className="ml-modal-footer">
					<button
						type="button"
						className="ml-btn"
						onClick={ onCancel }
						disabled={ isBusy }
					>
						{ cancelLabel }
					</button>
					<button
						ref={ confirmBtnRef }
						type="button"
						className={ `ml-btn ${ isDestructive ? 'danger' : 'primary' }` }
						onClick={ onConfirm }
						disabled={ isBusy }
					>
						{ isBusy ? __( 'Processing…', 'migrate-lift' ) : confirmLabel }
					</button>
				</div>
			</div>
		</div>
	);
}

