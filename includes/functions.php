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
 * Returns the wp-content folder this site really uses, without a trailing slash.
 *
 * A migration has to read and replace the whole of wp-content, including when it has been moved
 * in wp-config.php. WordPress has no function that returns this path, so this is the single place
 * that reads the constant.
 *
 * @return string
 */
function migrate_lift_content_dir() {
	return untrailingslashit( WP_CONTENT_DIR );
}

/**
 * Returns the folder WordPress is installed in, with a trailing slash.
 *
 * Core reads .maintenance from here, and imports use it to rewrite old server paths. get_home_path()
 * is a different folder when WordPress lives in a subdirectory, so this is the single place that
 * reads the constant.
 *
 * @return string
 */
function migrate_lift_root_dir() {
	return ABSPATH;
}

/**
 * Returns the path of a working folder inside storage. The folder name ends in a random value kept
 * on disk, so on servers that ignore .htaccess its contents cannot be found by URL. It is kept on
 * disk rather than in the database because an import replaces the database part way through.
 *
 * @param string $name Base name of the folder.
 * @param string $sub  Optional relative path inside the folder.
 * @return string
 */
function migrate_lift_tmp( $name, $sub = '' ) {
	static $key = null;
	if ( null === $key ) {
		$file = migrate_lift_dir( 'storage-key.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$raw = is_file( $file ) ? (string) file_get_contents( $file ) : '';
		$key = trim( (string) substr( $raw, (int) strpos( $raw, "\n" ) + 1 ) );
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $key ) ) {
			$key = wp_generate_password( 32, false, false );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, "<?php exit; ?>\n" . $key, LOCK_EX );
		}
	}
	return migrate_lift_dir( $name . '-' . $key . ( '' === $sub ? '' : '/' . $sub ) );
}

/**
 * Returns the storage folder (or a path inside it), creating and locking it down on first use.
 *
 * @param string $sub Optional relative path.
 * @return string
 */
if ( ! function_exists( 'migrate_lift_dir' ) ) {
	function migrate_lift_dir( $sub = '' ) {
		$dir = migrate_lift_content_dir() . '/' . MIGRATE_LIFT_STORE;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Protected storage directory lockdown file.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Protected storage directory lockdown file.
			file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
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
 * Runs a statement through wpdb and throws on error. Errors are thrown rather than printed, so
 * they cannot break the JSON response of a running step.
 *
 * Identifiers in $sql are quoted with migrate_lift_name() and values with migrate_lift_e().
 *
 * @param string $sql SQL.
 * @return int|bool
 */
function migrate_lift_q( $sql ) {
	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Whole-site dump and restore on dynamic tables; see the docblock.
	$result = $wpdb->query( $sql );
	$wpdb->suppress_errors( $suppress );
	if ( false === $result ) {
		throw new Exception( esc_html( 'Database error: ' . $wpdb->last_error . ' in: ' . substr( $sql, 0, 200 ) ) );
	}
	return $result;
}

/**
 * Returns all rows of a query and throws on error. wpdb's buffers are released straight away,
 * so a batch of large rows is only held in memory once while it is processed.
 *
 * @param string $sql    SQL.
 * @param string $output ARRAY_N (values in column order) or ARRAY_A.
 * @return array
 */
function migrate_lift_rows( $sql, $output = ARRAY_N ) {
	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Whole-site dump and restore on dynamic tables; see migrate_lift_q().
	$rows  = $wpdb->get_results( $sql, $output );
	$error = $wpdb->last_error;
	$wpdb->suppress_errors( $suppress );
	$wpdb->flush();
	if ( '' !== $error ) {
		throw new Exception( esc_html( 'Database error: ' . $error . ' in: ' . substr( $sql, 0, 200 ) ) );
	}
	return (array) $rows;
}

/**
 * Chooses the row count for the next dump batch. It grows while rows are small and shrinks when
 * they are large, because a batch has to fit in PHP memory on hosts with low limits.
 *
 * @param int $batch Size of the batch just read.
 * @param int $bytes Bytes that batch produced.
 * @param int $max   Largest allowed batch.
 * @return int
 */
function migrate_lift_next_batch( $batch, $bytes, $max ) {
	if ( $bytes > 4 * MB_IN_BYTES ) {
		return max( 10, (int) floor( $batch / 2 ) );
	}
	return min( $max, $batch * 2 );
}

/**
 * Quotes a value for SQL. The result is also written to database.sql, where wpdb's placeholder
 * escape for % would corrupt the data, so that escape is removed here.
 *
 * @param string|null $value Value.
 * @return string
 */
function migrate_lift_e( $value ) {
	global $wpdb;
	return null === $value ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( esc_sql( (string) $value ) ) . "'";
}

/**
 * Writes a BIT value as a SQL literal. The mysqlnd driver returns BIT as a decimal string such as
 * "165", which has to stay a plain number; other drivers return the raw bytes.
 *
 * @param string $value Value read from a BIT column.
 * @return string
 */
function migrate_lift_bit_literal( $value ) {
	return ctype_digit( (string) $value ) ? (string) $value : '0x' . bin2hex( $value );
}

/**
 * Returns the first column of a query.
 *
 * @param string $sql SQL.
 * @return array
 */
function migrate_lift_col( $sql ) {
	$out = array();
	foreach ( migrate_lift_rows( $sql ) as $row ) {
		$out[] = $row[0];
	}
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
	$tables = migrate_lift_col(
		$wpdb->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);

	// A prefix such as wp_ also matches wp_shop_options. Those tables belong to another WordPress
	// site sharing this database, and a backup or restore here must leave them alone.
	foreach ( $tables as $table ) {
		if ( preg_match( '/^(.+_)options$/', $table, $match ) && $match[1] !== $prefix && in_array( $match[1] . 'posts', $tables, true ) ) {
			$other  = $match[1];
			$tables = array_values(
				array_filter(
					$tables,
					function ( $name ) use ( $other ) {
						return 0 !== strpos( $name, $other );
					}
				)
			);
		}
	}

	return $tables;
}

/**
 * Column details of a table.
 *
 * @param string $table Table.
 * @return array cols, bin (hex-encode on dump), bit (see migrate_lift_bit_literal()), text (search-replace targets), pk.
 */
function migrate_lift_table_info( $table ) {
	$info = array(
		'cols' => array(),
		'bin'  => array(),
		'bit'  => array(),
		'text' => array(),
		'pk'   => array(),
	);
	foreach ( migrate_lift_rows( 'SHOW COLUMNS FROM ' . migrate_lift_name( $table ), ARRAY_A ) as $c ) {
		if ( false !== stripos( (string) $c['Extra'], 'GENERATED' ) ) {
			continue; // Generated columns can't be inserted.
		}
		$type           = strtolower( $c['Type'] );
		$info['cols'][] = $c['Field'];
		$info['bin'][]  = (bool) preg_match( '/^((tiny|medium|long)?blob|(var)?binary)/', $type );
		$info['bit'][]  = 0 === strpos( $type, 'bit' );
		$info['text'][] = (bool) preg_match( '/^((var)?char|(tiny|medium|long)?text|json)/', $type );
		if ( 'PRI' === $c['Key'] ) {
			$info['pk'][] = $c['Field'];
		}
	}
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
 * Whether a name is a plain archive file name, with no path and no other file type.
 *
 * @param string $name File name.
 * @return bool
 */
function migrate_lift_is_archive_name( $name ) {
	return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(tgz|tar\.gz|zip)$/i', (string) $name );
}

/**
 * Resolves an archive name to its path in the storage folder.
 *
 * @param string $name File name.
 * @return string
 */
function migrate_lift_archive_path( $name ) {
	$name = basename( (string) $name );
	if ( ! migrate_lift_is_archive_name( $name ) || ! is_file( migrate_lift_dir( $name ) ) ) {
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
		'abspath' => migrate_lift_root_dir(),
		'prefix'  => $wpdb->prefix,
		'plugin'  => plugin_basename( MIGRATE_LIFT_FILE ),
		'wp'      => get_bloginfo( 'version' ),
		'php'     => PHP_VERSION,
		'mysql'   => $wpdb->db_version(),
	);
}
