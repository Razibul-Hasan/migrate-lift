<?php
/**
 * Activity Logging Engine.
 *
 * Persists a bounded, structured audit trail of operations, jobs, and diagnostic events.
 * Strictly sanitizes and redacts credentials, raw SQL, tokens, and absolute paths.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrate_Lift_Activity_Logger
 */
class Migrate_Lift_Activity_Logger {

	/**
	 * Maximum number of log entries to retain.
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Log file name inside migrate-lift-files storage directory.
	 */
	const LOG_FILE = 'activity-log.json';

	/**
	 * Supported log levels.
	 */
	const LEVELS = array( 'info', 'warning', 'error', 'success' );

	/**
	 * Records an event in the activity log.
	 *
	 * @param string      $level   Log level: info, warning, error, success.
	 * @param string      $action  Short action identifier (e.g. 'job_created', 'export_step').
	 * @param string      $message Human-readable descriptive message.
	 * @param array       $context Additional diagnostic context.
	 * @param string|null $job_id  Optional associated job ID.
	 * @return array The recorded event entry.
	 */
	public static function log( $level, $action, $message, $context = array(), $job_id = null ) {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		$sanitized_message = self::sanitize_text( $message );
		$sanitized_context = self::sanitize_context( $context );

		$entry = array(
			'id'        => 'act_' . wp_generate_password( 12, false ),
			'timestamp' => time(),
			'date'      => wp_date( 'Y-m-d H:i:s' ),
			'level'     => $level,
			'action'    => sanitize_key( $action ),
			'message'   => $sanitized_message,
			'context'   => $sanitized_context,
			'job_id'    => $job_id ? sanitize_text_field( $job_id ) : null,
		);

		self::append_entry( $entry );

		// Fire WordPress action for extensible integration.
		do_action( 'migrate_lift_activity_logged', $entry );

		return $entry;
	}

	/**
	 * Convenience shortcuts for log levels.
	 */
	public static function info( $action, $message, $context = array(), $job_id = null ) {
		return self::log( 'info', $action, $message, $context, $job_id );
	}

	public static function success( $action, $message, $context = array(), $job_id = null ) {
		return self::log( 'success', $action, $message, $context, $job_id );
	}

	public static function warning( $action, $message, $context = array(), $job_id = null ) {
		return self::log( 'warning', $action, $message, $context, $job_id );
	}

	public static function error( $action, $message, $context = array(), $job_id = null ) {
		return self::log( 'error', $action, $message, $context, $job_id );
	}

	/**
	 * Sanitizes text to remove credentials, tokens, raw SQL queries, and server root paths.
	 *
	 * @param string $text Raw input text.
	 * @return string Sanitized text.
	 */
	public static function sanitize_text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		// 1. Redact server absolute paths (ABSPATH, WP_CONTENT_DIR).
		$text = str_replace( migrate_lift_root_dir(), '[ABSPATH]/', $text );
		$text = str_replace( migrate_lift_content_dir(), '[WP_CONTENT_DIR]', $text );
		// Redact standard Windows or Unix home directories if present.
		$text = preg_replace( '#([a-zA-Z]:[\\\\/](?:Users|home)[\\\\/][^\\\\/]+)#i', '[USER_HOME]', $text );

		// 2. Redact DB credentials and secrets keywords.
		$secret_patterns = array(
			'#(?i)(password|passwd|pwd|secret|token|nonce|api_key|auth_key|authorization)\s*[:=]\s*["\']?[^\s,"\'&]+["\']?#' => '$1=[REDACTED]',
			'#(?i)(bearer\s+)[a-zA-Z0-9_\-\.]{15,}#' => '$1[REDACTED]',
		);
		$text = preg_replace( array_keys( $secret_patterns ), array_values( $secret_patterns ), $text );

		// 3. Redact raw SQL statements.
		$sql_keywords = '#(?i)\b(SELECT\s+.+\s+FROM|INSERT\s+INTO\s+.+\s+VALUES|UPDATE\s+.+\s+SET|DELETE\s+FROM|DROP\s+TABLE|ALTER\s+TABLE)\b.*#s';
		if ( preg_match( $sql_keywords, $text ) ) {
			$text = preg_replace( $sql_keywords, '[SQL_STATEMENT_REDACTED]', $text );
		}

		return sanitize_text_field( $text );
	}

	/**
	 * Recursively sanitizes diagnostic context arrays.
	 *
	 * @param mixed $context Raw context data.
	 * @return mixed Sanitized context.
	 */
	public static function sanitize_context( $context ) {
		if ( ! is_array( $context ) ) {
			if ( is_string( $context ) ) {
				return self::sanitize_text( $context );
			}
			return $context;
		}

		$sanitized = array();
		$sensitive_keys = array(
			'password',
			'pwd',
			'secret',
			'token',
			'nonce',
			'key',
			'auth',
			'credentials',
			'db_password',
			'db_user',
		);

		foreach ( $context as $k => $v ) {
			$clean_key = sanitize_key( (string) $k );
			if ( in_array( strtolower( $clean_key ), $sensitive_keys, true ) ) {
				$sanitized[ $clean_key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $v ) ) {
				$sanitized[ $clean_key ] = self::sanitize_context( $v );
			} elseif ( is_string( $v ) ) {
				$sanitized[ $clean_key ] = self::sanitize_text( $v );
			} else {
				$sanitized[ $clean_key ] = $v;
			}
		}

		return $sanitized;
	}

	/**
	 * Retrieves bounded activity log entries with filtering and pagination.
	 *
	 * @param array $args Filter options: search, level, job_id, limit, offset.
	 * @return array Array with 'items' and 'total'.
	 */
	public static function get_events( $args = array() ) {
		$defaults = array(
			'search' => '',
			'level'  => '',
			'job_id' => '',
			'limit'  => 50,
			'offset' => 0,
		);
		$r = wp_parse_args( $args, $defaults );

		$entries = self::read_entries();

		// Apply filters.
		$filtered = array();
		$search   = strtolower( trim( (string) $r['search'] ) );
		$level    = strtolower( trim( (string) $r['level'] ) );
		$job_id   = trim( (string) $r['job_id'] );

		foreach ( $entries as $entry ) {
			if ( ! empty( $level ) && 'all' !== $level && ( $entry['level'] ?? '' ) !== $level ) {
				continue;
			}

			if ( ! empty( $job_id ) && ( $entry['job_id'] ?? '' ) !== $job_id ) {
				continue;
			}

			if ( ! empty( $search ) ) {
				$haystack = strtolower( ( $entry['message'] ?? '' ) . ' ' . ( $entry['action'] ?? '' ) . ' ' . ( $entry['job_id'] ?? '' ) );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}

			$filtered[] = $entry;
		}

		$total = count( $filtered );
		$limit = max( 1, min( 200, (int) $r['limit'] ) );
		$offset = max( 0, (int) $r['offset'] );
		$items = array_slice( $filtered, $offset, $limit );

		return array(
			'items'  => $items,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		);
	}

	/**
	 * Retrieves the most recent N activity entries for the dashboard.
	 *
	 * @param int $count Number of entries.
	 * @return array
	 */
	public static function get_recent( $count = 5 ) {
		$res = self::get_events( array( 'limit' => $count, 'offset' => 0 ) );
		return $res['items'];
	}

	/**
	 * Clears all activity log entries.
	 *
	 * @return bool
	 */
	public static function clear() {
		$path = self::get_log_path();
		if ( file_exists( $path ) ) {
			return false !== file_put_contents( $path, '[]', LOCK_EX );
		}
		return true;
	}

	/**
	 * Returns the absolute path to the activity log file.
	 *
	 * @return string
	 */
	protected static function get_log_path() {
		return migrate_lift_private_dir( self::LOG_FILE );
	}

	/**
	 * Reads entries from the log file.
	 *
	 * @return array
	 */
	protected static function read_entries() {
		$path = self::get_log_path();
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$data = json_decode( (string) @file_get_contents( $path ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Atomically appends an entry to the log file, enforcing max capacity.
	 *
	 * @param array $entry Log entry.
	 */
	protected static function append_entry( $entry ) {
		$path = self::get_log_path();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$entries = self::read_entries();

		// Prepend new entry so newest is always first.
		array_unshift( $entries, $entry );

		// Bound capacity.
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}

		$payload = wp_json_encode( $entries );
		@file_put_contents( $path, $payload, LOCK_EX );
	}
}
