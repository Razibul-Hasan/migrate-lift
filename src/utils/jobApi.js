/**
 * REST API client for MigrateLift Job Engine and Activity Logging (Part 04).
 */

const getCfg = () => ( typeof window !== 'undefined' && window.migrateLift ) || {};

function getRestBase() {
	const cfg = getCfg();
	return cfg.restUrl || '/wp-json/migrate-lift/v1/';
}

function getHeaders() {
	const cfg = getCfg();
	const headers = {
		'Content-Type': 'application/json',
	};
	if ( cfg.restNonce ) {
		headers[ 'X-WP-Nonce' ] = cfg.restNonce;
	}
	return headers;
}

async function request( path, method = 'GET', body = null ) {
	const base = getRestBase().replace( /\/?$/, '/' );
	const url = `${ base }${ path.replace( /^\//, '' ) }`;

	const options = {
		method,
		headers: getHeaders(),
		credentials: 'same-origin',
	};

	if ( body && ( method === 'POST' || method === 'PUT' ) ) {
		options.body = JSON.stringify( body );
	}

	const response = await fetch( url, options );
	let json = null;
	try {
		json = await response.json();
	} catch ( _err ) {
		// Ignore json parse error
	}

	if ( ! response.ok ) {
		const message = ( json && ( json.message || json.code ) ) || `HTTP ${ response.status } ${ response.statusText }`;
		const error = new Error( message );
		error.status = response.status;
		error.data = json;
		throw error;
	}

	return json && json.data !== undefined ? json.data : json;
}

/**
 * Fetch paginated list of jobs.
 */
export async function getJobs( filters = {} ) {
	const params = new URLSearchParams();
	if ( filters.state ) params.set( 'state', filters.state );
	if ( filters.kind ) params.set( 'kind', filters.kind );
	if ( filters.limit ) params.set( 'limit', filters.limit );
	if ( filters.offset ) params.set( 'offset', filters.offset );

	const query = params.toString() ? `?${ params.toString() }` : '';
	return request( `jobs${ query }`, 'GET' );
}

/**
 * Fetch a single job by ID.
 */
export async function getJob( id ) {
	return request( `jobs/${ encodeURIComponent( id ) }`, 'GET' );
}

/**
 * Create a new job.
 */
export async function createJob( kind, params = {}, idempotencyKey = '' ) {
	return request( 'jobs', 'POST', {
		kind,
		params,
		idempotency_key: idempotencyKey,
	} );
}

/**
 * Step a job forward by one batch.
 */
export async function stepJob( id, workerId = '' ) {
	return request( `jobs/${ encodeURIComponent( id ) }/step`, 'POST', {
		worker_id: workerId,
	} );
}

/**
 * Pause a job.
 */
export async function pauseJob( id ) {
	return request( `jobs/${ encodeURIComponent( id ) }/pause`, 'POST' );
}

/**
 * Resume a paused job.
 */
export async function resumeJob( id ) {
	return request( `jobs/${ encodeURIComponent( id ) }/resume`, 'POST' );
}

/**
 * Cancel a job.
 */
export async function cancelJob( id ) {
	return request( `jobs/${ encodeURIComponent( id ) }/cancel`, 'POST' );
}

/**
 * Fetch paginated activity log events.
 */
export async function getActivity( filters = {} ) {
	const params = new URLSearchParams();
	if ( filters.search ) params.set( 'search', filters.search );
	if ( filters.level && filters.level !== 'all' ) params.set( 'level', filters.level );
	if ( filters.job_id ) params.set( 'job_id', filters.job_id );
	if ( filters.limit ) params.set( 'limit', filters.limit );
	if ( filters.offset ) params.set( 'offset', filters.offset );

	const query = params.toString() ? `?${ params.toString() }` : '';
	return request( `activity${ query }`, 'GET' );
}

/**
 * Clear activity log entries.
 */
export async function clearActivity() {
	return request( 'activity/clear', 'POST' );
}

