/**
 * MigrateView Component (Parts 13, 14, 15)
 *
 * Implements the guided server-to-server migration screen featuring
 * cryptographic site pairing, resumable direct HTTPS transfer, and the 7-step migration wizard.
 */

import { __ } from '@wordpress/i18n';
import PageHeader from '../components/common/PageHeader';
import MigrationWizard from '../components/migrate/MigrationWizard';

export default function MigrateView() {
	return (
		<div className="ml-migrate-view">
			<PageHeader
				overline={ __( 'Server-to-Server Protocol', 'migrate-lift' ) }
				title={ __( 'Direct Site Migration', 'migrate-lift' ) }
				description={ __(
					'Transfer this WordPress site directly to a destination server over encrypted HTTPS without manual downloads or cloud intermediary accounts.',
					'migrate-lift'
				) }
			/>

			<div style={ { marginTop: '24px' } }>
				<MigrationWizard />
			</div>
		</div>
	);
}
