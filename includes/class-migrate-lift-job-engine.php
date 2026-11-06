<?php
/**
 * Resumable Background Job Engine.
 *
 * Provides lifecycle management, state transitions, lease-based concurrency locking,
 * bounded time-budgeted execution, checkpoint persistence, and idempotency protection.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrate_Lift_Job_Engine
 */
class Migrate_Lift_Job_Engine {

	/**
	 * Storage subdirectory for job files.
	 */
	const JOBS_DIR = 'jobs';

	/**
	 * Default lease duration in seconds for worker lock.
	 */
	const LEASE_DURATION = 45;

	/**
	 * Supported job states.
	 */
	const STATE_QUEUED           = 'queued';
	const STATE_RUNNING          = 'running';
	const STATE_PAUSED           = 'paused';
	const STATE_CANCEL_REQUESTED = 'cancel-requested';
	const STATE_FAILED           = 'failed';
	const STATE_COMPLETED        = 'completed';

	/**
	 * Allowed state transitions.
	 */
	const TRANSITIONS = array(
		self::STATE_QUEUED           => array( self::STATE_RUNNING, self::STATE_CANCEL_REQUESTED, self::STATE_FAILED ),
		self::STATE_RUNNING          => array( self::STATE_RUNNING, self::STATE_PAUSED, self::STATE_CANCEL_REQUESTED, self::STATE_FAILED, self::STATE_COMPLETED ),
		self::STATE_PAUSED           => array( self::STATE_QUEUED, self::STATE_RUNNING, self::STATE_CANCEL_REQUESTED, self::STATE_FAILED ),
		self::STATE_CANCEL_REQUESTED => array( self::STATE_FAILED ),
		self::STATE_FAILED           => array( self::STATE_QUEUED ), // Allows retry/restart
		self::STATE_COMPLETED        => array(),
	);

	/**
	 * Handler registry for different job kinds.
	 *
	 * @var array<string, callable>
	 */
	protected static $handlers = array();

	/**
	 * Registers a job kind handler.
	 *
	 * @param string   $kind    Job kind identifier.
	 * @param callable $handler Callback receiving ( $job, $worker_id ) and returning array.
	 */
	public static function register_handler( $kind, $handler ) {
		self::$handlers[ sanitize_key( $kind ) ] = $handler;
	}

	/**
	 * Initializes built-in job handlers.
	 */
	public static function init() {
		// Exercised by the test runner only.
		if ( defined( 'MIGRATE_LIFT_TESTING' ) ) {
			self::register_handler( 'test_job', array( __CLASS__, 'handle_test_job_step' ) );
		}

		// Register backup handler if loaded.
		if ( class_exists( 'Migrate_Lift_Backup_Engine' ) ) {
			self::register_handler( 'backup', array( 'Migrate_Lift_Backup_Engine', 'handle_backup_step' ) );
		}
	}

	/**
	 * Creates a new job record.
	 *
	 * @param string $kind            Job kind (e.g. 'test_job', 'backup', 'import').
	 * @param array  $params          Input parameters.
	 * @param string $idempotency_key Optional idempotency key to prevent duplicate jobs.
	 * @return array|WP_Error Job record or error.
	 */
	public static function create_job( $kind, $params = array(), $idempotency_key = '' ) {
		$kind = sanitize_key( $kind );

		// Check idempotency key.
		if ( ! empty( $idempotency_key ) ) {
			$idempotency_key = sanitize_text_field( $idempotency_key );
			$existing = self::find_by_idempotency_key( $idempotency_key );
			if ( $existing && ! in_array( $existing['state'], array( self::STATE_FAILED ), true ) ) {
				Migrate_Lift_Activity_Logger::info(
					'job_idempotent_hit',
					sprintf( 'Returned existing job %s for idempotency key %s', $existing['id'], $idempotency_key ),
					array( 'job_id' => $existing['id'], 'kind' => $existing['kind'] ),
					$existing['id']
				);
				return $existing;
			}
		}

		$job_id = 'mljob_' . wp_generate_password( 12, false, false );
		$now    = time();

		$record = array(
			'id'                => $job_id,
			'kind'              => $kind,
			'owner'             => get_current_user_id() ? get_current_user_id() : 1,
			'state'             => self::STATE_QUEUED,
			'percentage'        => 0,
			'phase'             => 'queued',
			'counts'            => array(
				'items_total'     => 0,
				'items_processed' => 0,
				'bytes_total'     => 0,
				'bytes_processed' => 0,
				'errors'          => 0,
			),
			'diagnostic_detail' => '',
			'created_at'        => $now,
			'started_at'        => null,
			'updated_at'        => $now,
			'completed_at'      => null,
			'retry_metadata'    => array(
				'retry_count'   => 0,
				'max_retries'   => 5,
				'last_retry_at' => null,
			),
			'checkpoint'        => array(
				'cursor'      => 0,
				'batch_index' => 0,
			),
			'idempotency_key'   => $idempotency_key,
			'lock_token'        => null,
			'locked_until'      => null,
			'params'            => Migrate_Lift_Activity_Logger::sanitize_context( $params ),
		);

		// Allow kind-specific initialization.
		if ( 'test_job' === $kind ) {
			$items_total = isset( $params['items_total'] ) ? max( 5, min( 100, (int) $params['items_total'] ) ) : 20;
			$record['counts']['items_total'] = $items_total;
			$record['checkpoint']['items_total'] = $items_total;
		}

		$saved = self::save_job( $record );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		Migrate_Lift_Activity_Logger::info(
			'job_created',
			sprintf( 'Job %s (%s) created by user #%d.', $job_id, $kind, $record['owner'] ),
			array( 'job_id' => $job_id, 'kind' => $kind ),
			$job_id
		);

		return $record;
	}

	/**
	 * Retrieves a job by ID.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|null Job record or null if not found.
	 */
	public static function get_job( $job_id ) {
		$job_id = sanitize_key( $job_id );
		$path   = self::get_job_file_path( $job_id );

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$raw = (string) @file_get_contents( $path );
		$newline_pos = strpos( $raw, "\n" );
		if ( false === $newline_pos ) {
			return null;
		}

		$data = json_decode( substr( $raw, $newline_pos + 1 ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Lists stored jobs with optional filtering.
	 *
	 * @param array $args Query arguments (state, kind, limit, offset).
	 * @return array Array with 'jobs' and 'total'.
	 */
	public static function list_jobs( $args = array() ) {
		$defaults = array(
			'state'  => '',
			'kind'   => '',
			'limit'  => 50,
			'offset' => 0,
		);
		$r = wp_parse_args( $args, $defaults );

		$dir = self::get_jobs_dir();
		if ( ! is_dir( $dir ) ) {
			return array( 'jobs' => array(), 'total' => 0 );
		}

		$files = glob( $dir . '/mljob_*.php' );
		if ( ! $files ) {
			return array( 'jobs' => array(), 'total' => 0 );
		}

		$jobs = array();
		foreach ( $files as $file ) {
			$raw = (string) @file_get_contents( $file );
			$newline_pos = strpos( $raw, "\n" );
			if ( false === $newline_pos ) {
				continue;
			}
			$job = json_decode( substr( $raw, $newline_pos + 1 ), true );
			if ( ! is_array( $job ) || empty( $job['id'] ) ) {
				continue;
			}

			if ( ! empty( $r['state'] ) && 'all' !== $r['state'] && ( $job['state'] ?? '' ) !== $r['state'] ) {
				continue;
			}

			if ( ! empty( $r['kind'] ) && 'all' !== $r['kind'] && ( $job['kind'] ?? '' ) !== $r['kind'] ) {
				continue;
			}

			$jobs[] = $job;
		}

		// Sort newest first by created_at.
		usort( $jobs, function( $a, $b ) {
			return ( $b['created_at'] ?? 0 ) - ( $a['created_at'] ?? 0 );
		} );

		$total  = count( $jobs );
		$limit  = max( 1, min( 100, (int) $r['limit'] ) );
		$offset = max( 0, (int) $r['offset'] );
		$paged  = array_slice( $jobs, $offset, $limit );

		return array(
			'jobs'   => $paged,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		);
	}

	/**
	 * Transitions job state if permitted.
	 *
	 * @param array  $job       Job record.
	 * @param string $new_state Destination state.
	 * @param string $detail    Optional diagnostic detail.
	 * @return array|WP_Error Updated job or error.
	 */
	public static function transition( &$job, $new_state, $detail = '' ) {
		$current = $job['state'];

		if ( $current === $new_state ) {
			return $job;
		}

		$allowed = self::TRANSITIONS[ $current ] ?? array();
		if ( ! in_array( $new_state, $allowed, true ) ) {
			return new WP_Error(
				'invalid_state_transition',
				sprintf( 'Illegal state transition from %s to %s for job %s', $current, $new_state, $job['id'] ),
				array( 'status' => 400 )
			);
		}

		$job['state']      = $new_state;
		$job['updated_at'] = time();

		if ( ! empty( $detail ) ) {
			$job['diagnostic_detail'] = Migrate_Lift_Activity_Logger::sanitize_text( $detail );
		}

		if ( self::STATE_RUNNING === $new_state && empty( $job['started_at'] ) ) {
			$job['started_at'] = time();
		}

		if ( in_array( $new_state, array( self::STATE_COMPLETED, self::STATE_FAILED ), true ) ) {
			$job['completed_at'] = time();
			$job['lock_token']   = null;
			$job['locked_until'] = null;
		}

		self::save_job( $job );

		$log_level = 'info';
		if ( self::STATE_COMPLETED === $new_state ) {
			$log_level = 'success';
		} elseif ( self::STATE_FAILED === $new_state ) {
			$log_level = 'error';
		} elseif ( self::STATE_PAUSED === $new_state || self::STATE_CANCEL_REQUESTED === $new_state ) {
			$log_level = 'warning';
		}

		Migrate_Lift_Activity_Logger::log(
			$log_level,
			'job_state_changed',
			sprintf( 'Job %s transitioned from %s to %s. %s', $job['id'], $current, $new_state, $job['diagnostic_detail'] ),
			array( 'job_id' => $job['id'], 'from' => $current, 'to' => $new_state ),
			$job['id']
		);

		return $job;
	}

	/**
	 * Claims a lease on the job to guarantee mutual exclusion.
	 *
	 * @param array  $job       Job record.
	 * @param string $worker_id Unique worker identifier.
	 * @return true|WP_Error True on success or WP_Error if claim refused.
	 */
	public static function claim_lease( &$job, $worker_id ) {
		$now = time();

		// Check if another worker currently holds an unexpired lease.
		if ( ! empty( $job['lock_token'] ) && ! empty( $job['locked_until'] ) ) {
			if ( $job['locked_until'] > $now && $job['lock_token'] !== $worker_id ) {
				return new WP_Error(
					'job_locked',
					sprintf( 'Job %s is currently claimed by worker %s until %s.', $job['id'], $job['lock_token'], wp_date( 'H:i:s', $job['locked_until'] ) ),
					array( 'status' => 409, 'locked_until' => $job['locked_until'] )
				);
			}

			// If lease expired, log recovery from worker restart.
			if ( $job['locked_until'] <= $now && $job['lock_token'] !== $worker_id ) {
				Migrate_Lift_Activity_Logger::warning(
					'job_lease_recovered',
					sprintf( 'Job %s had an expired lease from worker %s. Recovered by worker %s.', $job['id'], $job['lock_token'], $worker_id ),
					array( 'job_id' => $job['id'], 'old_worker' => $job['lock_token'], 'new_worker' => $worker_id ),
					$job['id']
				);
			}
		}

		$job['lock_token']   = $worker_id;
		$job['locked_until'] = $now + self::LEASE_DURATION;
		$job['updated_at']   = $now;

		return true;
	}

	/**
	 * Releases the worker lease on a job.
	 *
	 * @param array $job Job record.
	 */
	public static function release_lease( &$job ) {
		$job['lock_token']   = null;
		$job['locked_until'] = null;
		$job['updated_at']   = time();
		self::save_job( $job );
	}

	/**
	 * Executes a single bounded step of a job.
	 *
	 * @param string $job_id   Job identifier.
	 * @param string $worker_id Worker identifier.
	 * @return array|WP_Error Step result or error.
	 */
	public static function step_job( $job_id, $worker_id = '' ) {
		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'job_not_found', 'Requested job does not exist.', array( 'status' => 404 ) );
		}

		if ( empty( $worker_id ) ) {
			$worker_id = 'worker_' . wp_generate_password( 8, false, false );
		}

		// Handle terminal or paused states.
		if ( self::STATE_COMPLETED === $job['state'] ) {
			return array(
				'done'     => true,
				'job'      => $job,
				'message'  => __( 'Job already completed.', 'migrate-lift' ),
				'progress' => 100,
			);
		}

		if ( self::STATE_FAILED === $job['state'] ) {
			return new WP_Error( 'job_failed', sprintf( 'Job failed: %s', $job['diagnostic_detail'] ), array( 'status' => 400 ) );
		}

		if ( self::STATE_PAUSED === $job['state'] ) {
			return new WP_Error( 'job_paused', 'Job is paused. Resume before stepping.', array( 'status' => 400 ) );
		}

		// Handle cancel request.
		if ( self::STATE_CANCEL_REQUESTED === $job['state'] ) {
			self::transition( $job, self::STATE_FAILED, 'Job cancelled by user request.' );
			self::release_lease( $job );
			return array(
				'done'     => true,
				'job'      => $job,
				'message'  => __( 'Job was cancelled.', 'migrate-lift' ),
				'progress' => $job['percentage'],
			);
		}

		// Claim worker lease.
		$claim = self::claim_lease( $job, $worker_id );
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}

		// Transition to running.
		if ( self::STATE_QUEUED === $job['state'] ) {
			self::transition( $job, self::STATE_RUNNING, 'Worker processing started.' );
		}

		// Check handler registration.
		$kind = $job['kind'];
		if ( ! isset( self::$handlers[ $kind ] ) || ! is_callable( self::$handlers[ $kind ] ) ) {
			self::transition( $job, self::STATE_FAILED, sprintf( 'No registered handler for job kind %s', $kind ) );
			self::release_lease( $job );
			return new WP_Error( 'unsupported_job_kind', sprintf( 'Job kind %s is not supported.', $kind ), array( 'status' => 400 ) );
		}

		// Execute one batch step with error handling.
		try {
			// Initialize time budget if available.
			if ( function_exists( 'migrate_lift_timer_start' ) ) {
				migrate_lift_timer_start();
			}

			$step_result = call_user_func( self::$handlers[ $kind ], $job, $worker_id );
			if ( isset( $step_result['job'] ) && is_array( $step_result['job'] ) ) {
				$job = $step_result['job'];
			}

			// If completed, transition to completed and release lease.
			if ( ! empty( $step_result['done'] ) ) {
				$job['percentage'] = 100;
				$job['phase']      = 'completed';
				self::transition( $job, self::STATE_COMPLETED, $step_result['message'] ?? 'Job completed successfully.' );
				self::release_lease( $job );
			} else {
				// Job yielded for next step: extend lease or release lock for next HTTP tick.
				$job['lock_token']   = null;
				$job['locked_until'] = null;
				self::save_job( $job );
			}

			return array(
				'done'     => ! empty( $step_result['done'] ),
				'job'      => $job,
				'message'  => $step_result['message'] ?? '',
				'progress' => $job['percentage'],
			);

		} catch ( Exception $e ) {
			$error_message = $e->getMessage();
			$job['retry_metadata']['retry_count']++;
			$job['retry_metadata']['last_retry_at'] = time();

			if ( $job['retry_metadata']['retry_count'] >= $job['retry_metadata']['max_retries'] ) {
				self::transition( $job, self::STATE_FAILED, 'Max retries exceeded: ' . $error_message );
			} else {
				// Yield for retry.
				$job['diagnostic_detail'] = sprintf( 'Step error (attempt %d/%d): %s', $job['retry_metadata']['retry_count'], $job['retry_metadata']['max_retries'], $error_message );
				self::save_job( $job );
			}

			self::release_lease( $job );

			return new WP_Error( 'step_execution_error', Migrate_Lift_Activity_Logger::sanitize_text( $error_message ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Pauses an active or queued job.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Updated job or error.
	 */
	public static function pause_job( $job_id ) {
		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.', array( 'status' => 404 ) );
		}

		if ( in_array( $job['state'], array( self::STATE_COMPLETED, self::STATE_FAILED ), true ) ) {
			return new WP_Error( 'job_terminal', 'Cannot pause a finished job.', array( 'status' => 400 ) );
		}

		$result = self::transition( $job, self::STATE_PAUSED, 'Job paused by administrator.' );
		self::release_lease( $job );
		return $result;
	}

	/**
	 * Resumes a paused job.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Updated job or error.
	 */
	public static function resume_job( $job_id ) {
		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.', array( 'status' => 404 ) );
		}

		if ( self::STATE_PAUSED !== $job['state'] ) {
			return new WP_Error( 'job_not_paused', 'Only paused jobs can be resumed.', array( 'status' => 400 ) );
		}

		return self::transition( $job, self::STATE_QUEUED, 'Job resumed by administrator.' );
	}

	/**
	 * Requests cancellation of a job.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Updated job or error.
	 */
	public static function cancel_job( $job_id ) {
		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.', array( 'status' => 404 ) );
		}

		// If completed or failed, cannot cancel.
		if ( in_array( $job['state'], array( self::STATE_COMPLETED, self::STATE_FAILED ), true ) ) {
			return new WP_Error( 'job_terminal', 'Cannot cancel a finished job.', array( 'status' => 400 ) );
		}

		// Clean up staging files if backup job.
		if ( 'backup' === $job['kind'] && class_exists( 'Migrate_Lift_Backup_Engine' ) ) {
			Migrate_Lift_Backup_Engine::cleanup_job( $job );
		}

		// Directly mark as failed/cancelled and release worker lease.
		$result = self::transition( $job, self::STATE_FAILED, 'Cancelled by user request.' );
		self::release_lease( $job );
		return $result;
	}

	/**
	 * Built-in non-destructive test job handler.
	 *
	 * Demonstrates bounded batching, atomic checkpoint updates, percentage calculation,
	 * safe interruption, and resume without repeating completed batches.
	 *
	 * @param array  $job       Job data.
	 * @param string $worker_id Worker ID.
	 * @return array Result array with 'done', 'job', and 'message'.
	 */
	public static function handle_test_job_step( $job, $worker_id ) {
		$cursor      = (int) ( $job['checkpoint']['cursor'] ?? 0 );
		$total_items = (int) ( $job['counts']['items_total'] ?? 20 );
		$batch_size  = 5; // Process 5 items per HTTP step.

		$new_cursor = min( $total_items, $cursor + $batch_size );
		$processed  = $new_cursor;

		$job['checkpoint']['cursor']      = $new_cursor;
		$job['checkpoint']['batch_index'] = ( $job['checkpoint']['batch_index'] ?? 0 ) + 1;
		$job['counts']['items_processed'] = $processed;

		$percent = $total_items > 0 ? (int) round( ( $processed / $total_items ) * 100 ) : 100;
		$job['percentage'] = min( 100, $percent );

		if ( $processed < $total_items ) {
			$job['phase'] = 'batch_processing';
			$message = sprintf( 'Processed test batch %d (%d/%d items, %d%%). Checkpoint saved at item #%d.', $job['checkpoint']['batch_index'], $processed, $total_items, $percent, $new_cursor );
			$job['diagnostic_detail'] = $message;

			Migrate_Lift_Activity_Logger::info(
				'test_job_checkpoint',
				$message,
				array( 'job_id' => $job['id'], 'cursor' => $new_cursor, 'percent' => $percent ),
				$job['id']
			);

			self::save_job( $job );

			return array(
				'done'    => false,
				'job'     => $job,
				'message' => $message,
			);
		}

		// Reached total.
		$job['phase']      = 'finalizing';
		$job['percentage'] = 100;
		$message = sprintf( 'Demonstration test job finished all %d items across %d checkpointed batches.', $total_items, $job['checkpoint']['batch_index'] );
		$job['diagnostic_detail'] = $message;

		Migrate_Lift_Activity_Logger::success(
			'test_job_completed',
			$message,
			array( 'job_id' => $job['id'], 'total' => $total_items ),
			$job['id']
		);

		self::save_job( $job );

		return array(
			'done'    => true,
			'job'     => $job,
			'message' => $message,
		);
	}

	/**
	 * Finds an existing job by idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return array|null Job or null.
	 */
	protected static function find_by_idempotency_key( $key ) {
		$res = self::list_jobs( array( 'limit' => 50 ) );
		foreach ( $res['jobs'] as $j ) {
			if ( ! empty( $j['idempotency_key'] ) && $j['idempotency_key'] === $key ) {
				return $j;
			}
		}
		return null;
	}

	/**
	 * Saves a job record to protected disk storage.
	 *
	 * @param array $job Job record.
	 * @return true|WP_Error True or error.
	 */
	public static function save_job( $job ) {
		if ( empty( $job['id'] ) ) {
			return new WP_Error( 'missing_job_id', 'Cannot save job without ID.' );
		}

		$path = self::get_job_file_path( $job['id'] );
		$payload = "<?php exit; ?>\n" . wp_json_encode( $job );

		$written = @file_put_contents( $path, $payload, LOCK_EX );
		if ( false === $written ) {
			return new WP_Error( 'job_write_failed', sprintf( 'Could not persist job file at %s', basename( $path ) ) );
		}

		return true;
	}

	/**
	 * Returns directory path where job files are persisted.
	 *
	 * @return string
	 */
	public static function get_jobs_dir() {
		$dir = migrate_lift_dir( self::JOBS_DIR );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Returns file path for a specific job ID.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	public static function get_job_file_path( $job_id ) {
		return self::get_jobs_dir() . '/' . sanitize_key( $job_id ) . '.php';
	}
}

// Initialize built-in handlers.
Migrate_Lift_Job_Engine::init();
