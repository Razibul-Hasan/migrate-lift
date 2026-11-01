<?php
/**
 * Automated Backup Scheduler & Retention Engine.
 *
 * Implements Part 08 Daily Backup Scheduler:
 * - Configurable cadence (Daily, Weekly, Manual) and site-timezone start time
 * - High-precision timezone/DST handling with DateTimeImmutable and wp_timezone()
 * - Safe WP-Cron scheduling with duplicate event prevention on activation/settings change
 * - Graceful unscheduling on deactivation
 * - Resumable backup execution via Migrate_Lift_Backup_Engine and format 2.0 manifest
 * - Overlap prevention: skips execution if a backup job is already active
 * - Capped exponential backoff retry policy on failure (15m, 30m, 60m; max 3 retries)
 * - Safe retention pruning: removes only eligible completed archives; preserves recovery checkpoints, incremental bases, and active staging files
 * - Throttled admin notifications for repeated failures and low free server disk space
 * - System crontab instructions for exact-time operation without requiring cPanel access
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrate_Lift_Scheduler
 */
class Migrate_Lift_Scheduler {

	/**
	 * WP-Cron event hook for scheduled backups.
	 */
	const CRON_HOOK = 'migrate_lift_scheduled_backup_event';

	/**
	 * WP-Cron event hook for failure retry backoff.
	 */
	const RETRY_CRON_HOOK = 'migrate_lift_scheduled_backup_retry_event';

	/**
	 * Option name for scheduler settings.
	 */
	const OPTION_SETTINGS = 'migrate_lift_schedule_settings';

	/**
	 * Option name for last run report.
	 */
	const OPTION_LAST_RUN = 'migrate_lift_schedule_last_run';

	/**
	 * Option name for failure tracking.
	 */
	const OPTION_FAILURES = 'migrate_lift_schedule_failures';

	/**
	 * Option name for last failure email timestamp.
	 */
	const OPTION_LAST_ALERT_SENT = 'migrate_lift_last_alert_email_sent';

	/**
	 * Minimum required free disk space in bytes (50 MB).
	 */
	const MIN_FREE_DISK_SPACE = 52428800;

	/**
	 * Initializes hooks and scheduler filters.
	 */
	public static function init() {
		// Register custom cron schedule interval (e.g. weekly).
		add_filter( 'cron_schedules', array( __CLASS__, 'filter_cron_schedules' ) );

		// Register cron event handlers.
		add_action( self::CRON_HOOK, array( __CLASS__, 'execute_scheduled_backup' ) );
		add_action( self::RETRY_CRON_HOOK, array( __CLASS__, 'execute_scheduled_backup' ) );
	}

	/**
	 * Adds weekly schedule interval to WP-Cron.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function filter_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'migrate-lift' ),
			);
		}
		return $schedules;
	}

	/**
	 * Returns default scheduler settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		$admin_email = function_exists( 'get_option' ) ? get_option( 'admin_email' ) : '';
		return array(
			'enabled'            => false,
			'cadence'            => 'daily', // 'daily', 'weekly', 'manual'
			'frequency'          => 'daily',
			'time'               => '02:00', // HH:MM in site timezone
			'start_time'         => '02:00',
			'day_of_week'        => 1,       // 1 (Monday) to 7 (Sunday)
			'retention_copies'   => 7,       // Number of backups to keep (1-60)
			'retention'          => 7,
			'notify_on_failure'  => true,
			'notify_email'       => $admin_email ? sanitize_email( $admin_email ) : '',
			'notify_low_disk'    => true,
			'low_disk_threshold' => self::MIN_FREE_DISK_SPACE,
		);
	}

	/**
	 * Retrieves current scheduler settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION_SETTINGS, array() ) : array();
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_defaults() );
	}

	/**
	 * Calculates the next run timestamp in UTC for a given schedule configuration.
	 * Respects site timezone and daylight saving time (DST) shifts.
	 *
	 * @param string|array            $time_str    Time in 'HH:MM' format or settings array.
	 * @param string                  $cadence     'daily' or 'weekly'.
	 * @param int                     $day_of_week 1 (Mon) to 7 (Sun) or 0 (Sun).
	 * @param DateTimeImmutable|null $now         Optional reference time for testing.
	 * @return int UTC timestamp.
	 */
	public static function calculate_next_run( $time_str = '02:00', $cadence = 'daily', $day_of_week = 1, $now = null ) {
		if ( is_array( $time_str ) ) {
			$args        = $time_str;
			$cadence     = $args['cadence'] ?? ( $args['frequency'] ?? 'daily' );
			$day_of_week = $args['day_of_week'] ?? 1;
			$now         = $args['now'] ?? null;
			$time_str    = $args['time'] ?? ( $args['start_time'] ?? '02:00' );
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

		if ( $now instanceof DateTimeImmutable ) {
			$current = $now->setTimezone( $timezone );
		} elseif ( function_exists( 'current_datetime' ) ) {
			$current = current_datetime();
		} else {
			$current = new DateTimeImmutable( 'now', $timezone );
		}

		// Parse hours and minutes safely.
		$parts  = explode( ':', (string) $time_str );
		$hour   = isset( $parts[0] ) ? max( 0, min( 23, (int) $parts[0] ) ) : 2;
		$minute = isset( $parts[1] ) ? max( 0, min( 59, (int) $parts[1] ) ) : 0;

		$target = $current->setTime( $hour, $minute, 0 );

		if ( 'weekly' === $cadence ) {
			$current_dow = (int) $current->format( 'N' );
			$target_dow  = (int) $day_of_week;
			if ( 0 === $target_dow ) {
				$target_dow = 7; // Convert 0 (Sunday) to ISO 7
			}
			$target_dow = max( 1, min( 7, $target_dow ) );
			$diff_days  = $target_dow - $current_dow;

			if ( $diff_days < 0 || ( 0 === $diff_days && $target <= $current ) ) {
				$diff_days += 7;
			}
			$target = $target->modify( "+{$diff_days} days" );
		} else {
			// Daily recurrence.
			if ( $target <= $current ) {
				$target = $target->modify( '+1 day' );
			}
		}

		return $target->getTimestamp();
	}

	/**
	 * Reschedules the WP-Cron event according to current settings.
	 * Clears any existing scheduled hooks to prevent duplicate runs.
	 *
	 * @param array|null $settings Optional settings to apply; defaults to stored.
	 * @return int|false Next timestamp or false if disabled.
	 */
	public static function reschedule( $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		// Always clear existing scheduled events to guarantee zero duplicate triggers.
		self::unschedule();

		if ( empty( $settings['enabled'] ) || 'manual' === ( $settings['cadence'] ?? '' ) ) {
			return false;
		}

		$cadence     = $settings['cadence'] === 'weekly' ? 'weekly' : 'daily';
		$next_run    = self::calculate_next_run( $settings['time'] ?? '02:00', $cadence, $settings['day_of_week'] ?? 1 );
		$recurrence  = 'weekly' === $cadence ? 'weekly' : 'daily';

		if ( function_exists( 'wp_schedule_event' ) ) {
			wp_schedule_event( $next_run, $recurrence, self::CRON_HOOK );
		}

		return $next_run;
	}

	/**
	 * Unschedules all MigrateLift cron events.
	 */
	public static function unschedule() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_clear_scheduled_hook( self::RETRY_CRON_HOOK );
		}
	}

	/**
	 * Returns the timestamp of the next scheduled run, or null if none scheduled.
	 *
	 * @return int|null
	 */
	public static function get_next_run_timestamp() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return null;
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		return $next ? (int) $next : null;
	}

	/**
	 * Formats a human-readable frequency description string.
	 *
	 * @param array $settings Settings array.
	 * @return string
	 */
	public static function get_frequency_label( $settings ) {
		if ( empty( $settings['enabled'] ) || 'manual' === ( $settings['cadence'] ?? '' ) ) {
			return __( 'Manual / Disabled', 'migrate-lift' );
		}

		$time = ! empty( $settings['time'] ) ? $settings['time'] : '02:00';
		$tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';

		if ( 'weekly' === ( $settings['cadence'] ?? '' ) ) {
			$days = array(
				1 => __( 'Monday', 'migrate-lift' ),
				2 => __( 'Tuesday', 'migrate-lift' ),
				3 => __( 'Wednesday', 'migrate-lift' ),
				4 => __( 'Thursday', 'migrate-lift' ),
				5 => __( 'Friday', 'migrate-lift' ),
				6 => __( 'Saturday', 'migrate-lift' ),
				7 => __( 'Sunday', 'migrate-lift' ),
			);
			$dow = $days[ $settings['day_of_week'] ?? 1 ] ?? __( 'Monday', 'migrate-lift' );
			return sprintf(
				/* translators: 1: day of week, 2: time, 3: timezone */
				__( 'Weekly on %1$s at %2$s (%3$s)', 'migrate-lift' ),
				$dow,
				$time,
				$tz_string
			);
		}

		return sprintf(
			/* translators: 1: time, 2: timezone */
			__( 'Daily at %1$s (%2$s)', 'migrate-lift' ),
			$time,
			$tz_string
		);
	}

	/**
	 * Validates and saves scheduler settings.
	 *
	 * @param array $input Raw input.
	 * @return array|WP_Error Saved settings or error.
	 */
	public static function save_settings( $input ) {
		$defaults = self::get_defaults();

		$enabled     = ! empty( $input['enabled'] );
		$cadence_raw = $input['cadence'] ?? ( $input['frequency'] ?? $defaults['cadence'] );
		$cadence     = sanitize_key( $cadence_raw );
		if ( ! in_array( $cadence, array( 'daily', 'weekly', 'manual' ), true ) ) {
			$cadence = 'daily';
		}

		$time_raw = $input['time'] ?? ( $input['start_time'] ?? $defaults['time'] );
		$time     = sanitize_text_field( $time_raw );
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return new WP_Error(
				'invalid_schedule_time',
				__( 'Invalid schedule time format. Please provide a valid 24-hour time string (e.g. 02:00).', 'migrate-lift' ),
				array( 'status' => 400 )
			);
		}

		$dow_raw     = $input['day_of_week'] ?? 1;
		$day_of_week = (int) $dow_raw;
		if ( 0 === $day_of_week ) {
			$day_of_week = 7;
		}
		$day_of_week = max( 1, min( 7, $day_of_week ) );

		$ret_raw   = $input['retention_copies'] ?? ( $input['retention'] ?? 7 );
		$retention = max( 1, min( 100, (int) $ret_raw ) );

		$notify_fail = isset( $input['notify_on_failure'] ) ? (bool) $input['notify_on_failure'] : true;
		$notify_mail = isset( $input['notify_email'] ) ? sanitize_email( $input['notify_email'] ) : $defaults['notify_email'];
		if ( $notify_fail && ! empty( $notify_mail ) && ! is_email( $notify_mail ) ) {
			return new WP_Error(
				'invalid_notify_email',
				__( 'Please provide a valid email address for failure alerts.', 'migrate-lift' ),
				array( 'status' => 400 )
			);
		}

		$notify_disk = isset( $input['notify_low_disk'] ) ? (bool) $input['notify_low_disk'] : true;

		$cleaned = array(
			'enabled'            => $enabled,
			'cadence'            => $cadence,
			'frequency'          => $cadence,
			'time'               => $time,
			'start_time'         => $time,
			'day_of_week'        => $day_of_week,
			'retention_copies'   => $retention,
			'retention'          => $retention,
			'notify_on_failure'  => $notify_fail,
			'notify_email'       => $notify_mail,
			'notify_low_disk'    => $notify_disk,
			'low_disk_threshold' => self::MIN_FREE_DISK_SPACE,
		);

		update_option( self::OPTION_SETTINGS, $cleaned, false );

		// Reschedule cron event.
		self::reschedule( $cleaned );

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			Migrate_Lift_Activity_Logger::info(
				'schedule_settings_updated',
				sprintf(
					'Backup schedule updated: %s, retention: %d copies, alerts: %s',
					self::get_frequency_label( $cleaned ),
					$retention,
					$notify_fail ? 'enabled' : 'disabled'
				),
				$cleaned
			);
		}

		return $cleaned;
	}

	/**
	 * Core execution handler for scheduled backup cron events.
	 *
	 * @param bool $is_manual Whether triggered manually via admin UI.
	 * @return array|bool Result summary or false on skip/failure.
	 */
	public static function execute_scheduled_backup( $is_manual = false ) {
		$settings = self::get_settings();

		// If triggered by cron and schedule is disabled, clear and exit.
		if ( ! $is_manual && empty( $settings['enabled'] ) ) {
			self::unschedule();
			return false;
		}

		// 1. Prevent overlapping jobs.
		if ( class_exists( 'Migrate_Lift_Job_Engine' ) ) {
			$active_jobs = Migrate_Lift_Job_Engine::list_jobs( array( 'kind' => 'backup', 'limit' => 5 ) );
			foreach ( $active_jobs['jobs'] ?? array() as $job ) {
				if ( in_array( $job['state'], array( 'queued', 'running', 'paused' ), true ) ) {
					if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
						Migrate_Lift_Activity_Logger::info(
							'scheduled_backup_skipped_overlap',
							sprintf( 'Scheduled backup skipped: Backup job %s is currently active (%d%%).', $job['id'], $job['percentage'] ),
							array( 'active_job' => $job['id'] )
						);
					}
					return false;
				}
			}
		}

		// 2. Preflight disk capacity check.
		$store_dir  = function_exists( 'migrate_lift_dir' ) ? migrate_lift_dir() : '';
		$free_space = function_exists( 'disk_free_space' ) && ! empty( $store_dir ) ? @disk_free_space( $store_dir ) : null;

		if ( false !== $free_space && null !== $free_space && $free_space < self::MIN_FREE_DISK_SPACE ) {
			$err_msg = sprintf(
				'Scheduled backup aborted: Free server disk space is critically low (%s available; minimum %s required).',
				size_format( $free_space ),
				size_format( self::MIN_FREE_DISK_SPACE )
			);
			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::error( 'scheduled_backup_low_disk', $err_msg );
			}
			self::maybe_notify_low_disk( $free_space );
			self::record_failure( $err_msg );
			return false;
		}

		// 3. Create scheduled backup job.
		if ( ! class_exists( 'Migrate_Lift_Job_Engine' ) ) {
			self::record_failure( 'Job Engine is unavailable.' );
			return false;
		}

		$trigger_type    = $is_manual ? 'manual_schedule_trigger' : 'wp_cron';
		$idempotency_key = 'sched_' . gmdate( 'Ymd_H' );

		$job_params = array(
			'type'    => 'scheduled',
			'trigger' => $trigger_type,
		);

		$job = Migrate_Lift_Job_Engine::create_job( 'backup', $job_params, $idempotency_key );
		if ( is_wp_error( $job ) ) {
			self::record_failure( $job->get_error_message() );
			return false;
		}

		$job_id = $job['id'];

		// 4. Drive job forward within time budget.
		$max_time = microtime( true ) + 28;
		$worker   = $trigger_type;

		try {
			while ( ! empty( $job_id ) && in_array( $job['state'] ?? 'running', array( 'queued', 'running' ), true ) ) {
				if ( microtime( true ) >= $max_time ) {
					// Time budget reached; the job remains queued/running with state preserved in DB/file.
					// On next cron or visit, it can resume!
					break;
				}

				$step_result = Migrate_Lift_Job_Engine::step_job( $job_id, $worker );
				if ( is_wp_error( $step_result ) ) {
					throw new Exception( $step_result->get_error_message() );
				}

				$job = $step_result['job'] ?? array();
				if ( ! empty( $step_result['done'] ) || ( $job['state'] ?? '' ) === 'completed' ) {
					break;
				}

				// Small pause between batches.
				usleep( 50000 );
			}

			// If completed:
			if ( ( $job['state'] ?? '' ) === 'completed' ) {
				$archive_name = $job['output']['archive'] ?? '';
				$archive_size = $job['output']['size'] ?? 0;

				// Record successful run report.
				update_option(
					self::OPTION_LAST_RUN,
					array(
						'timestamp' => time(),
						'status'    => 'success',
						'archive'   => $archive_name,
						'size'      => $archive_size,
						'message'   => 'Scheduled backup completed and verified successfully.',
					),
					false
				);

				// Reset failure counter.
				delete_option( self::OPTION_FAILURES );

				if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
					Migrate_Lift_Activity_Logger::success(
						'scheduled_backup_success',
						sprintf( 'Automated scheduled backup completed: %s (%s).', $archive_name, size_format( $archive_size ) ),
						array(
							'job_id'  => $job_id,
							'archive' => $archive_name,
							'size'    => $archive_size,
						)
					);
				}

				// 5. Execute safe retention pruning.
				$prune_report = self::prune_retention( $settings['retention_copies'] ?? 7 );

				return array(
					'success'      => true,
					'job'          => $job,
					'archive'      => $archive_name,
					'size'         => $archive_size,
					'prune_report' => $prune_report,
				);
			}

			// Job is still stepping (long-running on slow host).
			return array(
				'success' => true,
				'job'     => $job,
				'message' => 'Scheduled backup initiated and stepping in background.',
			);

		} catch ( Exception $e ) {
			self::record_failure( $e->getMessage() );
			return false;
		}
	}

	/**
	 * Records a failure and executes capped backoff retry policy.
	 *
	 * @param string $error_message Error description.
	 */
	public static function record_failure( $error_message ) {
		$failures = (int) get_option( self::OPTION_FAILURES, 0 ) + 1;
		update_option( self::OPTION_FAILURES, $failures, false );

		update_option(
			self::OPTION_LAST_RUN,
			array(
				'timestamp' => time(),
				'status'    => 'failed',
				'archive'   => '',
				'size'      => 0,
				'message'   => $error_message,
			),
			false
		);

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			Migrate_Lift_Activity_Logger::error(
				'scheduled_backup_failed',
				sprintf( 'Automated backup failed (Attempt #%d): %s', $failures, $error_message ),
				array( 'failure_count' => $failures, 'error' => $error_message )
			);
		}

		// Capped exponential retry: attempts 1, 2, 3 scheduled at +15m, +30m, +60m.
		if ( $failures <= 3 && function_exists( 'wp_schedule_single_event' ) ) {
			$delay_seconds = min( 3600, (int) pow( 2, $failures - 1 ) * 15 * MINUTE_IN_SECONDS );
			wp_clear_scheduled_hook( self::RETRY_CRON_HOOK );
			wp_schedule_single_event( time() + $delay_seconds, self::RETRY_CRON_HOOK );

			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::warning(
					'scheduled_backup_retry_queued',
					sprintf( 'Scheduled backup retry attempt #%d queued in %d minutes.', $failures + 1, (int) ( $delay_seconds / 60 ) )
				);
			}
		}

		// If >= 2 consecutive failures, dispatch throttled email alert to admin.
		if ( $failures >= 2 ) {
			self::maybe_notify_failure( $error_message, $failures );
		}
	}

	/**
	 * Clears failure counter and any pending retry events.
	 */
	public static function clear_failures() {
		update_option( self::OPTION_FAILURES, 0, false );
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::RETRY_CRON_HOOK );
		}
	}

	/**
	 * Safe retention engine: removes only eligible completed backups.
	 * NEVER removes a live staging file (.part), a registered recovery checkpoint,
	 * an incremental base, or any file referenced by an active job.
	 *
	 * @param int $retention_copies Number of backups to keep.
	 * @return array Prune summary.
	 */
	public static function prune_retention( $retention_copies = 7 ) {
		$retention_copies = max( 1, (int) $retention_copies );
		$all_archives     = function_exists( 'migrate_lift_archives' ) ? migrate_lift_archives() : array();

		if ( count( $all_archives ) <= $retention_copies ) {
			return array(
				'pruned'  => array(),
				'deleted' => array(),
				'kept'    => count( $all_archives ),
			);
		}

		// Compile list of protected filenames that must NEVER be pruned.
		$protected_files = array();

		// 1. Designated recovery checkpoint snapshot.
		$recovery_point = get_option( 'migrate_lift_recovery_point' );
		if ( ! empty( $recovery_point ) ) {
			$protected_files[] = (string) $recovery_point;
		}

		// 2. Incremental base chain.
		$chain_base = get_option( 'migrate_lift_incremental_chain_base' );
		if ( ! empty( $chain_base ) ) {
			$protected_files[] = (string) $chain_base;
		}

		// 3. Files in use by active background jobs.
		if ( class_exists( 'Migrate_Lift_Job_Engine' ) ) {
			$active_jobs = Migrate_Lift_Job_Engine::list_jobs( array( 'limit' => 20 ) );
			foreach ( $active_jobs['jobs'] ?? array() as $j ) {
				if ( in_array( $j['state'], array( 'queued', 'running', 'paused' ), true ) ) {
					if ( ! empty( $j['params']['archive'] ) ) $protected_files[] = (string) $j['params']['archive'];
					if ( ! empty( $j['params']['file'] ) ) $protected_files[] = (string) $j['params']['file'];
					if ( ! empty( $j['checkpoint']['archive_name'] ) ) $protected_files[] = (string) $j['checkpoint']['archive_name'];
					if ( ! empty( $j['checkpoint']['recovery_checkpoint'] ) ) $protected_files[] = (string) $j['checkpoint']['recovery_checkpoint'];
				}
			}
		}

		$eligible_archives = array();
		foreach ( $all_archives as $arch ) {
			$filename = $arch['name'] ?? $arch['file'] ?? '';
			if ( empty( $filename ) ) {
				continue;
			}

			// Protection check: Never delete protected files.
			if ( in_array( $filename, $protected_files, true ) ) {
				continue;
			}

			// Safety check: Never delete partial staging files.
			if ( false !== strpos( $filename, '.part' ) || false !== strpos( $filename, 'tmp' ) ) {
				continue;
			}

			$eligible_archives[] = $arch;
		}

		// Sort newest first by timestamp.
		usort(
			$eligible_archives,
			function ( $a, $b ) {
				return ( $b['time'] ?? 0 ) - ( $a['time'] ?? 0 );
			}
		);

		// Keep the newest $retention_copies; prune the remainder.
		$to_prune     = array_slice( $eligible_archives, $retention_copies );
		$pruned_files = array();

		foreach ( $to_prune as $old_arch ) {
			$filename = $old_arch['name'] ?? $old_arch['file'] ?? '';
			try {
				$path = function_exists( 'migrate_lift_archive_path' ) ? migrate_lift_archive_path( $filename ) : '';
				if ( ! empty( $path ) && file_exists( $path ) && wp_delete_file( $path ) ) {
					$pruned_files[] = $filename;

					// Clean up cached metadata.
					$meta = get_option( 'migrate_lift_backup_metadata', array() );
					if ( is_array( $meta ) && isset( $meta[ $filename ] ) ) {
						unset( $meta[ $filename ] );
						update_option( 'migrate_lift_backup_metadata', $meta, false );
					}

					if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
						Migrate_Lift_Activity_Logger::info(
							'retention_prune',
							sprintf(
								'Retention policy pruned older backup: %s (Retention limit: %d copies).',
								$filename,
								$retention_copies
							),
							array(
								'archive' => $filename,
								'size'    => $old_arch['size'] ?? 0,
							)
						);
					}
				}
			} catch ( Exception $e ) {
				// Suppress and continue pruning remaining eligible files.
			}
		}

		return array(
			'pruned'  => $pruned_files,
			'deleted' => $pruned_files,
			'kept'    => count( $all_archives ) - count( $pruned_files ),
		);
	}

	/**
	 * Dispatches an email notification to the site administrator on repeated failures.
	 * Throttled to once per 24 hours to prevent inbox flooding.
	 *
	 * @param string $error_message Error description.
	 * @param int    $failures      Number of consecutive failures.
	 */
	public static function maybe_notify_failure( $error_message, $failures ) {
		$settings = self::get_settings();
		if ( empty( $settings['notify_on_failure'] ) ) {
			return;
		}

		$recipient = ! empty( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );
		if ( empty( $recipient ) || ! is_email( $recipient ) ) {
			return;
		}

		// Throttling: Max 1 email per 24 hours.
		$last_sent = (int) get_option( self::OPTION_LAST_ALERT_SENT, 0 );
		if ( time() - $last_sent < DAY_IN_SECONDS ) {
			return;
		}

		$site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'WordPress';
		$site_url  = function_exists( 'home_url' ) ? home_url() : '';
		$subject   = sprintf( '[%s] MigrateLift Alert: Automated Backup Failed (%d attempts)', $site_name, $failures );

		$body = sprintf(
			"Hello Administrator,\n\n" .
			"The automated backup schedule on your website (%s) has failed %d consecutive times.\n\n" .
			"Last Error:\n%s\n\n" .
			"Please log in to your WordPress dashboard to inspect system logs and verify server disk space:\n" .
			"%s\n\n" .
			"—\nMigrateLift Backup & Protection System",
			$site_url,
			$failures,
			$error_message,
			admin_url( 'admin.php?page=migrate-lift#schedule' )
		);

		if ( function_exists( 'wp_mail' ) ) {
			$sent = @wp_mail( $recipient, $subject, $body );
			if ( $sent ) {
				update_option( self::OPTION_LAST_ALERT_SENT, time(), false );
			}
		}
	}

	/**
	 * Dispatches an alert notification when server disk space is critically low.
	 * Throttled to once per 24 hours.
	 *
	 * @param int $free_bytes Available space in bytes.
	 */
	public static function maybe_notify_low_disk( $free_bytes ) {
		$settings = self::get_settings();
		if ( empty( $settings['notify_low_disk'] ) ) {
			return;
		}

		$recipient = ! empty( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );
		if ( empty( $recipient ) || ! is_email( $recipient ) ) {
			return;
		}

		$last_sent = (int) get_option( self::OPTION_LAST_ALERT_SENT, 0 );
		if ( time() - $last_sent < DAY_IN_SECONDS ) {
			return;
		}

		$site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'WordPress';
		$site_url  = function_exists( 'home_url' ) ? home_url() : '';
		$subject   = sprintf( '[%s] MigrateLift Warning: Low Server Disk Space', $site_name );

		$body = sprintf(
			"Hello Administrator,\n\n" .
			"Server storage space on your website (%s) is critically low.\n\n" .
			"Available Free Space: %s\n" .
			"Minimum Required: %s\n\n" .
			"Automated backups will be safely paused until sufficient server disk space is freed.\n\n" .
			"Inspect your stored archives:\n%s\n\n" .
			"—\nMigrateLift Backup & Protection System",
			$site_url,
			size_format( $free_bytes ),
			size_format( self::MIN_FREE_DISK_SPACE ),
			admin_url( 'admin.php?page=migrate-lift#backups' )
		);

		if ( function_exists( 'wp_mail' ) ) {
			$sent = @wp_mail( $recipient, $subject, $body );
			if ( $sent ) {
				update_option( self::OPTION_LAST_ALERT_SENT, time(), false );
			}
		}
	}

	/**
	 * Returns system crontab commands for users desiring exact-time operation.
	 *
	 * @return array
	 */
	public static function get_system_cron_info() {
		$home_url   = function_exists( 'home_url' ) ? home_url() : '';
		$cron_url   = untrailingslashit( $home_url ) . '/wp-cron.php?doing_wp_cron';
		$abspath    = defined( 'ABSPATH' ) ? untrailingslashit( ABSPATH ) : '/path/to/wordpress';
		$disable_wp = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return array(
			'disable_wp_cron_defined' => $disable_wp,
			'curl_command'            => sprintf( '* * * * * curl -s -L "%s" > /dev/null 2>&1', $cron_url ),
			'wget_command'            => sprintf( '* * * * * wget -q -O - "%s" > /dev/null 2>&1', $cron_url ),
			'wp_cli_command'          => sprintf( '0 2 * * * wp cron event run %s --path=%s > /dev/null 2>&1', self::CRON_HOOK, $abspath ),
			'notice'                  => __( 'WordPress default cron relies on site visitor page loads. For exact clock timing on low-traffic sites, add this single-line cron job to your host crontab.', 'migrate-lift' ),
		);
	}
}
