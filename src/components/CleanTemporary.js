import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Flex,
	FlexItem,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { post } from '../utils/api';

export default function CleanTemporary( { isBusy, setBusy } ) {
	const [ cleaning, setCleaning ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( null );

	const handleClean = async () => {
		setError( null );
		setCleaning( true );
		setBusy( true );
		setStatus( __( 'Scanning for temporary files…', 'migrate-lift' ) );

		try {
			for ( ;; ) {
				const r = await post( 'migrate_lift_clean' );
				setStatus( r.message || __( 'Cleaning…', 'migrate-lift' ) );
				if ( r.done ) {
					break;
				}
			}
		} catch ( err ) {
			setError( err.message || __( 'Cleaning failed.', 'migrate-lift' ) );
		} finally {
			setCleaning( false );
			setBusy( false );
		}
	};

	return (
		<Card elevation={ 0 } className="migrate-lift-card migrate-lift-tools-card" style={ { width: '100%', borderRadius: 'var(--ml-radius-md)', border: '1px solid var(--ml-line)', boxShadow: 'none', margin: '0 0 24px' } }>
			<CardBody className="migrate-lift-card-body">
				{ error && (
					<Notice status="error" isDismissible onDismiss={ () => setError( null ) } style={ { marginBottom: '16px' } }>
						{ error }
					</Notice>
				) }

				<Flex align="center" gap={ 3 } wrap>
					<FlexItem>
						<Button
							variant="secondary"
							disabled={ isBusy }
							isBusy={ cleaning }
							onClick={ handleClean }
						>
							{ __( 'Clean Temporary Files', 'migrate-lift' ) }
						</Button>
					</FlexItem>

					{ ( status || cleaning ) && (
						<FlexItem>
							<Flex align="center" gap={ 2 }>
								{ cleaning && <Spinner /> }
								<span style={ { fontSize: '13px', color: '#50575e' } }>
									{ status }
								</span>
							</Flex>
						</FlexItem>
					) }
				</Flex>
			</CardBody>
		</Card>
	);
}
