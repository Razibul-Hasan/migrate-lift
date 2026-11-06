const cfg = ( typeof window !== 'undefined' && window.migrateLift ) || {};

export class RetryError extends Error {}

export const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/**
 * Format relative time string from timestamp.
 */
export function formatRelativeTime( timestamp ) {
	if ( ! timestamp ) return '—';
	const num = typeof timestamp === 'number' ? timestamp : parseInt( timestamp, 10 );
	if ( isNaN( num ) || num <= 0 ) return '—';

	const now = Math.floor( Date.now() / 1000 );
	const diff = now - num;

	if ( diff < 60 ) return 'Just now';
	if ( diff < 3600 ) {
		const mins = Math.floor( diff / 60 );
		return `${ mins } ${ mins === 1 ? 'min' : 'mins' } ago`;
	}
	if ( diff < 86400 ) {
		const hours = Math.floor( diff / 3600 );
		return `${ hours } ${ hours === 1 ? 'hr' : 'hrs' } ago`;
	}
	if ( diff < 604800 ) {
		const days = Math.floor( diff / 86400 );
		return `${ days } ${ days === 1 ? 'day' : 'days' } ago`;
	}

	return new Date( num * 1000 ).toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} );
}

/**
 * Format bytes into human readable format.
 */
export function formatBytes( bytes ) {
	if ( ! bytes || bytes <= 0 ) return '0 B';
	const units = [ 'B', 'KB', 'MB', 'GB' ];
	let i = 0;
	let b = bytes;
	while ( b >= 1024 && i < units.length - 1 ) {
		b /= 1024;
		i++;
	}
	return b.toFixed( i ? 1 : 0 ) + ' ' + units[ i ];
}

/**
 * Make an AJAX request to admin-ajax.php with automatic nonce and FormData.
 */
export function post( action, fields = {}, onUpload = null ) {
	const body = new FormData();
	body.append( 'action', action );
	body.append( '_ajax_nonce', cfg.nonce );

	Object.keys( fields ).forEach( ( key ) => {
		const value = fields[ key ];
		if ( value instanceof Blob ) {
			body.append( key, value, 'chunk' );
		} else if ( value !== undefined && value !== null ) {
			body.append( key, value );
		}
	} );

	return new Promise( ( resolve, reject ) => {
		const xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.ajaxUrl );
		xhr.withCredentials = true;
		xhr.timeout = 180000;

		if ( onUpload && xhr.upload ) {
			xhr.upload.onprogress = ( e ) => {
				if ( e.lengthComputable ) {
					onUpload( e.loaded );
				}
			};
		}

		xhr.onerror = () => reject( new RetryError( 'Connection lost' ) );
		xhr.ontimeout = () => reject( new RetryError( 'The server did not respond within 3 minutes' ) );
		xhr.onload = () => {
			const text = xhr.responseText;
			let json;
			try {
				json = JSON.parse( text );
			} catch ( err ) {
				const status = xhr.status;
				const clean = text ? text.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 200 ) : '';
				const msg = 'Server returned HTTP ' + status + ( clean ? ': ' + clean : '' );
				reject( status >= 500 || status === 408 || status === 429 || status === 0 ? new RetryError( msg ) : new Error( msg ) );
				return;
			}

			if ( ! json.success ) {
				reject( new Error( ( json.data && json.data.message ) || 'Request failed.' ) );
				return;
			}
			resolve( json.data );
		};

		xhr.send( body );
	} );
}

/**
 * Executes a function with automatic retries on RetryError.
 */
export async function withRetry( fn, onStatus = null, maxAttempts = 5 ) {
	for ( let attempt = 1; ; attempt++ ) {
		try {
			return await fn();
		} catch ( e ) {
			if ( ! ( e instanceof RetryError ) || attempt >= maxAttempts ) {
				throw e;
			}
			if ( onStatus ) {
				onStatus( `${ e.message }. Retrying (${ attempt }/${ maxAttempts })…` );
			}
			await sleep( 2000 * attempt );
		}
	}
}

/**
 * Runs a multi-step job until the server returns done = true.
 */
export async function runJob( action, initialFields = {}, onProgress = null ) {
	let data = { step: 'start', ...initialFields };

	for ( ;; ) {
		const result = await withRetry(
			() => post( action, data ),
			( msg ) => onProgress && onProgress( { status: msg } )
		);

		if ( onProgress ) {
			onProgress( {
				progress: result.progress ?? 0,
				message: result.message ?? '',
				done: !! result.done,
			} );
		}

		if ( result.done ) {
			return result;
		}

		data = { step: 'continue', token: initialFields.token || '' };
	}
}

/**
 * Drives a background job to completion via REST API.
 *
 * @param {string} kind Job kind (e.g. 'backup', 'test_job').
 * @param {Object} params Parameters.
 * @param {Function} onProgress Callback receiving progress updates.
 * @return {Promise<Object>} Completed job result.
 */
export async function runRestJob( kind, params = {}, onProgress = null, existingJobId = null ) {
	const restBase = cfg.restUrl || '/wp-json/migrate-lift/v1/';
	const headers = {
		'Content-Type': 'application/json',
	};
	if ( cfg.restNonce ) {
		headers[ 'X-WP-Nonce' ] = cfg.restNonce;
	}

	let jobId = existingJobId;
	let job = null;

	// 1. Create the job if not attaching to existing one.
	if ( ! jobId ) {
		const createRes = await fetch( `${ restBase }jobs`, {
			method: 'POST',
			headers,
			credentials: 'same-origin',
			body: JSON.stringify( { kind, params } ),
		} );

		if ( ! createRes.ok ) {
			const errJson = await createRes.json().catch( () => null );
			const msg = ( errJson && ( errJson.message || errJson.data?.message ) ) || `HTTP ${ createRes.status }: Failed to create job.`;
			const err = new Error( msg );
			if ( errJson?.data?.job ) {
				err.activeJob = errJson.data.job;
				err.activeJobId = errJson.data.job.id;
			}
			throw err;
		}

		const createData = await createRes.json();
		job = createData.data || createData;
		jobId = job.id;
	}

	if ( onProgress && job ) {
		onProgress( {
			job,
			progress: job.percentage || 0,
			phase: job.phase || 'queued',
			message: job.diagnostic_detail || 'Job queued…',
			done: false,
		} );
	}

	// 2. Step the job until done.
	for ( ;; ) {
		const stepRes = await fetch( `${ restBase }jobs/${ jobId }/step`, {
			method: 'POST',
			headers,
			credentials: 'same-origin',
			body: JSON.stringify( {} ),
		} );

		if ( ! stepRes.ok ) {
			const errJson = await stepRes.json().catch( () => null );
			throw new Error( ( errJson && ( errJson.message || errJson.data?.message ) ) || `HTTP ${ stepRes.status }: Step execution failed.` );
		}

		const stepData = await stepRes.json();
		const result = stepData.data || stepData;
		const updatedJob = result.job || {};

		if ( onProgress ) {
			onProgress( {
				job: updatedJob,
				progress: result.progress ?? updatedJob.percentage ?? 0,
				phase: updatedJob.phase || '',
				message: result.message || updatedJob.diagnostic_detail || '',
				done: !! result.done,
			} );
		}

		if ( result.done ) {
			return {
				...result,
				job: updatedJob,
				archive: updatedJob.output?.archive || result.archive,
				size: updatedJob.output?.size || result.size,
				download: updatedJob.output?.download_url || result.download,
			};
		}

		await sleep( 300 );
	}
}

/**
 * Uploads a file in chunks with resume capability, cancellation, pause, and speed tracking.
 */
export async function uploadChunked( file, onProgress = null, controller = null ) {
	const status = await withRetry(
		() => post( 'migrate_lift_upload_status', { name: file.name } ),
		( msg ) => onProgress && onProgress( { status: msg } )
	);

	let offset = status.size > 0 && status.size < file.size ? status.size : 0;
	const started = Date.now();
	const startOffset = offset;
	const chunkLimit = cfg.chunk || ( 2 * 1024 * 1024 );
	let lastReceipt = null;

	const report = ( done ) => {
		const secs = ( Date.now() - started ) / 1000;
		const speed = secs > 1 ? ( done - startOffset ) / secs : 0;
		const pct = Math.min( 100, Math.floor( ( done / file.size ) * 100 ) );
		const speedText = speed ? ` (${ formatBytes( speed ) }/s)` : '';
		if ( onProgress ) {
			onProgress( {
				progress: pct,
				uploadedBytes: done,
				totalBytes: file.size,
				speed,
				receipt: lastReceipt,
				message: `Uploading… ${ formatBytes( done ) } of ${ formatBytes( file.size ) }${ speedText }`,
			} );
		}
	};

	report( offset );

	for ( ;; ) {
		// Check for cancellation or pause via controller.
		if ( controller ) {
			if ( controller.cancelled ) {
				await cancelChunkUpload( file.name ).catch( () => null );
				throw new Error( 'Upload cancelled by user.' );
			}
			while ( controller.paused ) {
				if ( controller.cancelled ) {
					await cancelChunkUpload( file.name ).catch( () => null );
					throw new Error( 'Upload cancelled by user.' );
				}
				if ( onProgress ) {
					onProgress( {
						progress: Math.min( 100, Math.floor( ( offset / file.size ) * 100 ) ),
						status: 'Upload paused.',
						paused: true,
					} );
				}
				await sleep( 400 );
			}
		}

		const blob = file.slice( offset, offset + chunkLimit );
		const sent = offset;

		const res = await withRetry(
			() => post(
				'migrate_lift_upload',
				{ name: file.name, offset: sent, total: file.size, chunk: blob },
				( loaded ) => report( sent + Math.min( loaded, blob.size ) )
			),
			( msg ) => onProgress && onProgress( { status: msg } )
		);

		offset += blob.size;
		lastReceipt = res.receipt || `chunk_${ offset }`;
		report( offset );

		if ( res && res.file ) {
			return res.file;
		}
	}
}

/**
 * Resolves a REST API endpoint URL safely.
 *
 * @param {string} endpoint Route relative to namespace.
 * @return {string} Full REST URL.
 */
export function getRestUrl( endpoint = '' ) {
	let base = cfg.restUrl || '/wp-json/migrate-lift/v1/';
	if ( ! base.endsWith( '/' ) ) {
		base += '/';
	}
	let clean = endpoint.replace( /^\/?(migrate-lift\/v1\/)?/, '' );
	// With plain permalinks the base already has a query string (?rest_route=...).
	if ( base.includes( '?' ) ) {
		clean = clean.replace( '?', '&' );
	}
	return base + clean;
}

/**
 * Retrieves compression & exclusions settings and host capabilities.
 */
export async function getCompressionSettings() {
	const res = await fetch( getRestUrl( 'settings/compression' ), {
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed fetching compression settings.' );
	}
	return json.data;
}

/**
 * Saves compression & exclusions settings.
 */
export async function saveCompressionSettings( settings ) {
	const res = await fetch( getRestUrl( 'settings/compression' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( settings ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed saving compression settings.' );
	}
	return json.data;
}

/**
 * Fetches all archives from REST endpoint.
 */
export async function fetchArchives() {
	const res = await fetch( getRestUrl( 'archives' ), {
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to fetch archives.' );
	}
	return json.data || [];
}

/**
 * Fetches detailed metadata and manifest breakdown for a single archive.
 *
 * @param {string} fileName Archive filename.
 */
export async function fetchArchiveDetails( fileName ) {
	const res = await fetch( getRestUrl( `archives/${ encodeURIComponent( fileName ) }` ), {
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to fetch archive details.' );
	}
	return json.data;
}

/**
 * Verifies an archive's integrity and manifest structure.
 *
 * @param {string} fileName Archive filename.
 */
export async function verifyBackup( fileName ) {
	const res = await fetch( getRestUrl( 'backup/verify' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { file: fileName } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Verification request failed.' );
	}
	return json.data;
}

/**
 * Safely deletes an archive with dependency checks.
 *
 * @param {string} fileName Archive filename.
 */
export async function deleteArchive( fileName ) {
	const res = await fetch( getRestUrl( `archives/${ encodeURIComponent( fileName ) }` ), {
		method: 'DELETE',
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to delete archive.' );
	}
	return json.data;
}

/**
 * Checks if a backup job is currently running, queued, or paused.
 */
export async function getActiveBackupJob() {
	try {
		const res = await fetch( getRestUrl( 'jobs?kind=backup&limit=5' ), {
			headers: {
				'X-WP-Nonce': cfg.restNonce || '',
			},
			credentials: 'same-origin',
		} );
		const json = await res.json();
		if ( res.ok && json.success && Array.isArray( json.data?.jobs ) ) {
			const active = json.data.jobs.find( ( j ) =>
				[ 'queued', 'running', 'paused' ].includes( j.state )
			);
			return active || null;
		}
	} catch ( _err ) {
		// Ignore
	}
	return null;
}

/**
 * Pauses an active job.
 */
export async function pauseJob( jobId ) {
	const res = await fetch( getRestUrl( `jobs/${ encodeURIComponent( jobId ) }/pause` ), {
		method: 'POST',
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Could not pause job.' );
	}
	return json.data;
}

/**
 * Resumes a paused job.
 */
export async function resumeJob( jobId ) {
	const res = await fetch( getRestUrl( `jobs/${ encodeURIComponent( jobId ) }/resume` ), {
		method: 'POST',
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Could not resume job.' );
	}
	return json.data;
}

/**
 * Cancels an active or paused job.
 */
export async function cancelJob( jobId ) {
	const res = await fetch( getRestUrl( `jobs/${ encodeURIComponent( jobId ) }/cancel` ), {
		method: 'POST',
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Could not cancel job.' );
	}
	return json.data;
}

/**
 * Retrieves automated backup schedule configuration, status, and system cron instructions.
 */
export async function getScheduleSettings() {
	const res = await fetch( getRestUrl( 'settings/schedule' ), {
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed fetching schedule settings.' );
	}
	return json.data;
}

/**
 * Saves automated backup schedule configuration.
 *
 * @param {Object} settings Schedule configuration.
 */
export async function saveScheduleSettings( settings ) {
	const res = await fetch( getRestUrl( 'settings/schedule' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( settings ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed saving schedule settings.' );
	}
	return json.data;
}

/**
 * Triggers an immediate execution of the scheduled backup.
 */
export async function runScheduleNow() {
	const res = await fetch( getRestUrl( 'schedule/run-now' ), {
		method: 'POST',
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to trigger scheduled backup.' );
	}
	return json.data;
}

/**
 * Fetches staging directory info and discovered SFTP archives.
 */
export async function fetchImportStaging() {
	const res = await fetch( getRestUrl( 'import/staging' ), {
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to fetch staging directory info.' );
	}
	return json.data;
}

/**
 * Rescans the staging directory for newly dropped SFTP / cPanel files.
 */
export async function rescanImportStaging() {
	const res = await fetch( getRestUrl( 'import/rescan' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to scan staging directory.' );
	}
	return json.data;
}

/**
 * Validates and imports a staged archive into the main backup library.
 *
 * @param {string} fileName Archive filename in staging directory.
 */
export async function verifyStagedArchive( fileName ) {
	const res = await fetch( getRestUrl( 'import/verify-staged' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { filename: fileName } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to verify and import staged archive.' );
	}
	return json.data;
}

/**
 * Cancels an in-progress chunked upload.
 *
 * @param {string} fileName Archive filename being uploaded.
 */
export async function cancelChunkUpload( fileName ) {
	const res = await fetch( getRestUrl( 'import/upload-cancel' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { filename: fileName } ),
	} );
	const json = await res.json();
	return json.success;
}

/**
 * Refreshes the web exposure check for the storage directory.
 */
export async function refreshExposureCheck() {
	const res = await fetch( getRestUrl( 'import/exposure-check' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to verify storage exposure.' );
	}
	return json.data;
}

/**
 * Runs destination preflight checks on an archive.
 *
 * @param {string} fileName Archive filename.
 */
export async function runRestorePreflight( fileName ) {
	const res = await fetch( getRestUrl( 'restore/preflight' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { file: fileName } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Preflight inspection failed.' );
	}
	return json.data;
}

/**
 * Generates an accurate dry-run change preview report.
 *
 * @param {string} fileName Archive filename.
 */
export async function fetchRestoreDryRun( fileName ) {
	const res = await fetch( getRestUrl( 'restore/dry-run' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { file: fileName } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to generate dry run report.' );
	}
	return json.data;
}

/**
 * Creates and verifies a destination safety recovery checkpoint snapshot.
 */
export async function createRecoveryCheckpoint( onProgress = null ) {
	return runCheckpoint( 'restore/checkpoint', {}, onProgress );
}

/**
 * Creates a full recovery checkpoint on the paired destination site, one step at a time.
 *
 * @param {string}   pairId     Destination pair ID.
 * @param {Function} onProgress Called with each step's progress and message.
 */
export async function createRemoteRecoveryCheckpoint( pairId, onProgress = null ) {
	return runCheckpoint( 'pair/remote-checkpoint', { pair_id: pairId }, onProgress );
}

/**
 * Drives a checkpoint job until it finishes, so hosts with short time limits work.
 *
 * @param {string}   route      REST route.
 * @param {Object}   body       Extra request fields.
 * @param {Function} onProgress Progress callback.
 */
async function runCheckpoint( route, body, onProgress ) {
	let jobId = '';
	for ( ;; ) {
		const res = await fetch( getRestUrl( route ), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce || '',
			},
			credentials: 'same-origin',
			body: JSON.stringify( { ...body, job_id: jobId } ),
		} );
		const json = await res.json();
		if ( ! res.ok || ! json.success ) {
			throw new Error( json.message || 'Failed to create recovery checkpoint.' );
		}
		const step = json.data;
		jobId = step.job_id;
		if ( onProgress ) {
			onProgress( step );
		}
		if ( step.done ) {
			return step.checkpoint;
		}
		await sleep( 300 );
	}
}

/**
 * Fetches current registered recovery checkpoint status.
 */
export async function fetchRecoveryStatus() {
	const res = await fetch( getRestUrl( 'restore/recovery-status' ), {
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to fetch recovery status.' );
	}
	return json.data;
}

/**
 * Generates an accurate diff preview between archive and destination files/tables.
 *
 * @param {string} fileName Archive filename.
 * @param {string} fileMode Mode (add_missing, overwrite_matching, sync_managed).
 * @param {string} dbMode Mode (replace_mapped, replace_selected, keep_destination).
 * @param {Array} selectedTables Selected table groups for replace_selected.
 */
export async function fetchRestoreDiffPreview( fileName, fileMode = 'overwrite_matching', dbMode = 'replace_mapped', selectedTables = [] ) {
	const res = await fetch( getRestUrl( 'restore/diff-preview' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( {
			file: fileName,
			file_mode: fileMode,
			db_mode: dbMode,
			selected_tables: selectedTables,
		} ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to generate diff preview.' );
	}
	return json;
}

/**
 * Validates preflight, confirmation, and executes recovery checkpoint or live restoration.
 *
 * @param {string} fileName Archive filename.
 * @param {string} confirmHost Destination hostname.
 * @param {Object} options Execution options (action, file_mode, db_mode, selected_tables, preserve_admin_user, confirm_delete).
 */
export async function executeRestoreWizard( fileName, confirmHost, options = {} ) {
	const res = await fetch( getRestUrl( 'restore/execute' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( {
			file: fileName,
			confirm_host: confirmHost,
			action: options.action || 'preview',
			file_mode: options.file_mode || 'overwrite_matching',
			db_mode: options.db_mode || 'replace_mapped',
			selected_tables: options.selected_tables || [],
			preserve_admin_user: Boolean( options.preserve_admin_user ),
			confirm_delete: Boolean( options.confirm_delete ),
		} ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Restore execution check failed.' );
	}
	return json;
}

/**
 * Generates a short-lived single-use pairing token on destination.
 */
export async function generatePairingToken() {
	const res = await fetch( getRestUrl( 'pair/token' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok ) {
		throw new Error( json.message || 'Failed to generate pairing token.' );
	}
	return json;
}

/**
 * Initiates handshake pairing connection to destination site.
 *
 * @param {string} pairingKey Base64 encoded pairing key string.
 */
export async function connectSite( pairingKey ) {
	const res = await fetch( getRestUrl( 'pair/connect' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { pairing_key: pairingKey } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to connect to destination site.' );
	}
	return json;
}

/**
 * Fetches list of paired sites.
 */
export async function fetchPairingStatus() {
	const res = await fetch( getRestUrl( 'pair/status' ), {
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to fetch paired sites.' );
	}
	return json.paired_sites || [];
}

/**
 * Revokes an existing pairing connection.
 *
 * @param {string} pairId Unique pair identifier.
 */
export async function revokePairing( pairId ) {
	const res = await fetch( getRestUrl( 'pair/revoke' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { pair_id: pairId } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to revoke pairing.' );
	}
	return json;
}

/**
 * Verifies round-trip latency and connectivity with paired site.
 *
 * @param {string} pairId Unique pair identifier.
 */
export async function verifyPairing( pairId ) {
	const res = await fetch( getRestUrl( 'pair/verify' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { pair_id: pairId } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.connected ) {
		throw new Error( json.message || 'Connection check failed.' );
	}
	return json;
}

/**
 * Starts direct server-to-server transfer background job.
 *
 * @param {string} pairId Paired destination ID.
 * @param {string} archive Archive filename to stream.
 */
export async function startDirectTransfer( pairId, archive ) {
	const res = await fetch( getRestUrl( 'transfer/start' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { pair_id: pairId, archive } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to start direct transfer.' );
	}
	return json;
}

/**
 * Runs one step of a direct transfer job. The transfer only advances while this is called.
 *
 * @param {string} jobId Unique transfer job ID.
 */
export async function stepDirectTransfer( jobId ) {
	const res = await fetch( getRestUrl( `jobs/${ encodeURIComponent( jobId ) }/step` ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( {} ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Transfer step failed.' );
	}
	return json.data;
}

/**
 * Polls status of direct transfer background job.
 *
 * @param {string} jobId Unique transfer job ID.
 */
export async function fetchTransferStatus( jobId ) {
	const res = await fetch( getRestUrl( `transfer/status?job_id=${ encodeURIComponent( jobId ) }` ), {
		headers: {
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to fetch transfer status.' );
	}
	return json.job;
}

/**
 * Cancels active direct transfer job.
 *
 * @param {string} jobId Unique transfer job ID.
 */
export async function cancelTransfer( jobId ) {
	const res = await fetch( getRestUrl( 'transfer/cancel' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { job_id: jobId } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to cancel transfer.' );
	}
	return json;
}

/**
 * Requests remote preflight checks from paired destination.
 *
 * @param {string} pairId Destination pair ID.
 * @param {string} fileName Archive filename.
 */
export async function fetchRemotePreflight( pairId, fileName ) {
	const res = await fetch( getRestUrl( 'pair/remote-preflight' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( { pair_id: pairId, file: fileName } ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Remote preflight check failed.' );
	}
	return json.preflight || json;
}

/**
 * Requests remote diff preview from paired destination.
 *
 * @param {string} pairId Destination pair ID.
 * @param {string} fileName Archive filename.
 * @param {string} fileMode Mode (overwrite_matching, keep_missing, clean_sync).
 * @param {string} dbMode Mode (replace_mapped, keep_destination).
 * @param {Array} selectedTables Selected tables.
 */
export async function fetchRemoteDiff( pairId, fileName, fileMode = 'overwrite_matching', dbMode = 'replace_mapped', selectedTables = [] ) {
	const res = await fetch( getRestUrl( 'pair/remote-diff' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( {
			pair_id: pairId,
			file: fileName,
			file_mode: fileMode,
			db_mode: dbMode,
			selected_tables: selectedTables,
		} ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Failed to generate remote diff preview.' );
	}
	return json;
}

/**
 * Executes remote restore apply on paired destination site.
 *
 * @param {string} pairId Destination pair ID.
 * @param {string} fileName Archive filename.
 * @param {string} confirmHost Destination hostname for confirmation.
 * @param {Object} options Options (file_mode, db_mode, selected_tables, preserve_admin_user, confirm_delete).
 */
export async function executeRemoteApply( pairId, fileName, confirmHost, options = {} ) {
	const res = await fetch( getRestUrl( 'pair/remote-apply' ), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.restNonce || '',
		},
		credentials: 'same-origin',
		body: JSON.stringify( {
			pair_id: pairId,
			file: fileName,
			confirm_host: confirmHost,
			file_mode: options.file_mode || 'overwrite_matching',
			db_mode: options.db_mode || 'replace_mapped',
			selected_tables: options.selected_tables || [],
			preserve_admin_user: Boolean( options.preserve_admin_user ),
			confirm_delete: Boolean( options.confirm_delete ),
		} ),
	} );
	const json = await res.json();
	if ( ! res.ok || ! json.success ) {
		throw new Error( json.message || 'Remote migration restore failed.' );
	}
	return json;
}

