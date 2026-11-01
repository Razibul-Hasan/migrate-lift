<?php
/**
 * Shared helpers: storage folder, job state, time budget, raw MySQL access and file moves.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/** Imported tables are created with this prefix and renamed at the very end. */
define( 'MIGRATE_LIFT_TMP_PREFIX', 'mltmp_' );

/** The replaced tables are parked under this prefix until cleanup drops them. */
define( 'MIGRATE_LIFT_OLD_PREFIX', 'mlold_' );

/** Stands in for the table prefix inside database.sql. */
define( 'MIGRATE_LIFT_TOKEN', '__MIGRATE_LIFT_PREFIX__' );

/** Folder name inside wp-content that holds archives and temporary files. */
define( 'MIGRATE_LIFT_STORE', 'migrate-lift-files' );

/**
 * Returns the storage folder (or a path inside it), creating and locking it down on first use.
 *
 * @param string $sub Optional relative path.
 * @return string
 */
if ( ! function_exists( 'migrate_lift_dir' ) ) {
	function migrate_lift_dir( $sub = '' ) {
		$dir = untrailingslashit( (string) constant( 'WP_CONTENT_DIR' ) ) . '/' . MIGRATE_LIFT_STORE;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Protected storage directory lockdown file.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Protected storage directory lockdown file.
			file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Protected storage directory lockdown file.
			file_put_contents( $dir . '/web.config', "<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n" );
		}

		return '' === $sub ? $dir : $dir . '/' . $sub;
	}
}

/**
 * Reads a job state. States are stored as PHP files that exit at once, so a browser never sees them.
 *
 * @param string $job "export" or "import".
 * @return array|null
 */
function migrate_lift_state_get( $job ) {
	$file = migrate_lift_dir( $job . '-state.php' );
	if ( ! file_exists( $file ) ) {
		return null;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	$raw  = (string) file_get_contents( $file );
	$data = json_decode( substr( $raw, strpos( $raw, "\n" ) + 1 ), true );

	return is_array( $data ) ? $data : null;
}

/**
 * Saves a job state.
 *
 * @param string $job   Job name.
 * @param array  $state State.
 */
function migrate_lift_state_put( $job, $state ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$ok = file_put_contents( migrate_lift_dir( $job . '-state.php' ), "<?php exit; ?>\n" . wp_json_encode( $state ), LOCK_EX );
	if ( false === $ok ) {
		throw new Exception( esc_html__( 'Could not write to wp-content/migrate-lift-files. Check folder permissions.', 'migrate-lift' ) );
	}
}

/**
 * Deletes a job state.
 *
 * @param string $job Job name.
 */
function migrate_lift_state_delete( $job ) {
	$file = migrate_lift_dir( $job . '-state.php' );
	if ( file_exists( $file ) ) {
		wp_delete_file( $file );
	}
}

/**
 * Starts the per-request time budget. Every step stops a little before the server would kill it
 * and the browser calls it again, so big sites work on hosts with short time limits.
 */
function migrate_lift_timer_start() {
	if ( function_exists( 'set_time_limit' ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		@set_time_limit( 300 );
	}
	if ( function_exists( 'ignore_user_abort' ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
	}

	$max    = (int) ini_get( 'max_execution_time' );
	$budget = 20;
	if ( $max > 0 && $max < 45 ) {
		$budget = max( 5, (int) floor( $max / 2 ) );
	}

	$GLOBALS['migrate_lift_deadline'] = microtime( true ) + (float) apply_filters( 'migrate_lift_step_seconds', $budget );
}

/**
 * Whether the current step has used its time budget.
 *
 * @return bool
 */
function migrate_lift_out_of_time() {
	return microtime( true ) >= ( $GLOBALS['migrate_lift_deadline'] ?? ( microtime( true ) + 20 ) );
}

/**
 * Builds a step response.
 *
 * @param int    $progress Percent.
 * @param string $message  Status text.
 * @param array  $extra    Extra fields.
 * @return array
 */
function migrate_lift_progress( $progress, $message, $extra = array() ) {
	return array_merge(
		array(
			'done'     => false,
			'progress' => max( 0, min( 100, (int) $progress ) ),
			'message'  => $message,
		),
		$extra
	);
}

/**
 * Returns the raw mysqli link. wpdb's own query() rewrites some queries, so the dump and import bypass it.
 *
 * @return mysqli
 */
function migrate_lift_db() {
	global $wpdb;
	if ( empty( $wpdb->dbh ) && method_exists( $wpdb, 'db_connect' ) ) {
		$wpdb->db_connect();
	}
	if ( ! $wpdb->dbh instanceof mysqli ) {
		throw new Exception( esc_html__( 'Migrate Lift needs the mysqli PHP extension.', 'migrate-lift' ) );
	}
	return $wpdb->dbh;
}

/**
 * Runs a query and throws on error.
 *
 * @param string $sql SQL.
 * @return mysqli_result|bool
 */
function migrate_lift_q( $sql ) {
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query
	$result = mysqli_query( migrate_lift_db(), $sql );
	if ( false === $result ) {
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error
		throw new Exception( esc_html( 'Database error: ' . mysqli_error( migrate_lift_db() ) . ' in: ' . substr( $sql, 0, 200 ) ) );
	}
	return $result;
}

/**
 * Quotes a value for SQL.
 *
 * @param string|null $value Value.
 * @return string
 */
function migrate_lift_e( $value ) {
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
	return null === $value ? 'NULL' : "'" . mysqli_real_escape_string( migrate_lift_db(), (string) $value ) . "'";
}

/**
 * Returns the first column of a query.
 *
 * @param string $sql SQL.
 * @return array
 */
function migrate_lift_col( $sql ) {
	$result = migrate_lift_q( $sql );
	$out    = array();
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_row, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
	while ( $row = mysqli_fetch_row( $result ) ) {
		$out[] = $row[0];
	}
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_free_result
	mysqli_free_result( $result );
	return $out;
}

/**
 * Session settings for dump and import: full UTF-8, no strict mode (WordPress has zero dates), no FK checks.
 */
function migrate_lift_db_session() {
	migrate_lift_q( 'SET NAMES utf8mb4' );
	migrate_lift_q( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
	migrate_lift_q( 'SET foreign_key_checks = 0' );
}

/**
 * Lists base tables that start with a prefix.
 *
 * @param string $prefix Prefix.
 * @return array
 */
function migrate_lift_tables( $prefix ) {
	global $wpdb;
	$like = migrate_lift_e( $wpdb->esc_like( $prefix ) . '%' );
	return migrate_lift_col( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE $like ORDER BY TABLE_NAME" );
}

/**
 * Column details of a table.
 *
 * @param string $table Table.
 * @return array cols, bin (hex-encode on dump), text (search-replace targets), pk.
 */
function migrate_lift_table_info( $table ) {
	$info   = array(
		'cols' => array(),
		'bin'  => array(),
		'text' => array(),
		'pk'   => array(),
	);
	$result = migrate_lift_q( 'SHOW COLUMNS FROM ' . migrate_lift_name( $table ) );
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_assoc, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
	while ( $c = mysqli_fetch_assoc( $result ) ) {
		if ( false !== stripos( (string) $c['Extra'], 'GENERATED' ) ) {
			continue; // Generated columns can't be inserted.
		}
		$type           = strtolower( $c['Type'] );
		$info['cols'][] = $c['Field'];
		$info['bin'][]  = (bool) preg_match( '/^((tiny|medium|long)?blob|(var)?binary|bit)/', $type );
		$info['text'][] = (bool) preg_match( '/^((var)?char|(tiny|medium|long)?text|json)/', $type );
		if ( 'PRI' === $c['Key'] ) {
			$info['pk'][] = $c['Field'];
		}
	}
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_free_result
	mysqli_free_result( $result );
	return $info;
}

/**
 * Backtick-quotes a table or column name.
 *
 * @param string $name Name.
 * @return string
 */
function migrate_lift_name( $name ) {
	return '`' . str_replace( '`', '``', $name ) . '`';
}

/**
 * Lists a folder without dot entries.
 *
 * @param string $dir Folder.
 * @return array
 */
function migrate_lift_ls( $dir ) {
	$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	return $items ? array_values( array_diff( $items, array( '.', '..' ) ) ) : array();
}

/**
 * Deletes a file or folder tree within the time budget.
 *
 * @param string $path Path.
 * @return bool True when it is gone, false when time ran out first.
 */
function migrate_lift_rmdir( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		wp_delete_file( $path );
		return true;
	}
	if ( ! is_dir( $path ) ) {
		return true;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	$count = 0;
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $item->getPathname() );
		} else {
			wp_delete_file( $item->getPathname() );
		}
		if ( 0 === ++$count % 200 && migrate_lift_out_of_time() ) {
			return false;
		}
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $path );
	return ! file_exists( $path );
}

/**
 * Moves a file or folder, copying when a plain rename is not possible.
 *
 * @param string $from Source.
 * @param string $to   Target.
 * @return bool
 */
function migrate_lift_move( $from, $to ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
	if ( @rename( $from, $to ) ) {
		return true;
	}
	if ( is_file( $from ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
		return @copy( $from, $to ) && wp_delete_file( $from );
	}
	if ( ! is_dir( $from ) || ! wp_mkdir_p( $to ) ) {
		return false;
	}
	foreach ( migrate_lift_ls( $from ) as $item ) {
		if ( ! migrate_lift_move( "$from/$item", "$to/$item" ) ) {
			return false;
		}
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	return @rmdir( $from );
}

/**
 * Cuts a file back to the size recorded in the job state. If a step was killed halfway,
 * this drops its half-written bytes before the step runs again.
 *
 * @param string $file File.
 * @param int    $size Size.
 */
function migrate_lift_truncate( $file, $size ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$handle = fopen( $file, 'cb' );
	if ( ! $handle ) {
		throw new Exception( esc_html( 'Could not open ' . basename( $file ) . ' for writing.' ) );
	}
	ftruncate( $handle, (int) $size );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $handle );
}

/**
 * Resolves an archive name to its path in the storage folder.
 *
 * @param string $name File name.
 * @return string
 */
function migrate_lift_archive_path( $name ) {
	$name = basename( (string) $name );
	if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(tgz|tar\.gz|zip)$/i', $name ) || ! is_file( migrate_lift_dir( $name ) ) ) {
		throw new Exception( esc_html__( 'Archive not found.', 'migrate-lift' ) );
	}
	return migrate_lift_dir( $name );
}

/**
 * Checks whether an archive is designated as a protected recovery point or in active use.
 *
 * @param string $name Archive filename.
 * @return bool True if protected from deletion or overwrite.
 */
function migrate_lift_archive_is_protected( $name ) {
	$name = sanitize_file_name( (string) $name );
	if ( empty( $name ) ) {
		return false;
	}

	// 1. Single designated recovery point.
	$recovery_point = get_option( 'migrate_lift_recovery_point' );
	if ( ! empty( $recovery_point ) && $recovery_point === $name ) {
		return true;
	}

	// 2. Recovery checkpoints list.
	$checkpoints = get_option( 'migrate_lift_recovery_checkpoints', array() );
	if ( is_array( $checkpoints ) ) {
		foreach ( $checkpoints as $cp ) {
			if ( ( is_array( $cp ) && isset( $cp['archive'] ) && $cp['archive'] === $name ) || $cp === $name ) {
				return true;
			}
		}
	}

	// 3. Archive metadata protected flag.
	$meta = get_option( 'migrate_lift_backup_metadata', array() );
	if ( is_array( $meta ) && ! empty( $meta[ $name ]['protected'] ) ) {
		return true;
	}

	return false;
}

/**
 * Lists the archives in the storage folder, newest first.
 *
 * @return array
 */
function migrate_lift_archives() {
	$out  = array();
	$meta = get_option( 'migrate_lift_backup_metadata', array() );
	if ( ! is_array( $meta ) ) {
		$meta = array();
	}

	foreach ( migrate_lift_ls( migrate_lift_dir() ) as $name ) {
		if ( ! preg_match( '/\.(tgz|tar\.gz|zip)$/i', $name ) ) {
			continue;
		}
		$path      = migrate_lift_dir( $name );
		$size      = (int) filesize( $path );
		$item_meta = isset( $meta[ $name ] ) && is_array( $meta[ $name ] ) ? $meta[ $name ] : array();
		$mtime     = isset( $item_meta['time'] ) ? (int) $item_meta['time'] : (int) filemtime( $path );

		$format      = preg_match( '/\.zip$/i', $name ) ? 'zip' : 'tgz';
		$is_verified = ! empty( $item_meta['verified'] );
		$status      = $item_meta['status'] ?? ( $is_verified ? 'ready' : 'unverified' );

		$out[] = array(
			'name'                 => $name,
			'file'                 => $name,
			'size'                 => $size,
			'raw_estimate'         => isset( $item_meta['raw_estimate'] ) ? (int) $item_meta['raw_estimate'] : $size,
			'reduction_percentage' => isset( $item_meta['reduction_percentage'] ) ? (float) $item_meta['reduction_percentage'] : 0.0,
			'time'                 => $mtime,
			'date'                 => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mtime ),
			'download'             => migrate_lift_download_url( $name ),
			'type'                 => $item_meta['type'] ?? 'full',
			'storage_source'       => 'local',
			'verified'             => $is_verified,
			'verified_at'          => $item_meta['verified_at'] ?? null,
			'status'               => $status,
			'format'               => $format,
			'checksum'             => $item_meta['checksum'] ?? null,
			'is_protected'         => migrate_lift_archive_is_protected( $name ),
		);
	}
	usort(
		$out,
		function ( $a, $b ) {
			return $b['time'] - $a['time'];
		}
	);
	return $out;
}

/**
 * Returns comprehensive details for a single archive.
 *
 * @param string $name File name.
 * @return array
 * @throws Exception When archive does not exist.
 */
function migrate_lift_get_archive_details( $name ) {
	$path  = migrate_lift_archive_path( $name );
	$size  = (int) filesize( $path );
	$mtime = (int) filemtime( $path );

	$meta = get_option( 'migrate_lift_backup_metadata', array() );
	if ( ! is_array( $meta ) ) {
		$meta = array();
	}
	$item_meta = isset( $meta[ $name ] ) && is_array( $meta[ $name ] ) ? $meta[ $name ] : array();

	// If manifest is missing or verification never ran, inspect archive now.
	$manifest = $item_meta['manifest'] ?? null;
	$report   = null;

	if ( empty( $manifest ) && class_exists( 'Migrate_Lift_Backup_Engine' ) ) {
		$report = Migrate_Lift_Backup_Engine::verify_archive_file( $path );
		if ( ! empty( $report['valid'] ) && ! empty( $report['manifest'] ) ) {
			$manifest = $report['manifest'];
			$item_meta['manifest']    = $manifest;
			$item_meta['verified']    = true;
			$item_meta['verified_at'] = time();
			$item_meta['status']      = 'ready';
			if ( ! empty( $manifest['database_sections']['sql_sha256'] ) ) {
				$item_meta['checksum'] = $manifest['database_sections']['sql_sha256'];
			}
			if ( ! empty( $manifest['bytes'] ) ) {
				$item_meta['raw_estimate'] = (int) $manifest['bytes'];
			}
			$meta[ $name ] = $item_meta;
			update_option( 'migrate_lift_backup_metadata', $meta, false );
		}
	}

	$store_dir   = migrate_lift_dir();
	$free_space  = function_exists( 'disk_free_space' ) ? @disk_free_space( $store_dir ) : null;
	$total_space = function_exists( 'disk_total_space' ) ? @disk_total_space( $store_dir ) : null;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	$is_writable = function_exists( 'wp_is_writable' ) ? wp_is_writable( $store_dir ) : is_writable( $store_dir );

	$format      = preg_match( '/\.zip$/i', $name ) ? 'zip' : 'tgz';
	$is_verified = ! empty( $item_meta['verified'] );
	$status      = $item_meta['status'] ?? ( $is_verified ? 'ready' : 'unverified' );

	return array(
		'name'                 => $name,
		'file'                 => $name,
		'size'                 => $size,
		'raw_estimate'         => isset( $item_meta['raw_estimate'] ) ? (int) $item_meta['raw_estimate'] : ( $manifest['bytes'] ?? $size ),
		'reduction_percentage' => isset( $item_meta['reduction_percentage'] ) ? (float) $item_meta['reduction_percentage'] : ( $manifest['compression']['reduction_percentage'] ?? 0.0 ),
		'time'                 => $mtime,
		'date'                 => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mtime ),
		'download'             => migrate_lift_download_url( $name ),
		'type'                 => $item_meta['type'] ?? 'full',
		'storage_source'       => 'local',
		'storage_path'         => defined( 'MIGRATE_LIFT_STORE' ) ? MIGRATE_LIFT_STORE : 'migrate-lift-files',
		'verified'             => $is_verified,
		'verified_at'          => $item_meta['verified_at'] ?? null,
		'status'               => $status,
		'format'               => $format,
		'checksum'             => $item_meta['checksum'] ?? ( $manifest['database_sections']['sql_sha256'] ?? null ),
		'is_protected'         => migrate_lift_archive_is_protected( $name ),
		'manifest'             => $manifest,
		'errors'               => $item_meta['errors'] ?? ( $report['errors'] ?? array() ),
		'storage'              => array(
			'directory'       => defined( 'MIGRATE_LIFT_STORE' ) ? MIGRATE_LIFT_STORE : 'migrate-lift-files',
			'writable'        => $is_writable,
			'disk_free_bytes' => false !== $free_space ? (int) $free_space : null,
			'disk_total_bytes'=> false !== $total_space ? (int) $total_space : null,
		),
	);
}

/**
 * Admin download link for an archive.
 *
 * @param string $name File name.
 * @return string
 */
if ( ! function_exists( 'migrate_lift_download_url' ) ) {
	function migrate_lift_download_url( $name ) {
		return add_query_arg(
			array(
				'action'   => 'migrate_lift_download',
				'file'     => rawurlencode( $name ),
				'_wpnonce' => wp_create_nonce( 'migrate_lift_download' ),
			),
			admin_url( 'admin-post.php' )
		);
	}
}

/**
 * Facts about this site, stored in the export manifest and in the import state.
 *
 * @return array
 */
function migrate_lift_site_info() {
	global $wpdb;
	return array(
		'home'    => untrailingslashit( get_option( 'home' ) ),
		'siteurl' => untrailingslashit( get_option( 'siteurl' ) ),
		'abspath' => ABSPATH,
		'prefix'  => $wpdb->prefix,
		'plugin'  => plugin_basename( MIGRATE_LIFT_FILE ),
		'wp'      => get_bloginfo( 'version' ),
		'php'     => PHP_VERSION,
		'mysql'   => $wpdb->db_version(),
	);
}
