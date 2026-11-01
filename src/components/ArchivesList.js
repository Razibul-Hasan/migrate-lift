import { useState } from '@wordpress/element';
import {
	Button,
	ButtonGroup,
	Card,
	CardBody,
	CardHeader,
	CardDivider,
	Flex,
	FlexItem,
	FlexBlock,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { formatBytes, post } from '../utils/api';

export default function ArchivesList( { archives, onRefresh, onImportArchive, isBusy, setBusy } ) {
	const [ deletingFile, setDeletingFile ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ confirmingDelete, setConfirmingDelete ] = useState( null );

	const handleDelete = async ( fileName ) => {
		setError( null );
		setDeletingFile( fileName );
		setBusy( true );

		try {
			const updatedList = await post( 'migrate_lift_delete', { file: fileName } );
			setConfirmingDelete( null );
			if ( onRefresh ) {
				onRefresh( updatedList );
			}
		} catch ( err ) {
			setError( err.message || __( 'Could not delete the archive.', 'migrate-lift' ) );
		} finally {
			setDeletingFile( null );
			setBusy( false );
		}
	};

	return (
		<Card elevation={ 0 } className="migrate-lift-card migrate-lift-archives-card" style={ { width: '100%', borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', margin: '0 0 24px' } }>
			<CardHeader className="migrate-lift-card-header">
				<Flex justify="space-between" align="center">
					<FlexItem>
						<h2 style={ { margin: 0, fontSize: '18px', fontWeight: 600 } }>
							{ __( 'Archives on This Server', 'migrate-lift' ) }
						</h2>
					</FlexItem>
					<FlexItem>
						<Button
							variant="tertiary"
							size="small"
							disabled={ isBusy }
							onClick={ () => onRefresh && onRefresh() }
						>
							{ __( 'Refresh', 'migrate-lift' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody className="migrate-lift-card-body">
				{ error && (
					<Notice status="error" isDismissible onDismiss={ () => setError( null ) } style={ { marginBottom: '16px' } }>
						{ error }
					</Notice>
				) }

				{ ( ! archives || archives.length === 0 ) ? (
					<p style={ { textAlign: 'center', color: '#646970', padding: '24px 0', margin: 0 } }>
						{ __( 'No archives currently stored on this server.', 'migrate-lift' ) }
					</p>
				) : (
					<Flex direction="column" gap={ 0 }>
						{ archives.map( ( archive, index ) => (
							<div key={ archive.name } className="migrate-lift-archive-row">
								{ index > 0 && <CardDivider /> }
								<Flex
									justify="space-between"
									align="center"
									style={ { padding: '12px 0' } }
									wrap
									gap={ 2 }
								>
									<FlexBlock style={ { minWidth: '220px' } }>
										<div style={ { fontWeight: 600, fontFamily: 'monospace', fontSize: '13px' } }>
											{ archive.name }
										</div>
										<div style={ { color: '#646970', fontSize: '12px', marginTop: '2px' } }>
											{ formatBytes( archive.size ) } • { archive.date }
										</div>
									</FlexBlock>

									<FlexItem>
										<ButtonGroup>
											<Button
												variant="secondary"
												size="small"
												href={ archive.download }
												download
											>
												{ __( 'Download', 'migrate-lift' ) }
											</Button>

											<Button
												variant="secondary"
												size="small"
												disabled={ isBusy }
												onClick={ () => onImportArchive && onImportArchive( archive.name ) }
											>
												{ __( 'Import', 'migrate-lift' ) }
											</Button>

											{ confirmingDelete === archive.name ? (
												<>
													<Button
														variant="primary"
														isDestructive
														size="small"
														disabled={ isBusy }
														isBusy={ deletingFile === archive.name }
														onClick={ () => handleDelete( archive.name ) }
													>
														{ __( 'Confirm Delete', 'migrate-lift' ) }
													</Button>
													<Button
														variant="tertiary"
														size="small"
														disabled={ isBusy }
														onClick={ () => setConfirmingDelete( null ) }
													>
														{ __( 'Cancel', 'migrate-lift' ) }
													</Button>
												</>
											) : (
												<Button
													variant="tertiary"
													isDestructive
													size="small"
													disabled={ isBusy }
													onClick={ () => setConfirmingDelete( archive.name ) }
												>
													{ __( 'Delete', 'migrate-lift' ) }
												</Button>
											) }
										</ButtonGroup>
									</FlexItem>
								</Flex>
							</div>
						) ) }
					</Flex>
				) }
			</CardBody>
		</Card>
	);
}
