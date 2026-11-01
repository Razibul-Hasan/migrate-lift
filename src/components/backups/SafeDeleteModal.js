/**
 * SafeDeleteModal Component
 * Accessible modal requiring typed confirmation for deleting backup archives.
 *
 * @package MigrateLift
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { IconAlertTriangle, IconTrash, IconClose } from '../common/Icons';
import { formatBytes } from '../../utils/api';

export default function SafeDeleteModal( {
	isOpen,
	archive,
	isBusy = false,
	onConfirm,
	onCancel,
} ) {
	const [ confirmationInput, setConfirmationInput ] = useState( '' );
	const [ error, setError ] = useState( null );
	const inputRef = useRef( null );

	useEffect( () => {
		if ( isOpen ) {
			setConfirmationInput( '' );
			setError( null );
			setTimeout( () => {
				if ( inputRef.current ) {
					inputRef.current.focus();
				}
			}, 50 );
		}
	}, [ isOpen, archive ] );

	if ( ! isOpen || ! archive ) {
		return null;
	}

	const fileName = archive.name || archive.file || '';
	const isMatched =
		confirmationInput.trim().toUpperCase() === 'DELETE' ||
		confirmationInput.trim() === fileName;

	const handleSubmit = ( e ) => {
		e.preventDefault();
		if ( ! isMatched || isBusy ) return;
		onConfirm( fileName );
	};

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
				className="ml-modal-card ml-safe-delete-card"
				role="dialog"
				aria-modal="true"
				aria-labelledby="ml-delete-modal-title"
			>
				<div className="ml-modal-header" style={ { justifyContent: 'space-between' } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 12 } }>
						<div className="ml-modal-icon-danger">
							<IconAlertTriangle />
						</div>
						<h3 id="ml-delete-modal-title" className="ml-modal-title">
							{ __( 'Permanently Delete Backup?', 'migrate-lift' ) }
						</h3>
					</div>
					<button
						type="button"
						className="ml-icon-btn"
						onClick={ onCancel }
						disabled={ isBusy }
						aria-label={ __( 'Close', 'migrate-lift' ) }
					>
						<IconClose style={ { width: 16, height: 16 } } />
					</button>
				</div>

				<form onSubmit={ handleSubmit }>
					<div className="ml-modal-body" style={ { marginBottom: 16 } }>
						<p style={ { margin: '0 0 12px', color: 'var(--ml-ink)' } }>
							{ __(
								'This action cannot be undone. The backup archive file will be permanently removed from server storage.',
								'migrate-lift'
							) }
						</p>

						<div className="ml-delete-target-box">
							<div style={ { fontWeight: 700, fontFamily: 'monospace', fontSize: 13, wordBreak: 'break-all' } }>
								{ fileName }
							</div>
							<div style={ { fontSize: 12, color: 'var(--ml-muted)', marginTop: 4 } }>
								{ formatBytes( archive.size ) } • { archive.date || __( 'Unknown date', 'migrate-lift' ) }
							</div>
						</div>

						<div style={ { marginTop: 16 } }>
							<label
								htmlFor="ml-delete-confirm-input"
								style={ { display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 6, color: 'var(--ml-ink-secondary)' } }
							>
								{ sprintf(
									__( 'To confirm, type %s in the box below:', 'migrate-lift' ),
									'DELETE'
								) }
							</label>
							<input
								id="ml-delete-confirm-input"
								ref={ inputRef }
								type="text"
								value={ confirmationInput }
								onChange={ ( e ) => setConfirmationInput( e.target.value ) }
								placeholder="DELETE"
								disabled={ isBusy }
								className="ml-input"
								style={ {
									width: '100%',
									padding: '8px 12px',
									borderRadius: 'var(--ml-radius-sm)',
									border: '1px solid var(--ml-line)',
									fontFamily: 'monospace',
									fontSize: 14,
								} }
								autoComplete="off"
							/>
						</div>
					</div>

					<div className="ml-modal-footer">
						<button
							type="button"
							className="ml-btn"
							onClick={ onCancel }
							disabled={ isBusy }
						>
							{ __( 'Cancel', 'migrate-lift' ) }
						</button>
						<button
							type="submit"
							className="ml-btn danger"
							disabled={ ! isMatched || isBusy }
						>
							<IconTrash />
							<span>{ isBusy ? __( 'Deleting…', 'migrate-lift' ) : __( 'Delete Permanently', 'migrate-lift' ) }</span>
						</button>
					</div>
				</form>
			</div>
		</div>
	);
}

