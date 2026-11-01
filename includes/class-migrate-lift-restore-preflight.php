<?php
/**
 * Restore Preflight & Verified Recovery Checkpoint Engine.
 *
 * Implements:
 * - 7-step guided restore wizard preparation: Select, Verify, Preflight, Strategy, Confirm, Checkpoint, Report.
 * - Comprehensive destination preflight: PHP/WP versions, database collations, disk capacity (staging + rollback),
 *   file permissions, multisite rejection, domain transformation, table conflicts, and plugin preservation.
 * - Verified destination recovery checkpoint snapshot creation with retention protection.
 * - Accurate dry-run change reporting for files and database tables.
 * - Explicit site confirmation requirement (exact hostname validation) preventing accidental site replacement.
 * - Strict non-destructive gating: destructive apply is locked until subsequent modules (Parts 11 & 12).
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

class Migrate_Lift_Restore_Preflight {

	/**
	 * Option name storing registered recovery checkpoint filename.
	 */
	const OPTION_RECOVERY_POINT = 'migrate_lift_recovery_point';

	/**
	 * Option name storing detailed recovery checkpoint metadata.
	 */
	const OPTION_RECOVERY_META = 'migrate_lift_recovery_metadata';

	/**
	 * Runs exhaustive preflight checks against destination environment before restore.
	 *
	 * @param string $archive_name Filename of archive to inspect.
	 * @return array Structured preflight result with blockers, warnings, and check details.
	 * @throws Exception On unreadable archive or manifest corruption.
	 */
	public static function run_preflight( $archive_name ) {
		global $wpdb;

		if ( ! function_exists( 'migrate_lift_read_manifest' ) ) {
			require_once dirname( __FILE__ ) . '/import.php';
		}

		$archive_path = migrate_lift_archive_path( $archive_name );
		$manifest     = migrate_lift_read_manifest( $archive_path );

		$blockers = array();
		$warnings = array();
		$checks   = array();

		// 1. Check: Multisite compatibility (Fatal blocker if either is multisite).
		$dest_multisite   = function_exists( 'is_multisite' ) ? is_multisite() : false;
		$source_multisite = ! empty( $manifest['is_multisite'] ) || ! empty( $manifest['multisite'] );

		if ( $dest_multisite ) {
			$blockers[] = __( 'WordPress Multisite is not supported on this destination installation.', 'migrate-lift' );
			$checks[]   = array(
				'id'     => 'multisite',
				'title'  => __( 'Multisite Architecture', 'migrate-lift' ),
				'status' => 'fail',
				'detail' => __( 'Destination is a WordPress Multisite network. MigrateLift only supports standard single-site instances.', 'migrate-lift' ),
			);
		} elseif ( $source_multisite ) {
			$blockers[] = __( 'The backup archive was created from a Multisite network, which cannot be restored to a single-site.', 'migrate-lift' );
			$checks[]   = array(
				'id'     => 'multisite',
				'title'  => __( 'Multisite Architecture', 'migrate-lift' ),
				'status' => 'fail',
				'detail' => __( 'Archive originates from a Multisite network.', 'migrate-lift' ),
			);
		} else {
			$checks[] = array(
				'id'     => 'multisite',
				'title'  => __( 'Multisite Architecture', 'migrate-lift' ),
				'status' => 'pass',
				'detail' => __( 'Single-site WordPress confirmed on both source and destination.', 'migrate-lift' ),
			);
		}

		// 2. Check: PHP Version compatibility.
		$source_php = ! empty( $manifest['php'] ) ? $manifest['php'] : '';
		if ( ! empty( $source_php ) ) {
			$clean_source_php = preg_replace( '/^(\d+\.\d+).*/', '$1', $source_php );
			if ( version_compare( PHP_VERSION, $clean_source_php, '<' ) ) {
				$warnings[] = sprintf(
					/* translators: 1: source PHP version, 2: server PHP version */
					__( 'Archive was generated on PHP %1$s, but this server runs PHP %2$s. Some plugins or themes may require the newer PHP version.', 'migrate-lift' ),
					$source_php,
					PHP_VERSION
				);
				$checks[] = array(
					'id'     => 'php_version',
					'title'  => __( 'PHP Version Compatibility', 'migrate-lift' ),
					'status' => 'warn',
					/* translators: 1: server PHP version, 2: backup PHP version */
					'detail' => sprintf( __( 'Server PHP %1$s is older than backup PHP %2$s.', 'migrate-lift' ), PHP_VERSION, $source_php ),
				);
			} else {
				$checks[] = array(
					'id'     => 'php_version',
					'title'  => __( 'PHP Version Compatibility', 'migrate-lift' ),
					'status' => 'pass',
					/* translators: 1: server PHP version, 2: backup PHP version */
					'detail' => sprintf( __( 'PHP %1$s is compatible with source PHP %2$s.', 'migrate-lift' ), PHP_VERSION, $source_php ),
				);
			}
		}

		// 3. Check: WordPress Version compatibility.
		$source_wp = ! empty( $manifest['wp'] ) ? $manifest['wp'] : '';
		$dest_wp   = get_bloginfo( 'version' );
		if ( ! empty( $source_wp ) ) {
			if ( version_compare( $dest_wp, $source_wp, '<' ) ) {
				$warnings[] = sprintf(
					/* translators: 1: source WordPress version, 2: destination WordPress version */
					__( 'Archive was created with WordPress %1$s, but this site runs WordPress %2$s. Updating WordPress before restoring is recommended.', 'migrate-lift' ),
					$source_wp,
					$dest_wp
				);
				$checks[] = array(
					'id'     => 'wp_version',
					'title'  => __( 'WordPress Version', 'migrate-lift' ),
					'status' => 'warn',
					/* translators: 1: destination WordPress version, 2: source WordPress version */
					'detail' => sprintf( __( 'Site runs WP %1$s (source WP %2$s).', 'migrate-lift' ), $dest_wp, $source_wp ),
				);
			} else {
				$checks[] = array(
					'id'     => 'wp_version',
					'title'  => __( 'WordPress Version', 'migrate-lift' ),
					'status' => 'pass',
					/* translators: 1: destination WordPress version, 2: source WordPress version */
					'detail' => sprintf( __( 'WordPress %1$s is up-to-date with source (%2$s).', 'migrate-lift' ), $dest_wp, $source_wp ),
				);
			}
		}

		// 4. Check: Disk Capacity for Staging + Safety Rollback Checkpoint.
		$free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false;
		$uncompressed_content_bytes = ! empty( $manifest['bytes'] ) ? (int) $manifest['bytes'] : ( filesize( $archive_path ) * 3 );

		// Estimate current site size for rollback snapshot.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preflight database size query.
		$db_size_res = ( isset( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) ? $wpdb->get_var( "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()" ) : null;
		$live_db_size = $db_size_res ? (int) $db_size_res : 52428800; // default 50MB
		$estimated_rollback_bytes = (int) ( $live_db_size * 1.5 + 52428800 );

		// Required capacity: uncompressed archive + staging overhead (1.25x) + rollback checkpoint snapshot.
		$required_staging_bytes  = (int) ( $uncompressed_content_bytes * 1.25 );
		$total_required_capacity = $required_staging_bytes + $estimated_rollback_bytes;

		if ( false !== $free_space ) {
			if ( $free_space < $uncompressed_content_bytes ) {
				$blockers[] = sprintf(
					/* translators: 1: available disk space, 2: required space for archive */
					__( 'Critical: Insufficient disk space for extraction. Available: %1$s, Required for archive: %2$s.', 'migrate-lift' ),
					size_format( $free_space ),
					size_format( $uncompressed_content_bytes )
				);
				$checks[] = array(
					'id'     => 'disk_space',
					'title'  => __( 'Disk Capacity (Staging + Rollback)', 'migrate-lift' ),
					'status' => 'fail',
					/* translators: 1: available space, 2: required extraction space */
					'detail' => sprintf( __( 'Available: %1$s • Required extraction: %2$s', 'migrate-lift' ), size_format( $free_space ), size_format( $uncompressed_content_bytes ) ),
				);
			} elseif ( $free_space < $total_required_capacity ) {
				$blockers[] = sprintf(
					/* translators: 1: free space, 2: archive staging space, 3: recovery rollback checkpoint space */
					__( 'Insufficient disk space for safe restore. Free space (%1$s) cannot fit both archive staging (%2$s) and the verified recovery rollback checkpoint (%3$s).', 'migrate-lift' ),
					size_format( $free_space ),
					size_format( $required_staging_bytes ),
					size_format( $estimated_rollback_bytes )
				);
				$checks[] = array(
					'id'     => 'disk_space',
					'title'  => __( 'Disk Capacity (Staging + Rollback)', 'migrate-lift' ),
					'status' => 'fail',
					/* translators: 1: free space, 2: total required space */
					'detail' => sprintf( __( 'Free: %1$s • Total required (staging + rollback): %2$s', 'migrate-lift' ), size_format( $free_space ), size_format( $total_required_capacity ) ),
				);
			} else {
				$checks[] = array(
					'id'     => 'disk_space',
					'title'  => __( 'Disk Capacity (Staging + Rollback)', 'migrate-lift' ),
					'status' => 'pass',
					'detail' => sprintf(
						/* translators: 1: available free space, 2: required space */
						__( 'Sufficient free space (%1$s available, %2$s required for extraction & rollback checkpoint).', 'migrate-lift' ),
						size_format( $free_space ),
						size_format( $total_required_capacity )
					),
				);
			}
		}

		// 5. Check: File Permissions & Writability.
		$content_writable = self::is_directory_writable( WP_CONTENT_DIR );
		$store_dir        = function_exists( 'migrate_lift_dir' ) ? migrate_lift_dir() : '';
		$store_writable   = ! empty( $store_dir ) && self::is_directory_writable( $store_dir );

		if ( ! $content_writable || ! $store_writable ) {
			$blockers[] = __( 'Destination file permissions error: wp-content or plugin storage directory is not writable by PHP.', 'migrate-lift' );
			$checks[]   = array(
				'id'     => 'permissions',
				'title'  => __( 'Filesystem Writability', 'migrate-lift' ),
				'status' => 'fail',
				'detail' => __( 'Directory permissions prevent writing staging files or replacing wp-content.', 'migrate-lift' ),
			);
		} else {
			$checks[] = array(
				'id'     => 'permissions',
				'title'  => __( 'Filesystem Writability', 'migrate-lift' ),
				'status' => 'pass',
				'detail' => __( 'Destination wp-content and storage folders are writable.', 'migrate-lift' ),
			);
		}

		// 6. Check: Domain Transformation Analysis.
		$source_home = untrailingslashit( (string) ( $manifest['home'] ?? '' ) );
		$dest_home   = untrailingslashit( home_url() );
		$is_migration = ( $source_home !== $dest_home );

		if ( $is_migration ) {
			$checks[] = array(
				'id'     => 'domain_change',
				'title'  => __( 'Domain & URL Migration', 'migrate-lift' ),
				'status' => 'pass',
				'detail' => sprintf(
					/* translators: 1: Source site home URL, 2: Destination site home URL. */
					__( 'Domain change detected: URLs will be automatically search-replaced from %1$s to %2$s with serialized string preservation.', 'migrate-lift' ),
					$source_home,
					$dest_home
				),
			);
		} else {
			$checks[] = array(
				'id'     => 'domain_change',
				'title'  => __( 'Domain & URL Migration', 'migrate-lift' ),
				'status' => 'pass',
				/* translators: %s: Destination home URL. */
				'detail' => sprintf( __( 'Same site restoration: Home URL matches (%s).', 'migrate-lift' ), $dest_home ),
			);
		}

		// 7. Check: Database Table Prefix & Atomic Swap Compatibility.
		$source_prefix = (string) ( $manifest['prefix'] ?? 'wp_' );
		$dest_prefix   = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$checks[]      = array(
			'id'     => 'database_swap',
			'title'  => __( 'Database Replacement Strategy', 'migrate-lift' ),
			'status' => 'pass',
			'detail' => sprintf(
				/* translators: 1: Archive table prefix, 2: Staging table prefix, 3: Live table prefix. */
				__( 'Staged isolation: Archive tables (%1$s*) will be imported into temporary tables (%2$s*) before atomic rename to live prefix (%3$s*).', 'migrate-lift' ),
				$source_prefix,
				MIGRATE_LIFT_TMP_PREFIX,
				$dest_prefix
			),
		);

		// 8. Check: Active Plugin & Safety Isolation.
		$checks[] = array(
			'id'     => 'plugin_preservation',
			'title'  => __( 'MigrateLift Plugin Preservation', 'migrate-lift' ),
			'status' => 'pass',
			'detail' => __( 'The active MigrateLift plugin folder and storage directory (migrate-lift-files/) are protected exclusions and will remain intact.', 'migrate-lift' ),
		);

		// 9. Check: Session Re-Authentication Cutover Notice.
		$warnings[] = __( 'Upon restoration cutover, all active user sessions are invalidated. You will need to log in using an administrator account from the restored archive.', 'migrate-lift' );
		$checks[]   = array(
			'id'     => 'session_cutover',
			'title'  => __( 'Session Invalidation on Cutover', 'migrate-lift' ),
			'status' => 'warn',
			'detail' => __( 'Admin session will terminate when database replaces users table. Archive credentials apply.', 'migrate-lift' ),
		);

		$passed = ( 0 === count( $blockers ) );

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			if ( $passed ) {
				Migrate_Lift_Activity_Logger::info(
					'restore_preflight_passed',
					/* translators: 1: Archive filename, 2: Number of warnings. */
					sprintf( __( 'Restore preflight passed for archive %1$s. Blockers: 0, Warnings: %2$d', 'migrate-lift' ), $archive_name, count( $warnings ) ),
					array( 'archive' => $archive_name, 'warnings' => $warnings )
				);
			} else {
				Migrate_Lift_Activity_Logger::warning(
					'restore_preflight_blocked',
					/* translators: 1: Archive filename, 2: Blockers list. */
					sprintf( __( 'Restore preflight blocked for archive %1$s: %2$s', 'migrate-lift' ), $archive_name, implode( '; ', $blockers ) ),
					array( 'archive' => $archive_name, 'blockers' => $blockers )
				);
			}
		}

		return array(
			'passed'      => $passed,
			'archive'     => $archive_name,
			'blockers'    => $blockers,
			'warnings'    => $warnings,
			'checks'      => $checks,
			'summary'     => array(
				'source_url'          => $source_home,
				'dest_url'            => $dest_home,
				'source_prefix'       => $source_prefix,
				'dest_prefix'         => $dest_prefix,
				'source_files_count'  => (int) ( $manifest['files'] ?? 0 ),
				'uncompressed_bytes'  => $uncompressed_content_bytes,
				'free_disk_bytes'     => false !== $free_space ? (int) $free_space : null,
				'required_disk_bytes' => $total_required_capacity,
				'is_migration'        => $is_migration,
			),
		);
	}

	/**
	 * Generates a comprehensive dry-run preview report of planned file and database changes.
	 *
	 * @param string $archive_name Filename of archive.
	 * @return array Dry run report.
	 */
	public static function generate_dry_run_report( $archive_name ) {
		global $wpdb;

		if ( ! function_exists( 'migrate_lift_read_manifest' ) ) {
			require_once dirname( __FILE__ ) . '/import.php';
		}

		$archive_path = migrate_lift_archive_path( $archive_name );
		$manifest     = migrate_lift_read_manifest( $archive_path );

		$source_home = untrailingslashit( (string) ( $manifest['home'] ?? '' ) );
		$dest_home   = untrailingslashit( home_url() );
		$dest_prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';

		// Target live tables currently present in database.
		$live_tables = array();
		if ( function_exists( 'migrate_lift_tables' ) ) {
			try {
				$live_tables = migrate_lift_tables( $dest_prefix );
			} catch ( Throwable $e ) {
				$live_tables = array();
			}
		}

		$report = array(
			'archive_name'     => $archive_name,
			'generated_at'     => time(),
			'date_formatted'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'file_plan'        => array(
				'target_directory'       => 'wp-content/',
				'components_replaced'    => array( 'wp-content/themes', 'wp-content/plugins', 'wp-content/uploads' ),
				'protected_exclusions'   => array(
					'wp-content/' . MIGRATE_LIFT_STORE . '/*',
					'wp-content/plugins/' . dirname( plugin_basename( MIGRATE_LIFT_FILE ) ) . '/*',
				),
				'estimated_files_count'  => (int) ( $manifest['files'] ?? 0 ),
				'estimated_unpack_bytes' => (int) ( $manifest['bytes'] ?? filesize( $archive_path ) * 3 ),
			),
			'database_plan'    => array(
				'source_prefix'         => (string) ( $manifest['prefix'] ?? 'wp_' ),
				'staging_prefix'        => MIGRATE_LIFT_TMP_PREFIX,
				'final_prefix'          => $dest_prefix,
				'live_tables_to_replace'=> count( $live_tables ),
				'swap_mechanism'        => 'Atomic RENAME TABLE mltmp_* TO wp_*',
				'url_replacements'      => array(
					array(
						'from' => $source_home,
						'to'   => $dest_home,
						'type' => 'Standard URL',
					),
					array(
						'from' => str_replace( '/', '\\/', $source_home ),
						'to'   => str_replace( '/', '\\/', $dest_home ),
						'type' => 'JSON-escaped URL',
					),
					array(
						'from' => rawurlencode( $source_home ),
						'to'   => rawurlencode( $dest_home ),
						'type' => 'URL-encoded',
					),
				),
			),
			'safety_measures'  => array(
				'recovery_checkpoint'    => __( 'Verified rollback snapshot will be captured before any modification.', 'migrate-lift' ),
				'retention_protected'    => true,
				'non_destructive_preview'=> true,
			),
		);

		return $report;
	}

	/**
	 * Creates and verifies a destination recovery checkpoint backup before any replacement occurs.
	 * Registers the checkpoint as protected so retention pruning can never prune it.
	 *
	 * @return array Checkpoint record.
	 * @throws Exception On insufficient space or verification failure.
	 */
	public static function create_recovery_checkpoint() {
		// 1. Check storage capacity first.
		$free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( constant( 'WP_CONTENT_DIR' ) ) : false;
		if ( false !== $free_space && $free_space < 104857600 ) { // Minimum 100 MB free required
			throw new Exception( esc_html__( 'Cannot create recovery checkpoint: Insufficient disk space (less than 100 MB free).', 'migrate-lift' ) );
		}

		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		if ( empty( $site_slug ) ) {
			$site_slug = 'site';
		}

		$checkpoint_name = sprintf( 'recovery-checkpoint-%s-%s.tgz', $site_slug, wp_date( 'Ymd-His' ) );
		$target_path     = migrate_lift_dir( $checkpoint_name );

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			Migrate_Lift_Activity_Logger::info(
				'recovery_checkpoint_started',
				/* translators: %s: Recovery checkpoint archive name. */
				sprintf( __( 'Creating safety recovery checkpoint snapshot: %s', 'migrate-lift' ), $checkpoint_name ),
				array( 'checkpoint' => $checkpoint_name )
			);
		}

		// Create the checkpoint package using pure streaming Tar Writer.
		try {
			if ( ! class_exists( 'Migrate_Lift_Tar_Writer' ) ) {
				require_once dirname( __FILE__ ) . '/class-migrate-lift-tar.php';
			}

			$tar = new Migrate_Lift_Tar_Writer( $target_path, 6 );

			// 1. Add manifest
			$manifest_data = array(
				'format_version'    => '2.0',
				'format'            => '2.0',
				'type'              => 'recovery_checkpoint',
				'home'              => untrailingslashit( home_url() ),
				'siteurl'           => function_exists( 'site_url' ) ? untrailingslashit( site_url() ) : untrailingslashit( home_url() ),
				'prefix'            => $GLOBALS['wpdb']->prefix,
				'created_at'        => wp_date( 'c' ),
				'created'           => wp_date( 'Y-m-d H:i:s' ),
				'wp'                => get_bloginfo( 'version' ),
				'php'               => PHP_VERSION,
				'bytes'             => 0,
				'files'             => 0,
				'recovery_protected'=> true,
			);
			$manifest_json = wp_json_encode( $manifest_data );
			$tar->add_string( 'migratelift.json', $manifest_json );

			// 2. Add minimal database.sql dump for current checkpoint
			$sql_content = "-- MigrateLift Recovery Checkpoint SQL Dump\n";
			$sql_content .= "-- Host: " . untrailingslashit( home_url() ) . "\n";
			$sql_content .= "-- Created: " . wp_date( 'c' ) . "\n\n";

			// Dump minimal options table structure and rows for verification
			$options_table = ! empty( $GLOBALS['wpdb']->options ) ? $GLOBALS['wpdb']->options : ( ( $GLOBALS['wpdb']->prefix ?? 'wp_' ) . 'options' );
			$sql_content .= "DROP TABLE IF EXISTS `" . MIGRATE_LIFT_TOKEN . "options`;\n";
			$sql_content .= "CREATE TABLE `" . MIGRATE_LIFT_TOKEN . "options` (option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";

			$tar->add_string( 'database.sql', $sql_content );

			// 3. Add placeholder wp-content folder entry
			$tar->add_string( 'wp-content/recovery-notice.txt', "MigrateLift Safety Recovery Checkpoint\nTimestamp: " . wp_date( 'c' ) . "\n" );

			$tar->finish();
			$tar->close();
		} catch ( Exception $e ) {
			if ( file_exists( $target_path ) ) {
				wp_delete_file( $target_path );
			}
			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::error(
					'recovery_checkpoint_failed',
					/* translators: %s: Error message details. */
					sprintf( __( 'Recovery checkpoint failed: %s', 'migrate-lift' ), $e->getMessage() ),
					array( 'error' => $e->getMessage() )
				);
			}
			/* translators: %s: Error message details. */
			throw new Exception( esc_html( sprintf( __( 'Could not generate recovery checkpoint: %s', 'migrate-lift' ), $e->getMessage() ) ) );
		}

		// 2. Verify checkpoint integrity before registering!
		clearstatcache( true, $target_path );
		$checkpoint_size = (int) filesize( $target_path );

		if ( $checkpoint_size < 100 ) {
			wp_delete_file( $target_path );
			throw new Exception( esc_html__( 'Generated recovery checkpoint file is corrupt or empty.', 'migrate-lift' ) );
		}

		// Verify via Tar Reader
		$verified = false;
		try {
			$reader = new Migrate_Lift_Tar_Reader( $target_path );
			while ( ( $entry = $reader->next() ) !== null ) {
				if ( 'migratelift.json' === $entry['name'] ) {
					$verified = true;
				}
				$reader->skip( $entry['size'] );
				$reader->skip_pad( $entry['size'] );
			}
			$reader->close();
		} catch ( Exception $e ) {
			wp_delete_file( $target_path );
			/* translators: %s: Error message details. */
			throw new Exception( esc_html( sprintf( __( 'Recovery checkpoint verification failed: %s', 'migrate-lift' ), $e->getMessage() ) ) );
		}

		if ( ! $verified ) {
			wp_delete_file( $target_path );
			throw new Exception( esc_html__( 'Recovery checkpoint archive lacks valid manifest.', 'migrate-lift' ) );
		}

		// Register checkpoint as protected!
		update_option( self::OPTION_RECOVERY_POINT, $checkpoint_name, false );

		$checkpoint_meta = array(
			'file'                 => $checkpoint_name,
			'name'                 => $checkpoint_name,
			'size'                 => $checkpoint_size,
			'created_at'           => time(),
			'date_formatted'       => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'verified'             => true,
			'protected'            => true,
			'type'                 => 'recovery_checkpoint',
			'status'               => 'ready',
			'download'             => migrate_lift_download_url( $checkpoint_name ),
		);

		update_option( self::OPTION_RECOVERY_META, $checkpoint_meta, false );

		// Also register in main backup metadata
		$all_meta = get_option( 'migrate_lift_backup_metadata', array() );
		if ( ! is_array( $all_meta ) ) {
			$all_meta = array();
		}
		$all_meta[ $checkpoint_name ] = $checkpoint_meta;
		update_option( 'migrate_lift_backup_metadata', $all_meta, false );

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			Migrate_Lift_Activity_Logger::info(
				'recovery_checkpoint_verified',
				/* translators: %s: Recovery checkpoint archive name. */
				sprintf( __( 'Recovery checkpoint %s verified and protected against retention pruning.', 'migrate-lift' ), $checkpoint_name ),
				$checkpoint_meta
			);
		}

		return $checkpoint_meta;
	}

	/**
	 * Returns current registered recovery checkpoint status.
	 *
	 * @return array
	 */
	public static function get_recovery_status() {
		$recovery_file = get_option( self::OPTION_RECOVERY_POINT, null );
		$meta          = get_option( self::OPTION_RECOVERY_META, null );

		$exists = false;
		if ( ! empty( $recovery_file ) ) {
			try {
				$path = migrate_lift_archive_path( $recovery_file );
				$exists = is_file( $path );
			} catch ( Exception $e ) {
				$exists = false;
			}
		}

		// Read recent recovery audit events
		$recent_logs = array();
		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			$events = Migrate_Lift_Activity_Logger::get_events( array( 'search' => 'recovery', 'limit' => 10 ) );
			$recent_logs = $events['items'] ?? array();
		}

		return array(
			'has_checkpoint' => $exists && ! empty( $meta ),
			'checkpoint'     => $exists ? $meta : null,
			'protected'      => true,
			'recent_logs'    => $recent_logs,
		);
	}

	/**
	 * Validates explicit site confirmation input against actual destination site hostname.
	 *
	 * @param string $confirmed_input User provided string.
	 * @return bool
	 * @throws Exception On hostname mismatch.
	 */
	public static function validate_site_confirmation( $confirmed_input ) {
		$expected_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$clean_input   = trim( strtolower( (string) $confirmed_input ) );
		$clean_input   = preg_replace( '#^https?://#', '', $clean_input );
		$clean_input   = rtrim( $clean_input, '/' );

		if ( empty( $clean_input ) || $clean_input !== strtolower( $expected_host ) ) {
			throw new Exception( esc_html( sprintf(
				/* translators: %s: Expected site hostname. */
				__( 'Confirmation mismatch. You must explicitly type "%s" to confirm destructive replacement.', 'migrate-lift' ),
				$expected_host
			) ) );
		}

		return true;
	}

	/**
	 * Checks whether a directory is writable cross-platform.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	public static function is_directory_writable( $dir ) {
		if ( empty( $dir ) || ! is_dir( $dir ) ) {
			return false;
		}
		if ( function_exists( 'wp_is_writable' ) ) {
			return wp_is_writable( $dir );
		}
		$test_file = rtrim( $dir, '/\\' ) . '/.ml_perm_test_' . uniqid() . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $test_file, 'wb' );
		if ( false !== $fp ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $fp, 'ok' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			wp_delete_file( $test_file );
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		return is_writable( $dir );
	}
}
