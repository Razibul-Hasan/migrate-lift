import { render, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';
import './styles/app.css';

window.migrateLift = window.migrateLift || {};

// Configure WordPress apiFetch middleware with safe nonce and REST root URL.
if ( window.migrateLift.restNonce && typeof apiFetch?.createNonceMiddleware === 'function' ) {
	apiFetch.use( apiFetch.createNonceMiddleware( window.migrateLift.restNonce ) );
}
if ( window.migrateLift.restUrl && typeof apiFetch?.createRootURLMiddleware === 'function' ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( window.migrateLift.restUrl ) );
}

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'migrate-lift-admin-root' );
	if ( container ) {
		if ( createRoot ) {
			createRoot( container ).render( <App /> );
		} else if ( render ) {
			render( <App />, container );
		}
	}
} );

