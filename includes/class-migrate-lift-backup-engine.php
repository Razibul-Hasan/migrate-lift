<?php
/**
 * Full Site Backup Engine.
 *
 * Implements the Backup Engine using the resumable Job Engine framework:
 * - Format version 2.0 with verifiable manifest schema
 * - Allowlisted root file scanning with symlink traversal and unreadable file protection
 * - Rule-based granular exclusions (caches, logs, other backup folders)
 * - Bounded chunked database export with prefix tokenization and consistency disclaimer
 * - Web-server protected private staging and atomic finalization
 * - Strict credential and secret omission (no wp-config.php secrets, no salts, no DB passwords)
 * - Archive integrity verification via streaming Tar Reader
 * - Automatic staging cleanup on failure or cancellation
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrate_Lift_Backup_Engine
 */
class Migrate_Lift_Backup_Engine {

	/**
	 * Backup format version.
	 */
	const FORMAT_VERSION = '2.0';

	/**
	 * Minimum required free disk space in bytes (20 MB).
	 */
	const MIN_FREE_DISK_SPACE = 20971520;

	/**
	 * Database batch size (rows per step).
	 */
	const DB_BATCH_SIZE = 500;

	/**
	 * Maximum file chunk read size (1 MB).
	 */
	const FILE_CHUNK_SIZE = 1048576;

	/**
	 * Registers the backup handler with the job engine.
	 */
	public static function init() {
		if ( class_exists( 'Migrate_Lift_Job_Engine' ) ) {
			Migrate_Lift_Job_Engine::register_handler( 'backup', array( __CLASS__, 'handle_backup_step' ) );
		}
	}

	/**
	 * Checks if a relative path inside wp-content is excluded.
	 *
	 * @param string $rel Relative path to wp-content with forward slashes.
	 * @return bool True if excluded.
	 */
	public static function is_excluded( $rel ) {
		if ( class_exists( 'Migrate_Lift_Compression' ) ) {
			return Migrate_Lift_Compression::is_path_excluded( $rel );
		}

		static $rules = null;
		if ( null === $rules ) {
			$store = defined( 'MIGRATE_LIFT_STORE' ) ? MIGRATE_LIFT_STORE : 'migrate-lift-files';
			$rules = apply_filters(
				'migrate_lift_exclude',
				array(
					$store,
					'ai1wm-backups',
					'updraft',
					'backups-dup-lite',
					'wpvividbackups',
					'backwpup-*',
					'cache',
					'upgrade',
					'upgrade-temp-backup',
					'wflogs',
					'litespeed',
					'debug.log',
					'uploads/backwpup*',
					'uploads/wpvivid*',
					'uploads/ai1wm-backups',
					'uploads/siteground-optimizer-assets',
				)
			);
		}

		$basename = basename( $rel );
		$ignored_basenames = array(
			'.git',
			'.svn',
			'node_modules',
			'.DS_Store',
			'Thumbs.db',
			'error_log',
			'php_errorlog',
		);
		if ( in_array( $basename, $ignored_basenames, true ) ) {
			return true;
		}

		// Check each directory segment for ignored directory names (e.g. node_modules, .git).
		$parts = explode( '/', str_replace( '\\', '/', $rel ) );
		foreach ( $parts as $part ) {
			if ( in_array( $part, $ignored_basenames, true ) ) {
				return true;
			}
		}

		// Check each accumulated path prefix against exclusion rules.
		$accum = '';
		foreach ( $parts as $part ) {
			$accum = '' === $accum ? $part : $accum . '/' . $part;
			foreach ( $rules as $rule ) {
				if ( fnmatch( $rule, $accum, FNM_PATHNAME ) || fnmatch( $rule, $rel, FNM_PATHNAME ) || $rule === $accum ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Checks whether a directory is writable cross-platform.
	 *
	 * @param string $dir Directory path.
	 * @return bool True if writable.
	 */
	public static function is_dir_writable( $dir ) {
		if ( function_exists( 'wp_is_writable' ) ) {
			return wp_is_writable( $dir );
		}

		$test_file = rtrim( $dir, '/\\' ) . '/.ml_test_' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$ok = @file_put_contents( $test_file, '1' );
		if ( false !== $ok ) {
			wp_delete_file( $test_file );
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		return is_writable( $dir );
	}

	/**
	 * Core handler for stepping a backup job.
	 *
	 * @param array  $job       Job record.
	 * @param string $worker_id Worker ID.
	 * @return array Step result.
	 * @throws Exception On fatal error.
	 */
	public static function handle_backup_step( $job, $worker_id ) {
		$checkpoint = $job['checkpoint'] ?? array();
		$phase      = $checkpoint['phase'] ?? 'init';

		try {
			switch ( $phase ) {
				case 'init':
					return self::step_init( $job );

				case 'scan':
					return self::step_scan( $job );

				case 'db':
					return self::step_db( $job );

				case 'archive':
					return self::step_archive( $job );

				case 'verify':
					return self::step_verify( $job );

				case 'finalize':
					return self::step_finalize( $job );

				default:
					/* translators: %s: Backup phase name. */
					throw new Exception( esc_html( sprintf( __( 'Unknown backup phase: %s', 'migrate-lift' ), $phase ) ) );
			}
		} catch ( Exception $e ) {
			// Clean up temporary files on fatal failure.
			self::cleanup_job( $job );
			throw $e;
		}
	}

	/**
	 * Phase 1: Preflight checks, staging setup, and initialization.
	 *
	 * @param array $job Job record.
	 * @return array Step result.
	 * @throws Exception On preflight failure.
	 */
	protected static function step_init( &$job ) {
		$storage_dir = migrate_lift_dir();

		if ( ! is_dir( $storage_dir ) && ! wp_mkdir_p( $storage_dir ) ) {
			throw new Exception( esc_html__( 'Could not create protected storage directory. Verify folder permissions.', 'migrate-lift' ) );
		}

		if ( ! self::is_dir_writable( $storage_dir ) ) {
			throw new Exception( esc_html__( 'Storage directory is not writable. Verify folder permissions.', 'migrate-lift' ) );
		}

		// Check available disk space.
		if ( function_exists( 'disk_free_space' ) ) {
			$free_space = @disk_free_space( $storage_dir );
			if ( false !== $free_space && $free_space < self::MIN_FREE_DISK_SPACE ) {
				throw new Exception(
					esc_html( sprintf(
						/* translators: 1: Available free disk space, 2: Required disk space. */
						__( 'Insufficient disk space in storage directory (%1$s free, at least %2$s required).', 'migrate-lift' ),
						size_format( $free_space ),
						size_format( self::MIN_FREE_DISK_SPACE )
					) )
				);
			}
		}

		// Ensure web server protection files exist.
		if ( ! file_exists( $storage_dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Protected storage directory lockdown file.
			file_put_contents( $storage_dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $storage_dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Protected storage directory lockdown file.
			file_put_contents(
				$storage_dir . '/.htaccess',
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}

		// Resolve compression mode, format and settings.
		$compression_settings = class_exists( 'Migrate_Lift_Compression' ) ? Migrate_Lift_Compression::get_settings() : array();
		$params               = is_array( $job['params'] ?? null ) ? $job['params'] : array();

		$format = ! empty( $params['format'] ) ? sanitize_key( (string) $params['format'] ) : ( $compression_settings['format'] ?? 'tgz' );
		if ( ! in_array( $format, array( 'tgz', 'zip' ), true ) ) {
			$format = 'tgz';
		}

		$mode = ! empty( $params['mode'] ) ? sanitize_key( (string) $params['mode'] ) : ( $compression_settings['mode'] ?? 'balanced' );
		if ( ! in_array( $mode, array( 'balanced', 'fastest', 'smallest' ), true ) ) {
			$mode = 'balanced';
		}

		$media_bypass = isset( $params['media_bypass'] )
			? (bool) $params['media_bypass']
			: (bool) ( $compression_settings['media_bypass'] ?? true );

		$deflate_level = class_exists( 'Migrate_Lift_Compression' )
			? Migrate_Lift_Compression::get_deflate_level( $mode )
			: 6;

		// Generate random staging and archive filenames.
		$host_slug = 'site';
		if ( function_exists( 'home_url' ) ) {
			$parsed_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $parsed_host ) {
				$host_slug = sanitize_title( (string) $parsed_host );
			}
		}
		$rand_token   = strtolower( wp_generate_password( 12, false, false ) );
		$ext          = ( 'zip' === $format ) ? 'zip' : 'tgz';
		$archive_name = sprintf( '%s-%s-%s.%s', $host_slug, wp_date( 'Ymd-His' ), $rand_token, $ext );
		$part_file    = $storage_dir . '/' . $archive_name . '.part';
		$staging_dir  = $storage_dir . '/staging-' . $job['id'];

		if ( ! is_dir( $staging_dir ) ) {
			wp_mkdir_p( $staging_dir );
		}

		$job['checkpoint'] = array(
			'phase'          => 'scan',
			'staging_dir'    => $staging_dir,
			'archive_name'   => $archive_name,
			'part_file'      => $part_file,
			'files_count'    => 0,
			'files_bytes'    => 0,
			'warnings'       => array(),
			'compression'    => array(
				'mode'         => $mode,
				'format'       => $format,
				'level'        => $deflate_level,
				'media_bypass' => $media_bypass,
			),
			'db'             => array(
				'tables'      => array(),
				'table_index' => 0,
				'row_offset'  => 0,
				'sql_bytes'   => 0,
				'total_rows'  => 0,
				'dump_init'   => false,
			),
			'archive'        => array(
				'arc_bytes_done' => 0,
				'list_pos'       => 0,
				'manifest_done'  => false,
				'cur'            => null,
			),
		);

		$job['phase']             = 'scan';
		$job['percentage']        = 5;
		$job['diagnostic_detail'] = __( 'Preflight passed. Beginning allowlisted file scan…', 'migrate-lift' );

		Migrate_Lift_Activity_Logger::info(
			'backup_preflight_passed',
			sprintf( 'Backup preflight checks passed. Target archive: %s', $archive_name ),
			array( 'job_id' => $job['id'], 'archive' => $archive_name ),
			$job['id']
		);

		return array(
			'done'    => false,
			'job'     => $job,
			'message' => __( 'Preflight passed. Scanning files…', 'migrate-lift' ),
		);
	}

	/**
	 * Phase 2: Scans allowlisted files, identifies excluded files, unreadable files and symlinks outside root.
	 *
	 * @param array $job Job record.
	 * @return array Step result.
	 */
	protected static function step_scan( &$job ) {
		$base_dir = str_replace( '\\', '/', migrate_lift_content_dir() );
		$cut      = strlen( $base_dir ) + 1;
		$staging  = $job['checkpoint']['staging_dir'];
		$list_file = $staging . '/files.txt';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $list_file, 'wb' );
		if ( ! $fp ) {
			throw new Exception( esc_html__( 'Could not create scan file list in staging directory.', 'migrate-lift' ) );
		}

		$files_count = 0;
		$files_bytes = 0;
		$warnings    = array();

		if ( is_dir( $base_dir ) ) {
			$iterator = new RecursiveDirectoryIterator(
				$base_dir,
				FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
			);

			$filter = new RecursiveCallbackFilterIterator(
				$iterator,
				function ( $item ) use ( $cut, &$warnings, $base_dir ) {
					$pathname = str_replace( '\\', '/', $item->getPathname() );
					$rel      = substr( $pathname, $cut );

					// Exclusions check.
					if ( self::is_excluded( $rel ) ) {
						return false;
					}

					// Symlink outside root check: do NOT follow symlinks pointing outside WP_CONTENT_DIR.
					if ( $item->isLink() ) {
						$target = @realpath( $item->getPathname() );
						if ( false === $target || 0 !== strpos( str_replace( '\\', '/', $target ), $base_dir ) ) {
							$warnings[] = sprintf( 'Skipped symlink "%s" pointing outside root.', $rel );
							return false;
						}
					}

					return true;
				}
			);

			$leaves = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );

			foreach ( $leaves as $item ) {
				$pathname = str_replace( '\\', '/', $item->getPathname() );
				$rel      = substr( $pathname, $cut );

				if ( ! $item->isFile() || false !== strpos( $rel, "\n" ) ) {
					continue;
				}

				if ( ! is_readable( $pathname ) ) {
					$warnings[] = sprintf( 'Unreadable file "%s" was skipped.', $rel );
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $fp, $rel . "\n" );
				++$files_count;
				$files_bytes += (int) $item->getSize();
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		// Query database tables to export.
		$tables = self::get_database_tables();

		$job['checkpoint']['files_count'] = $files_count;
		$job['checkpoint']['files_bytes'] = $files_bytes;
		$job['checkpoint']['warnings']    = $warnings;
		$job['checkpoint']['db']['tables'] = $tables;
		$job['checkpoint']['phase']       = 'db';

		$job['counts']['items_total']     = $files_count + count( $tables );
		$job['counts']['bytes_total']     = $files_bytes;

		$job['phase']             = 'db';
		$job['percentage']        = 10;
		$message = sprintf(
			'Found %d files (%s) and %d database tables. Exporting database…',
			$files_count,
			size_format( $files_bytes ),
			count( $tables )
		);
		$job['diagnostic_detail'] = $message;

		Migrate_Lift_Activity_Logger::info(
			'backup_scan_completed',
			$message,
			array(
				'job_id'   => $job['id'],
				'files'    => $files_count,
				'bytes'    => $files_bytes,
				'tables'   => count( $tables ),
				'warnings' => count( $warnings ),
			),
			$job['id']
		);

		return array(
			'done'    => false,
			'job'     => $job,
			'message' => $message,
		);
	}

	/**
	 * Phase 3: Dumps database tables in bounded chunks.
	 *
	 * @param array $job Job record.
	 * @return array Step result.
	 */
	protected static function step_db( &$job ) {
		$db_state = &$job['checkpoint']['db'];
		$tables   = $db_state['tables'];
		$staging  = $job['checkpoint']['staging_dir'];
		$sql_path = $staging . '/database.sql';

		// Initialize SQL file header if first db tick.
		if ( empty( $db_state['dump_init'] ) ) {
			$header = "-- MigrateLift Database Backup Archive\n"
				. "-- Format Version: " . self::FORMAT_VERSION . "\n"
				. "-- Generated At: " . gmdate( 'c' ) . "\n"
				. "-- Notice: Database export is performed via bounded non-locking queries for shared-hosting compatibility. Tables modified during export may reflect concurrent changes.\n"
				. "-- Safe restoration setup: Destination database credentials and configuration must be managed independently.\n\n";

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $sql_path, $header );
			$db_state['dump_init'] = true;
			$db_state['sql_bytes'] = strlen( $header );
		}

		if ( function_exists( 'migrate_lift_truncate' ) && file_exists( $sql_path ) ) {
			migrate_lift_truncate( $sql_path, $db_state['sql_bytes'] );
		}

		$is_done = self::dump_tables_chunk( $db_state, $sql_path );

		if ( file_exists( $sql_path ) ) {
			clearstatcache( true, $sql_path );
			$db_state['sql_bytes'] = (int) filesize( $sql_path );
		}

		$tables_count = count( $tables );
		$current_t    = $db_state['table_index'];
		$pct_done     = $tables_count > 0 ? ( $current_t / $tables_count ) : 1;

		$job['percentage'] = 10 + (int) floor( $pct_done * 15 ); // 10% to 25%
		$job['counts']['items_processed'] = $current_t;

		if ( ! $is_done ) {
			$message = sprintf(
				'Exporting database… table %d of %d (%d rows exported)',
				min( $current_t + 1, $tables_count ),
				$tables_count,
				$db_state['total_rows']
			);
			$job['diagnostic_detail'] = $message;

			return array(
				'done'    => false,
				'job'     => $job,
				'message' => $message,
			);
		}

		// Database dump complete.
		$sql_sha256 = file_exists( $sql_path ) ? hash_file( 'sha256', $sql_path ) : '';
		$db_state['sql_sha256'] = $sql_sha256;

		// Build and save the format 2.0 manifest into staging directory.
		$manifest = self::build_manifest( $job );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$staging . '/migratelift.json',
			wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		// Advance to archive compression phase.
		$job['checkpoint']['phase'] = 'archive';
		$job['counts']['bytes_total'] += $db_state['sql_bytes'];
		$job['phase']      = 'archive';
		$job['percentage'] = 25;
		$message = sprintf( 'Database export complete (%s). Compressing archive…', size_format( $db_state['sql_bytes'] ) );
		$job['diagnostic_detail'] = $message;

		Migrate_Lift_Activity_Logger::info(
			'backup_db_exported',
			$message,
			array(
				'job_id'     => $job['id'],
				'tables'     => $tables_count,
				'rows'       => $db_state['total_rows'],
				'sql_size'   => $db_state['sql_bytes'],
				'sql_sha256' => $sql_sha256,
			),
			$job['id']
		);

		return array(
			'done'    => false,
			'job'     => $job,
			'message' => $message,
		);
	}

	/**
	 * Phase 4: Streams manifest, database.sql and files into archive (.tgz or .zip).
	 *
	 * @param array $job Job record.
	 * @return array Step result.
	 */
	protected static function step_archive( &$job ) {
		$arc_state = &$job['checkpoint']['archive'];
		$part_file = $job['checkpoint']['part_file'];
		$staging   = $job['checkpoint']['staging_dir'];
		$list_file = $staging . '/files.txt';
		$comp_info = $job['checkpoint']['compression'] ?? array();
		$format    = $comp_info['format'] ?? 'tgz';
		$level     = (int) ( $comp_info['level'] ?? 6 );
		$media_bypass = (bool) ( $comp_info['media_bypass'] ?? true );

		if ( function_exists( 'migrate_lift_truncate' ) && file_exists( $part_file ) && ! empty( $arc_state['arc_size'] ) ) {
			migrate_lift_truncate( $part_file, $arc_state['arc_size'] );
		}

		$is_zip = ( 'zip' === $format );
		$zip_entries_file = $staging . '/zip_entries.json';
		$zip_entries = array();
		if ( $is_zip && file_exists( $zip_entries_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			$raw_entries = (string) file_get_contents( $zip_entries_file );
			$zip_entries = json_decode( $raw_entries, true );
			if ( ! is_array( $zip_entries ) ) {
				$zip_entries = array();
			}
		}

		$tar = ! $is_zip ? new Migrate_Lift_Tar_Writer( $part_file, $level ) : null;
		$zip = $is_zip ? new Migrate_Lift_Zip_Writer( $part_file ) : null;

		// 1. Add manifests and database.sql on initial archive tick.
		if ( empty( $arc_state['manifest_done'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			$manifest_json = (string) @file_get_contents( $staging . '/migratelift.json' );
			if ( $is_zip ) {
				$zip_entries[] = $zip->add_string( 'migratelift.json', $manifest_json, true, $level );
			} else {
				$tar->add_string( 'migratelift.json', $manifest_json );
			}

			$sql_file = $staging . '/database.sql';
			if ( file_exists( $sql_file ) ) {
				$arc_state['cur'] = array(
					'abs'      => $sql_file,
					'name'     => 'database.sql',
					'size'     => filesize( $sql_file ),
					'done'     => 0,
					'head'     => false,
					'compress' => true,
				);
			}

			$arc_state['manifest_done'] = true;
		}

		// 2. Stream database.sql and allowlisted files.
		$base_dir = str_replace( '\\', '/', migrate_lift_content_dir() ) . '/';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp_list  = file_exists( $list_file ) ? fopen( $list_file, 'rb' ) : false;
		$finished = false;

		if ( $fp_list ) {
			fseek( $fp_list, $arc_state['list_pos'] );
		}

		while ( ! self::out_of_time() ) {
			if ( empty( $arc_state['cur'] ) ) {
				if ( ! $fp_list ) {
					$finished = true;
					break;
				}
				$line = fgets( $fp_list );
				if ( false === $line ) {
					if ( ! $is_zip ) {
						$tar->finish();
					}
					$finished = true;
					break;
				}
				$arc_state['list_pos'] = ftell( $fp_list );
				$rel = rtrim( $line, "\r\n" );
				$abs = $base_dir . $rel;

				if ( ! is_file( $abs ) || ! is_readable( $abs ) ) {
					continue;
				}

				$should_compress = true;
				if ( $media_bypass && class_exists( 'Migrate_Lift_Compression' ) && Migrate_Lift_Compression::is_already_compressed( $rel ) ) {
					$should_compress = false;
				}

				$arc_state['cur'] = array(
					'abs'      => $abs,
					'name'     => 'wp-content/' . $rel,
					'size'     => (int) filesize( $abs ),
					'done'     => 0,
					'head'     => false,
					'compress' => $should_compress,
				);
			}

			$file_done = false;
			if ( $is_zip ) {
				$file_done = self::stream_zip_entry( $zip, $arc_state['cur'], $level, $zip_entries );
			} else {
				$file_done = self::stream_file_entry( $tar, $arc_state['cur'] );
			}

			if ( $file_done ) {
				$arc_state['arc_bytes_done'] += $arc_state['cur']['size'];
				$job['counts']['bytes_processed'] = $arc_state['arc_bytes_done'];
				$job['counts']['items_processed']++;
				$arc_state['cur'] = null;
			}
		}

		if ( $fp_list ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp_list );
		}

		if ( $is_zip ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $zip_entries_file, wp_json_encode( $zip_entries ) );
			if ( $finished ) {
				$zip->finalize_central_directory( $zip_entries );
			}
			$zip->close();
		} else {
			$tar->close();
		}

		clearstatcache( true, $part_file );
		$arc_state['arc_size'] = (int) filesize( $part_file );

		$total_bytes = max( 1, (int) $job['counts']['bytes_total'] );
		$done_bytes  = min( $total_bytes, $arc_state['arc_bytes_done'] );
		$pct_archive = $done_bytes / $total_bytes;

		$job['percentage'] = 25 + (int) floor( $pct_archive * 65 ); // 25% to 90%

		if ( ! $finished ) {
			$message = sprintf( 'Compressing… %s of %s', size_format( $done_bytes ), size_format( $total_bytes ) );
			$job['diagnostic_detail'] = $message;

			return array(
				'done'    => false,
				'job'     => $job,
				'message' => $message,
			);
		}

		// Archive streaming complete, advance to integrity verification.
		$job['checkpoint']['phase'] = 'verify';
		$job['phase']      = 'verify';
		$job['percentage'] = 92;
		$message = __( 'Archive complete. Verifying integrity and manifest…', 'migrate-lift' );
		$job['diagnostic_detail'] = $message;

		return array(
			'done'    => false,
			'job'     => $job,
			'message' => $message,
		);
	}

	/**
	 * Phase 5: Verifies archive integrity and manifest structure.
	 *
	 * @param array $job Job record.
	 * @return array Step result.
	 * @throws Exception On verification failure.
	 */
	protected static function step_verify( &$job ) {
		$part_file = $job['checkpoint']['part_file'];

		if ( ! file_exists( $part_file ) || filesize( $part_file ) <= 0 ) {
			throw new Exception( esc_html__( 'Verification failed: Archive file is missing or empty.', 'migrate-lift' ) );
		}

		$verification = self::verify_archive_file( $part_file );
		if ( ! $verification['valid'] ) {
			$err_msg = ! empty( $verification['errors'] ) ? implode( '; ', $verification['errors'] ) : 'Archive failed integrity verification.';
			throw new Exception( esc_html( 'Integrity verification failed: ' . $err_msg ) );
		}

		// Verified! Advance to finalize.
		$job['checkpoint']['phase']       = 'finalize';
		$job['checkpoint']['verification'] = $verification;
		$job['phase']      = 'finalize';
		$job['percentage'] = 98;
		$message = __( 'Archive integrity verified. Finalizing…', 'migrate-lift' );
		$job['diagnostic_detail'] = $message;

		return array(
			'done'    => false,
			'job'     => $job,
			'message' => $message,
		);
	}

	/**
	 * Phase 6: Atomically moves archive, cleans staging files, and completes job.
	 *
	 * @param array $job Job record.
	 * @return array Step result.
	 */
	protected static function step_finalize( &$job ) {
		$part_file    = $job['checkpoint']['part_file'];
		$archive_name = $job['checkpoint']['archive_name'];
		$storage_dir  = dirname( $part_file );
		$final_path   = $storage_dir . '/' . $archive_name;

		if ( ! file_exists( $part_file ) ) {
			throw new Exception( esc_html__( 'Finalize failed: Staged archive part file not found.', 'migrate-lift' ) );
		}

		// Atomic move / rename.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $part_file, $final_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $part_file, $final_path ) || ! wp_delete_file( $part_file ) ) {
				throw new Exception( esc_html__( 'Could not finalize backup archive. Verify storage permissions.', 'migrate-lift' ) );
			}
		}

		clearstatcache( true, $final_path );
		$final_size = (int) filesize( $final_path );

		// Clean up staging directory.
		self::cleanup_job( $job );

		$download_url = function_exists( 'migrate_lift_download_url' )
			? migrate_lift_download_url( $archive_name )
			: '';

		$files_bytes        = (int) ( $job['checkpoint']['files_bytes'] ?? 0 );
		$sql_bytes          = (int) ( $job['checkpoint']['db']['sql_bytes'] ?? 0 );
		$uncompressed_bytes = $files_bytes + $sql_bytes;

		$metrics = class_exists( 'Migrate_Lift_Compression' )
			? Migrate_Lift_Compression::calculate_metrics( $uncompressed_bytes, $final_size )
			: array( 'reduction_percentage' => 0.0 );

		$comp_info = $job['checkpoint']['compression'] ?? array();
		$mode      = $comp_info['mode'] ?? 'balanced';
		$format    = $comp_info['format'] ?? 'tgz';

		$job['output'] = array(
			'archive'               => $archive_name,
			'size'                  => $final_size,
			'uncompressed_estimate' => $uncompressed_bytes,
			'reduction_percentage'  => $metrics['reduction_percentage'],
			'compression_mode'      => $mode,
			'format'                => $format,
			'created_at'            => time(),
			'download_url'          => $download_url,
			'format_version'        => self::FORMAT_VERSION,
			'warnings'              => $job['checkpoint']['warnings'] ?? array(),
			'verified'              => true,
		);

		// Persist archive metadata in migrate_lift_backup_metadata option.
		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
			$meta = get_option( 'migrate_lift_backup_metadata', array() );
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}
			$meta[ $archive_name ] = array(
				'name'                 => $archive_name,
				'time'                 => time(),
				'size'                 => $final_size,
				'raw_estimate'         => $uncompressed_bytes,
				'reduction_percentage' => (float) $metrics['reduction_percentage'],
				'type'                 => 'full',
				'storage_source'       => 'local',
				'verified'             => true,
				'verified_at'          => time(),
				'status'               => 'ready',
				'format'               => $format,
				'checksum'             => $job['checkpoint']['db']['sql_sha256'] ?? '',
				'manifest'             => $job['checkpoint']['manifest'] ?? null,
			);
			update_option( 'migrate_lift_backup_metadata', $meta, false );
		}

		$job['phase']      = 'completed';
		$job['percentage'] = 100;
		$formatted_reduction = function_exists( 'number_format_i18n' )
			? number_format_i18n( $metrics['reduction_percentage'], 1 )
			: number_format( $metrics['reduction_percentage'], 1 );

		$message = sprintf(
			'Backup completed: %s (%s, source estimate: %s, %s%% reduction).',
			$archive_name,
			size_format( $final_size ),
			size_format( $uncompressed_bytes ),
			$formatted_reduction
		);
		$job['diagnostic_detail'] = $message;

		Migrate_Lift_Activity_Logger::success(
			'backup_completed',
			$message,
			array(
				'job_id'                => $job['id'],
				'archive'               => $archive_name,
				'size'                  => $final_size,
				'uncompressed_estimate' => $uncompressed_bytes,
				'reduction_pct'         => $metrics['reduction_percentage'],
				'mode'                  => $mode,
				'format'                => $format,
				'format_version'        => self::FORMAT_VERSION,
				'warnings'              => count( $job['output']['warnings'] ),
			),
			$job['id']
		);

		return array(
			'done'     => true,
			'job'      => $job,
			'message'  => $message,
			'archive'  => $archive_name,
			'size'     => $final_size,
			'download' => $download_url,
		);
	}

	/**
	 * Verifies an archive file's integrity and manifest structure.
	 *
	 * @param string $archive_path Full path to archive.
	 * @return array Verification report.
	 */
	public static function verify_archive_file( $archive_path ) {
		$report = array(
			'valid'    => false,
			'format'   => '',
			'manifest' => null,
			'errors'   => array(),
			'has_db'   => false,
		);

		clearstatcache( true, $archive_path );

		if ( ! file_exists( $archive_path ) ) {
			$report['errors'][] = 'Archive file does not exist: ' . basename( $archive_path );
			return $report;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $archive_path, 'rb' );
		if ( false === $fp ) {
			$report['errors'][] = 'Archive file is not readable: ' . basename( $archive_path );
			return $report;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		try {
			if ( preg_match( '/\.zip(\.part)?$/i', $archive_path ) ) {
				if ( ! class_exists( 'Migrate_Lift_Zip_Reader' ) ) {
					require_once dirname( __FILE__ ) . '/class-migrate-lift-zip.php';
				}
				$zip_reader = new Migrate_Lift_Zip_Reader( $archive_path );
				$report['has_db'] = $zip_reader->has_entry( 'database.sql' );

				$manifest_raw = null;
				if ( $zip_reader->has_entry( 'migratelift.json' ) ) {
					$manifest_raw = $zip_reader->get_entry_contents( 'migratelift.json' );
				}
				$zip_reader->close();

				if ( ! $manifest_raw ) {
					$report['errors'][] = 'Archive is missing valid migratelift.json manifest.';
					return $report;
				}

				$manifest_data = json_decode( (string) $manifest_raw, true );
				if ( ! is_array( $manifest_data ) || empty( $manifest_data['format_version'] ?? $manifest_data['format'] ) ) {
					$report['errors'][] = 'Archive manifest is not valid JSON.';
					return $report;
				}

				$format_ver = (string) ( $manifest_data['format_version'] ?? $manifest_data['format'] ?? '1.0' );
				$report['format']   = $format_ver;
				$report['manifest'] = $manifest_data;

				if ( empty( $manifest_data['source_environment'] ) && empty( $manifest_data['home'] ) ) {
					$report['errors'][] = 'Manifest is missing source environment metadata.';
				}

				if ( empty( $manifest_data['created_at'] ) && empty( $manifest_data['created'] ) ) {
					$report['errors'][] = 'Manifest is missing created_at timestamp.';
				}

				if ( empty( $report['errors'] ) ) {
					$report['valid'] = true;
				}

				return $report;
			}

			$reader = new Migrate_Lift_Tar_Reader( $archive_path );
			$found_manifest = false;
			$manifest_data  = null;

			while ( ( $entry = $reader->next() ) !== null ) {
				if ( 'migratelift.json' === $entry['name'] ) {
					$content = $reader->read_exact( $entry['size'] );
					$reader->skip_pad( $entry['size'] );

					$decoded = json_decode( $content, true );
					if ( is_array( $decoded ) && ! empty( $decoded['format_version'] ?? $decoded['format'] ) ) {
						$found_manifest = true;
						$manifest_data  = $decoded;
					}
					continue;
				}

				if ( 'database.sql' === $entry['name'] ) {
					$report['has_db'] = true;
				}

				$reader->skip( $entry['size'] );
				$reader->skip_pad( $entry['size'] );
			}

			$reader->close();

			if ( ! $found_manifest || ! is_array( $manifest_data ) ) {
				$report['errors'][] = 'Archive is missing valid migratelift.json manifest.';
				return $report;
			}

			// Validate manifest schema.
			$format_ver = (string) ( $manifest_data['format_version'] ?? $manifest_data['format'] ?? '1.0' );
			$report['format']   = $format_ver;
			$report['manifest'] = $manifest_data;

			if ( empty( $manifest_data['source_environment'] ) && empty( $manifest_data['home'] ) ) {
				$report['errors'][] = 'Manifest is missing source environment metadata.';
			}

			if ( empty( $manifest_data['created_at'] ) && empty( $manifest_data['created'] ) ) {
				$report['errors'][] = 'Manifest is missing created_at timestamp.';
			}

			if ( empty( $report['errors'] ) ) {
				$report['valid'] = true;
			}

		} catch ( Exception $e ) {
			$report['errors'][] = 'Tar Reader decompression error: ' . $e->getMessage();
		}

		return $report;
	}

	/**
	 * Builds format 2.0 manifest metadata, strictly excluding secrets.
	 *
	 * @param array $job Job record.
	 * @return array Manifest schema.
	 */
	public static function build_manifest( $job ) {
		global $wpdb;

		$checkpoint = $job['checkpoint'] ?? array();
		$db_state   = $checkpoint['db'] ?? array();
		$sql_size   = (int) ( $db_state['sql_bytes'] ?? 0 );
		$sql_sha    = (string) ( $db_state['sql_sha256'] ?? '' );

		$tables_count = count( $db_state['tables'] ?? array() );
		$files_count  = (int) ( $checkpoint['files_count'] ?? 0 );
		$files_bytes  = (int) ( $checkpoint['files_bytes'] ?? 0 );
		$warnings     = $checkpoint['warnings'] ?? array();

		$site_url = function_exists( 'get_option' ) ? untrailingslashit( (string) get_option( 'siteurl' ) ) : '';
		$home_url = function_exists( 'get_option' ) ? untrailingslashit( (string) get_option( 'home' ) ) : '';
		$prefix   = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
		$wp_ver   = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '';
		$mysql_ver = ( isset( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) ? $wpdb->db_version() : '';

		return array(
			// Core format identifiers.
			'format_version'        => self::FORMAT_VERSION,
			'format'                => 2, // Backward compatibility int
			'generator'             => 'MigrateLift ' . ( defined( 'MIGRATE_LIFT_VERSION' ) ? MIGRATE_LIFT_VERSION : '1.0.1' ),
			'created_at'            => gmdate( 'c' ),
			'created'               => gmdate( 'c' ),

			// Source environment metadata — NEVER includes plaintext secrets, salts, or passwords.
			'source_environment'    => array(
				'home_url'        => $home_url,
				'site_url'        => $site_url,
				'table_prefix'    => $prefix,
				'wp_version'      => $wp_ver,
				'php_version'     => PHP_VERSION,
				'mysql_version'   => $mysql_ver,
				'os'              => PHP_OS,
				'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			),

			// Compatibility flat fields for legacy format 1 consumers.
			'home'                  => $home_url,
			'siteurl'               => $site_url,
			'prefix'                => $prefix,
			'wp'                    => $wp_ver,
			'php'                   => PHP_VERSION,
			'mysql'                 => $mysql_ver,
			'abspath'               => migrate_lift_root_dir(),
			'plugin'                => plugin_basename( MIGRATE_LIFT_FILE ),

			// Component inventory.
			'component_inventory'   => array(
				'files_count'  => $files_count,
				'files_bytes'  => $files_bytes,
				'tables_count' => $tables_count,
				'warnings'     => $warnings,
			),
			'files'                 => $files_count,
			'bytes'                 => $files_bytes + $sql_size,

			// Database dump section.
			'database_sections'     => array(
				'tables'           => $db_state['tables'] ?? array(),
				'total_rows'       => $db_state['total_rows'] ?? 0,
				'sql_file'         => 'database.sql',
				'sql_size'         => $sql_size,
				'sql_sha256'       => $sql_sha,
				'consistency_mode' => 'bounded_chunked_snapshot_non_locking',
				'notice'           => 'Database export is performed via bounded non-locking queries for shared-hosting compatibility. Tables modified during export may reflect concurrent changes.',
			),

			// Safe restoration documentation.
			'restoration_guidelines' => array(
				'wp_config_policy' => 'wp-config.php and database credentials are excluded from the archive by design.',
				'instructions'     => 'When restoring to a destination site, destination database credentials and authentication salts must be configured independently.',
			),

			// Compression & optimization metadata.
			'compression'            => array(
				'mode'                  => $checkpoint['compression']['mode'] ?? 'balanced',
				'format'                => $checkpoint['compression']['format'] ?? 'tgz',
				'level'                 => $checkpoint['compression']['level'] ?? 6,
				'media_bypass'          => $checkpoint['compression']['media_bypass'] ?? true,
				'source_estimate_bytes' => $files_bytes + $sql_size,
				'disclaimer'            => 'Pre-compressed media assets shrink little with Deflate. Original files are preserved without alteration.',
			),
		);
	}

	/**
	 * Lists WordPress database tables with allowlisted prefix.
	 *
	 * @return array List of table names.
	 */
	protected static function get_database_tables() {
		global $wpdb;

		if ( function_exists( 'migrate_lift_tables' ) && isset( $wpdb->prefix ) ) {
			try {
				return migrate_lift_tables( $wpdb->prefix );
			} catch ( Throwable $e ) {
				// Fall through.
			}
		}

		// The standalone test runner has no database.
		if ( defined( 'MIGRATE_LIFT_TESTING' ) ) {
			$mock_tables = apply_filters( 'migrate_lift_test_tables', null );
			if ( is_array( $mock_tables ) ) {
				return $mock_tables;
			}
		}

		return array();
	}

	/**
	 * Dumps database tables in bounded batches.
	 *
	 * @param array  $db_state Checkpoint state for db.
	 * @param string $sql_path Path to database.sql.
	 * @return bool True when all tables dumped.
	 */
	protected static function dump_tables_chunk( &$db_state, $sql_path ) {
		global $wpdb;

		$tables = $db_state['tables'];
		if ( empty( $tables ) ) {
			return true;
		}

		// Lets the standalone test runner supply a dump without a database.
		$mock_dump = defined( 'MIGRATE_LIFT_TESTING' ) ? apply_filters( 'migrate_lift_test_db_dump', null ) : null;
		if ( null !== $mock_dump ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $sql_path, $mock_dump, FILE_APPEND );
			$db_state['table_index'] = count( $tables );
			$db_state['total_rows']  = 10;
			return true;
		}

		if ( function_exists( 'migrate_lift_db_session' ) ) {
			migrate_lift_db_session();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $sql_path, 'ab' );
		$cut = strlen( $wpdb->prefix );

		while ( $db_state['table_index'] < count( $tables ) && ! self::out_of_time() ) {
			$table  = $tables[ $db_state['table_index'] ];
			$target = migrate_lift_name( MIGRATE_LIFT_TOKEN . substr( $table, $cut ) );

			if ( 0 === $db_state['row_offset'] ) {
				$created = migrate_lift_rows( 'SHOW CREATE TABLE %i', array( $table ) );
				$create  = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE ' . $target, $created[0][1] );
				$create  = str_replace( 'REFERENCES `' . $wpdb->prefix, 'REFERENCES `' . MIGRATE_LIFT_TOKEN, $create );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $out, "DROP TABLE IF EXISTS $target;\n" . str_replace( array( "\r\n", "\n" ), ' ', $create ) . ";\n" );
			}

			$info   = migrate_lift_table_info( $table );
			$cols   = implode( ',', array_map( 'migrate_lift_name', $info['cols'] ) );
			$order  = $info['pk'] ? ' ORDER BY ' . migrate_lift_name_placeholders( count( $info['pk'] ) ) : '';
			$batch  = isset( $db_state['batch'] ) ? (int) $db_state['batch'] : 100;
			$fetch  = migrate_lift_rows(
				'SELECT ' . migrate_lift_name_placeholders( count( $info['cols'] ) ) . ' FROM %i' . $order . ' LIMIT %d, %d',
				array_merge( $info['cols'], array( $table ), $info['pk'], array( (int) $db_state['row_offset'], $batch ) )
			);
			$head   = "INSERT INTO $target ($cols) VALUES ";
			$values = array();
			$length = 0;
			$bytes  = 0;
			$rows   = count( $fetch );

			foreach ( $fetch as $row ) {
				++$db_state['total_rows'];
				foreach ( $row as $i => $value ) {
					if ( null === $value ) {
						$row[ $i ] = 'NULL';
					} elseif ( ! empty( $info['bit'][ $i ] ) ) {
						$row[ $i ] = migrate_lift_bit_literal( $value );
					} elseif ( ! empty( $info['bin'][ $i ] ) ) {
						$row[ $i ] = '' === $value ? "''" : '0x' . bin2hex( $value );
					} else {
						$row[ $i ] = migrate_lift_e( $value );
					}
				}
				$values[] = '(' . implode( ',', $row ) . ')';
				$size     = strlen( end( $values ) );
				$length  += $size;
				$bytes   += $size;
				if ( $length > 1000000 ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fwrite( $out, $head . implode( ',', $values ) . ";\n" );
					$values = array();
					$length = 0;
				}
			}
			unset( $fetch );
			if ( $values ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $out, $head . implode( ',', $values ) . ";\n" );
			}

			if ( $rows < $batch ) {
				++$db_state['table_index'];
				$db_state['row_offset'] = 0;
				unset( $db_state['batch'] );
			} else {
				$db_state['row_offset'] += $batch;
				$db_state['batch']       = migrate_lift_next_batch( $batch, $bytes, self::DB_BATCH_SIZE );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		return $db_state['table_index'] >= count( $tables );
	}

	/**
	 * Streams a single file entry into the tar archive.
	 *
	 * @param Migrate_Lift_Tar_Writer $tar Archive.
	 * @param array                   $cur Current entry state.
	 * @return bool True when the file is fully streamed.
	 */
	protected static function stream_file_entry( $tar, &$cur ) {
		if ( ! $cur['head'] ) {
			$tar->header( $cur['name'], $cur['size'], (int) @filemtime( $cur['abs'] ) );
			$cur['head'] = true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in = @fopen( $cur['abs'], 'rb' );
		if ( $in && $cur['done'] ) {
			fseek( $in, $cur['done'] );
		}

		while ( $cur['done'] < $cur['size'] ) {
			$want = min( self::FILE_CHUNK_SIZE, $cur['size'] - $cur['done'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$data = $in ? fread( $in, $want ) : '';
			if ( false === $data || '' === $data ) {
				$data = str_repeat( "\0", $want ); // Shrunk; preserve padding
			}
			$tar->write( $data );
			$cur['done'] += strlen( $data );

			if ( self::out_of_time() ) {
				break;
			}
		}

		if ( $in ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $in );
		}

		if ( $cur['done'] < $cur['size'] ) {
			return false;
		}

		$tar->pad( $cur['size'] );
		return true;
	}

	/**
	 * Streams a single file entry into the ZIP archive.
	 *
	 * @param Migrate_Lift_Zip_Writer $zip         ZIP Writer instance.
	 * @param array                   $cur         Current entry state.
	 * @param int                     $level       Deflate compression level.
	 * @param array                   $zip_entries Array of completed zip entries for Central Directory.
	 * @return bool True when the file is fully streamed.
	 */
	protected static function stream_zip_entry( $zip, &$cur, $level, &$zip_entries ) {
		if ( ! $cur['head'] ) {
			$compress = ! empty( $cur['compress'] );
			$zip->start_entry( $cur['name'], $cur['size'], (int) @filemtime( $cur['abs'] ), $compress, $level );
			$cur['head'] = true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in = @fopen( $cur['abs'], 'rb' );
		if ( $in && $cur['done'] ) {
			fseek( $in, $cur['done'] );
		}

		while ( $cur['done'] < $cur['size'] ) {
			$want = min( self::FILE_CHUNK_SIZE, $cur['size'] - $cur['done'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$data = $in ? fread( $in, $want ) : '';
			if ( false === $data || '' === $data ) {
				$data = str_repeat( "\0", $want );
			}
			$zip->write_chunk( $data );
			$cur['done'] += strlen( $data );

			if ( self::out_of_time() ) {
				break;
			}
		}

		if ( $in ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $in );
		}

		if ( $cur['done'] < $cur['size'] ) {
			return false;
		}

		$entry_record  = $zip->end_entry();
		$zip_entries[] = $entry_record;
		return true;
	}

	/**
	 * Checks time budget for the current step.
	 *
	 * @return bool True if out of time.
	 */
	protected static function out_of_time() {
		if ( function_exists( 'migrate_lift_out_of_time' ) ) {
			return migrate_lift_out_of_time();
		}
		return false;
	}

	/**
	 * Cleans temporary staging files associated with a job.
	 *
	 * @param array $job Job record.
	 */
	public static function cleanup_job( $job ) {
		$checkpoint = $job['checkpoint'] ?? array();

		if ( ! empty( $checkpoint['staging_dir'] ) && is_dir( $checkpoint['staging_dir'] ) ) {
			if ( function_exists( 'migrate_lift_rmdir' ) ) {
				migrate_lift_rmdir( $checkpoint['staging_dir'] );
			} else {
				self::delete_dir_recursive( $checkpoint['staging_dir'] );
			}
		}

		if ( ! empty( $checkpoint['part_file'] ) && file_exists( $checkpoint['part_file'] ) ) {
			wp_delete_file( $checkpoint['part_file'] );
		}
	}

	/**
	 * Recursive directory removal helper.
	 *
	 * @param string $dir Path.
	 */
	protected static function delete_dir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir );
		if ( ! $items ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $dir );
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$p = $dir . '/' . $item;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				self::delete_dir_recursive( $p );
			} else {
				wp_delete_file( $p );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}
}
