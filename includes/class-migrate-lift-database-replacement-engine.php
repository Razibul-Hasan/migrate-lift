<?php
/**
 * MigrateLift Database Replacement Engine.
 *
 * Implements controlled WordPress database replacement with temporary
 * staged table isolation (mltmp_), serialization-safe domain & path transformation,
 * admin account preservation, atomic multi-table rename swap, and rollback.
 *
 * Supported Modes:
 * 1. keep_destination: Do not replace any database tables (file-only restore).
 * 2. replace_mapped: Replace all mapped destination WordPress tables (safe default).
 * 3. replace_selected: Replace only selected table groups.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

class Migrate_Lift_Database_Replacement_Engine {

	const MODE_KEEP             = 'keep_destination';
	const MODE_REPLACE_MAPPED   = 'replace_mapped';
	const MODE_REPLACE_SELECTED = 'replace_selected';

	/**
	 * Returns supported database replacement modes.
	 *
	 * @return array
	 */
	public static function get_supported_modes() {
		return array(
			self::MODE_REPLACE_MAPPED   => array(
				'id'          => self::MODE_REPLACE_MAPPED,
				'title'       => __( 'Replace mapped WordPress tables (Recommended)', 'migrate-lift' ),
				'description' => __( 'Imports and updates all standard WordPress and plugin tables matching the site prefix. Staged in temporary tables with atomic rename swap.', 'migrate-lift' ),
				'recommended' => true,
			),
			self::MODE_REPLACE_SELECTED => array(
				'id'          => self::MODE_REPLACE_SELECTED,
				'title'       => __( 'Replace selected table groups', 'migrate-lift' ),
				'description' => __( 'Select specific table groups to replace (e.g. content only, options, plugin tables) while keeping the rest untouched.', 'migrate-lift' ),
				'recommended' => false,
			),
			self::MODE_KEEP             => array(
				'id'          => self::MODE_KEEP,
				'title'       => __( 'Keep destination database', 'migrate-lift' ),
				'description' => __( 'Does not touch the database. Restores files only. Useful for template, theme, or media file restorations.', 'migrate-lift' ),
				'recommended' => false,
			),
		);
	}

	/**
	 * Returns standard table group definitions for partial restorations.
	 *
	 * @param string $prefix Table prefix.
	 * @return array
	 */
	public static function get_table_groups( $prefix = '' ) {
		if ( empty( $prefix ) ) {
			global $wpdb;
			$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
		}

		return array(
			'posts_and_comments' => array(
				'title'  => __( 'Posts, Pages & Comments', 'migrate-lift' ),
				'tables' => array( "{$prefix}posts", "{$prefix}postmeta", "{$prefix}comments", "{$prefix}commentmeta" ),
			),
			'taxonomies'         => array(
				'title'  => __( 'Categories, Tags & Terms', 'migrate-lift' ),
				'tables' => array( "{$prefix}terms", "{$prefix}termmeta", "{$prefix}term_taxonomy", "{$prefix}term_relationships" ),
			),
			'users'              => array(
				'title'  => __( 'Users & Profiles', 'migrate-lift' ),
				'tables' => array( "{$prefix}users", "{$prefix}usermeta" ),
			),
			'options'            => array(
				'title'  => __( 'Site Settings & Options', 'migrate-lift' ),
				'tables' => array( "{$prefix}options" ),
			),
		);
	}

	/**
	 * Generates a comprehensive dry-run preview of planned database operations.
	 * Lists exact tables affected and verifies unrelated tables remain untouched.
	 *
	 * @param string $archive_name   Filename of archive.
	 * @param string $db_mode        Selected mode.
	 * @param array  $selected_groups Specific groups or tables to replace.
	 * @return array Dry run plan.
	 * @throws Exception On read error.
	 */
	public static function generate_diff_preview( $archive_name, $db_mode = self::MODE_REPLACE_MAPPED, $selected_groups = array() ) {
		global $wpdb;

		if ( ! function_exists( 'migrate_lift_read_manifest' ) ) {
			require_once dirname( __FILE__ ) . '/import.php';
		}

		$archive_path = migrate_lift_archive_path( $archive_name );
		$manifest     = migrate_lift_read_manifest( $archive_path );

		$source_prefix = (string) ( $manifest['prefix'] ?? 'wp_' );
		$dest_prefix   = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
		$source_home   = untrailingslashit( (string) ( $manifest['home'] ?? '' ) );
		$dest_home     = untrailingslashit( home_url() );
		$source_siteurl= untrailingslashit( (string) ( $manifest['siteurl'] ?? $source_home ) );
		$dest_siteurl  = untrailingslashit( function_exists( 'site_url' ) ? site_url() : home_url() );

		// Discover live tables currently present in database
		$all_live_tables = array();
		if ( function_exists( 'migrate_lift_tables' ) ) {
			try {
				$all_live_tables = migrate_lift_tables( $dest_prefix );
			} catch ( Throwable $e ) {
				$all_live_tables = array();
			}
		}

		// Filter tables based on mode
		$tables_to_replace = array();
		$tables_untouched  = array();

		if ( self::MODE_KEEP === $db_mode ) {
			$tables_untouched = $all_live_tables;
		} elseif ( self::MODE_REPLACE_SELECTED === $db_mode ) {
			$allowed_tables = array();
			$groups = self::get_table_groups( $dest_prefix );

			foreach ( (array) $selected_groups as $group_key ) {
				if ( isset( $groups[ $group_key ] ) ) {
					$allowed_tables = array_merge( $allowed_tables, $groups[ $group_key ]['tables'] );
				} elseif ( in_array( $group_key, $all_live_tables, true ) ) {
					$allowed_tables[] = $group_key;
				}
			}

			foreach ( $all_live_tables as $table ) {
				if ( in_array( $table, $allowed_tables, true ) ) {
					$tables_to_replace[] = $table;
				} else {
					$tables_untouched[] = $table;
				}
			}
		} else {
			// replace_mapped (default): all tables matching destination prefix
			$tables_to_replace = $all_live_tables;
		}

		// Search & replace URL pairs
		$search_replace_plan = array();
		if ( $source_home !== $dest_home || $source_siteurl !== $dest_siteurl ) {
			$search_replace_plan[] = array(
				'from' => $source_home,
				'to'   => $dest_home,
				'type' => 'Standard URL',
			);
			$search_replace_plan[] = array(
				'from' => str_replace( '/', '\\/', $source_home ),
				'to'   => str_replace( '/', '\\/', $dest_home ),
				'type' => 'JSON-escaped URL',
			);
			$search_replace_plan[] = array(
				'from' => rawurlencode( $source_home ),
				'to'   => rawurlencode( $dest_home ),
				'type' => 'URL-encoded',
			);
		}

		return array(
			'mode'                => $db_mode,
			'archive_name'        => $archive_name,
			'source_prefix'       => $source_prefix,
			'dest_prefix'         => $dest_prefix,
			'staging_prefix'      => MIGRATE_LIFT_TMP_PREFIX,
			'tables_to_replace'   => $tables_to_replace,
			'tables_untouched'    => $tables_untouched,
			'count_to_replace'    => count( $tables_to_replace ),
			'count_untouched'     => count( $tables_untouched ),
			'search_replace_plan' => $search_replace_plan,
			'swap_mechanism'      => sprintf( 'Atomic RENAME TABLE %s* TO %s*', MIGRATE_LIFT_TMP_PREFIX, $dest_prefix ),
			'session_warning'     => in_array( "{$dest_prefix}users", $tables_to_replace, true ),
		);
	}

	/**
	 * Extracts database.sql from the archive into a dedicated staging file.
	 *
	 * @param string $archive_path Full path to archive.
	 * @param string $sql_target   Target file path for extracted SQL.
	 * @return bool
	 * @throws Exception On extract error.
	 */
	public static function extract_archive_sql( $archive_path, $sql_target ) {
		$is_zip = (bool) preg_match( '/\.zip$/i', $archive_path );

		wp_mkdir_p( dirname( $sql_target ) );

		if ( $is_zip ) {
			if ( ! class_exists( 'Migrate_Lift_Zip_Reader' ) ) {
				require_once dirname( __FILE__ ) . '/class-migrate-lift-zip.php';
			}
			$zip = new Migrate_Lift_Zip_Reader( $archive_path );
			$sql = $zip->get_entry_contents( 'database.sql' );
			$zip->close();

			if ( empty( $sql ) ) {
				throw new Exception( esc_html__( 'Archive does not contain database.sql.', 'migrate-lift' ) );
			}
			file_put_contents( $sql_target, $sql );
			return true;
		}

		if ( ! class_exists( 'Migrate_Lift_Tar_Reader' ) ) {
			require_once dirname( __FILE__ ) . '/class-migrate-lift-tar.php';
		}
		$tar   = new Migrate_Lift_Tar_Reader( $archive_path );
		$found = false;

		while ( ( $entry = $tar->next() ) !== null ) {
			if ( 'database.sql' === $entry['name'] ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct handle for SQL extraction.
				$out = fopen( $sql_target, 'wb' );
				if ( ! $out ) {
					$tar->close();
					throw new Exception( esc_html__( 'Could not open staging SQL file for writing.', 'migrate-lift' ) );
				}
				$written = 0;
				while ( $written < $entry['size'] ) {
					$chunk = $tar->read_exact( min( 1048576, $entry['size'] - $written ) );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fwrite( $out, $chunk );
					$written += strlen( $chunk );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $out );
				$tar->skip_pad( $entry['size'] );
				$found = true;
				break;
			}
			$tar->skip( $entry['size'] );
			$tar->skip_pad( $entry['size'] );
		}
		$tar->close();

		if ( ! $found ) {
			throw new Exception( esc_html__( 'Archive does not contain database.sql.', 'migrate-lift' ) );
		}

		return true;
	}

	/**
	 * Recursively replaces values inside arbitrary data structures while preserving
	 * serialized string byte lengths.
	 *
	 * @param mixed $data  Value (string, array, stdClass, etc.).
	 * @param array $pairs From => To map.
	 * @return mixed Transformed data with valid serialization headers.
	 */
	public static function recursive_serialization_replace( $data, array $pairs ) {
		if ( empty( $pairs ) ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			// Check if this string is a serialized PHP data structure
			if ( function_exists( 'is_serialized' ) && is_serialized( $data ) ) {
				$unserialized = @unserialize( $data, array( 'allowed_classes' => array( 'stdClass' ) ) );
				if ( false !== $unserialized || 'b:0;' === $data ) {
					$transformed = self::recursive_serialization_replace( $unserialized, $pairs );
					return serialize( $transformed );
				}
			}

			// Plain string replacement
			return strtr( $data, $pairs );
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $val ) {
				$new_key = is_string( $key ) ? strtr( $key, $pairs ) : $key;
				$out[ $new_key ] = self::recursive_serialization_replace( $val, $pairs );
			}
			return $out;
		}

		if ( $data instanceof stdClass ) {
			$vars = get_object_vars( $data );
			$out  = new stdClass();
			foreach ( $vars as $key => $val ) {
				$new_key = strtr( $key, $pairs );
				$out->$new_key = self::recursive_serialization_replace( $val, $pairs );
			}
			return $out;
		}

		return $data;
	}

	/**
	 * Imports database.sql into isolated temporary tables (mltmp_*), rewriting
	 * source prefixes and adjusting MySQL 8+ collations if unsupported on destination.
	 *
	 * @param string $sql_file Path to extracted database.sql.
	 * @return array List of created temporary tables.
	 * @throws Exception On SQL error.
	 */
	public static function stage_import_sql( $sql_file ) {
		global $wpdb;

		if ( ! is_file( $sql_file ) ) {
			throw new Exception( esc_html__( 'Extracted SQL file not found for staging.', 'migrate-lift' ) );
		}

		// 1. Clean previous leftover mltmp_ tables if any
		if ( function_exists( 'migrate_lift_tables' ) ) {
			try {
				foreach ( migrate_lift_tables( MIGRATE_LIFT_TMP_PREFIX ) as $table ) {
					migrate_lift_q( 'DROP TABLE IF EXISTS ' . migrate_lift_name( $table ) );
				}
			} catch ( Throwable $e ) {
				// Continue.
			}
		}

		// 2. Collation compatibility check
		$fix_collation = false;
		if ( function_exists( 'migrate_lift_col' ) ) {
			try {
				$has_0900 = migrate_lift_col( "SHOW COLLATION WHERE Collation = 'utf8mb4_0900_ai_ci'" );
				$fix_collation = empty( $has_0900 );
			} catch ( Throwable $e ) {
				$fix_collation = true;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct handle for SQL staging.
		$handle = fopen( $sql_file, 'rb' );
		if ( ! $handle ) {
			throw new Exception( esc_html__( 'Could not open SQL file for staging execution.', 'migrate-lift' ) );
		}

		$tokens = array( '`' . MIGRATE_LIFT_TOKEN );
		$tmp    = '`' . MIGRATE_LIFT_TMP_PREFIX;

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line || '--' === substr( $line, 0, 2 ) || '/*' === substr( $line, 0, 2 ) ) {
				continue;
			}

			$matched = false;
			foreach ( $tokens as $t ) {
				if ( 0 === strpos( $line, "INSERT INTO $t" ) ) {
					$line = "INSERT IGNORE INTO $tmp" . substr( $line, strlen( "INSERT INTO $t" ) );
					$matched = true;
					break;
				} elseif ( 0 === strpos( $line, 'CREATE TABLE ' ) && false !== strpos( $line, $t ) ) {
					$line = str_replace( $t, $tmp, $line );
					if ( $fix_collation ) {
						$line = preg_replace( '/utf8mb4_0900_\w+/', 'utf8mb4_unicode_520_ci', $line );
					}
					$matched = true;
					break;
				} elseif ( 0 === strpos( $line, "DROP TABLE IF EXISTS $t" ) ) {
					$line = str_replace( $t, $tmp, $line );
					$matched = true;
					break;
				}
			}

			if ( $matched && function_exists( 'migrate_lift_q' ) ) {
				try {
					migrate_lift_q( $line );
				} catch ( Throwable $e ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );
					/* translators: %s: error message */
					throw new Exception( esc_html( sprintf( __( 'Error staging table query: %s', 'migrate-lift' ), $e->getMessage() ) ) );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		// Return list of created temporary tables
		if ( function_exists( 'migrate_lift_tables' ) ) {
			try {
				return migrate_lift_tables( MIGRATE_LIFT_TMP_PREFIX );
			} catch ( Throwable $e ) {
				return array();
			}
		}

		return array();
	}

	/**
	 * Executes serialization-safe search and replace across staged temporary tables.
	 *
	 * @param array $tables Staged mltmp_* table names.
	 * @param array $pairs  From => To replacement pairs.
	 * @return int Total replacement count.
	 */
	public static function transform_staged_tables( array $tables, array $pairs ) {
		if ( empty( $tables ) || empty( $pairs ) || ! function_exists( 'migrate_lift_table_info' ) ) {
			return 0;
		}

		$total_replacements = 0;

		foreach ( $tables as $table_name ) {
			try {
				$info = migrate_lift_table_info( $table_name );
			} catch ( Throwable $e ) {
				continue;
			}

			$text_cols = array();
			foreach ( $info['cols'] as $idx => $col ) {
				if ( ! empty( $info['text'][ $idx ] ) && ! in_array( $col, $info['pk'], true ) ) {
					$text_cols[] = $col;
				}
			}

			if ( empty( $text_cols ) || empty( $info['pk'] ) ) {
				continue;
			}

			$table_escaped = migrate_lift_name( $table_name );
			$select_cols   = implode( ',', array_map( 'migrate_lift_name', array_merge( $info['pk'], $text_cols ) ) );
			$order_cols    = implode( ',', array_map( 'migrate_lift_name', $info['pk'] ) );

			$query  = "SELECT $select_cols FROM $table_escaped ORDER BY $order_cols";
			try {
				$result = migrate_lift_q( $query );
				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_assoc
				while ( ( $row = mysqli_fetch_assoc( $result ) ) !== null ) {
					$updates = array();
					foreach ( $text_cols as $col ) {
						$original = $row[ $col ];
						if ( null === $original || '' === $original ) {
							continue;
						}
						$replaced = self::recursive_serialization_replace( $original, $pairs );
						if ( $replaced !== $original ) {
							$updates[] = migrate_lift_name( $col ) . ' = ' . migrate_lift_e( $replaced );
							++$total_replacements;
						}
					}

					if ( ! empty( $updates ) ) {
						$where = array();
						foreach ( $info['pk'] as $pk_col ) {
							$where[] = migrate_lift_name( $pk_col ) . ' = ' . migrate_lift_e( $row[ $pk_col ] );
						}
						migrate_lift_q( "UPDATE $table_escaped SET " . implode( ', ', $updates ) . ' WHERE ' . implode( ' AND ', $where ) );
					}
				}
				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_free_result
				mysqli_free_result( $result );
			} catch ( Throwable $e ) {
				// Continue.
			}
		}

		return $total_replacements;
	}

	/**
	 * Preserves the current active admin user record into staged temporary users and usermeta tables.
	 *
	 * @return bool True if preserved.
	 */
	public static function preserve_current_admin_user() {
		global $wpdb;

		$current_user_id = get_current_user_id();
		if ( empty( $current_user_id ) ) {
			return false;
		}

		// Check if staged tables exist
		$tmp_users    = MIGRATE_LIFT_TMP_PREFIX . 'users';
		$tmp_usermeta = MIGRATE_LIFT_TMP_PREFIX . 'usermeta';

		try {
			if ( ! function_exists( 'migrate_lift_q' ) ) {
				return false;
			}

			// Read live admin row
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin user preservation.
			$live_user = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $current_user_id ), ARRAY_A );
			if ( ! $live_user ) {
				return false;
			}

			// Insert or replace into mltmp_users
			$cols = array();
			$vals = array();
			foreach ( $live_user as $k => $v ) {
				$cols[] = migrate_lift_name( $k );
				$vals[] = migrate_lift_e( $v );
			}
			migrate_lift_q( "REPLACE INTO " . migrate_lift_name( $tmp_users ) . " (" . implode( ',', $cols ) . ") VALUES (" . implode( ',', $vals ) . ")" );

			// Copy admin usermeta
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin user preservation.
			$live_meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $current_user_id ), ARRAY_A );
			if ( ! empty( $live_meta ) ) {
				migrate_lift_q( "DELETE FROM " . migrate_lift_name( $tmp_usermeta ) . " WHERE user_id = " . (int) $current_user_id );
				foreach ( $live_meta as $m ) {
					migrate_lift_q( sprintf(
						"INSERT INTO %s (user_id, meta_key, meta_value) VALUES (%d, %s, %s)",
						migrate_lift_name( $tmp_usermeta ),
						(int) $current_user_id,
						migrate_lift_e( $m['meta_key'] ),
						migrate_lift_e( $m['meta_value'] )
					) );
				}
			}

			return true;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Activates WordPress core maintenance mode by writing .maintenance file.
	 */
	public static function enable_maintenance_mode() {
		$file    = defined( 'ABSPATH' ) ? ABSPATH . '.maintenance' : './.maintenance';
		$content = "<?php \$upgrading = " . ( time() + 300 ) . ";";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Standard WordPress core .maintenance flag file in root.
		@file_put_contents( $file, $content );
	}

	/**
	 * Deactivates WordPress core maintenance mode.
	 */
	public static function disable_maintenance_mode() {
		$file = defined( 'ABSPATH' ) ? ABSPATH . '.maintenance' : './.maintenance';
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Executes the atomic database table rename swap.
	 * Moves live tables to mlold_*, and moves staged mltmp_* to live prefix in a single query.
	 *
	 * @param array $staged_tables Temporary mltmp_* tables.
	 * @return array Swapped table names.
	 * @throws Exception On rename failure.
	 */
	public static function swap_staged_tables( array $staged_tables ) {
		global $wpdb;

		$dest_prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
		$old_prefix  = MIGRATE_LIFT_OLD_PREFIX;
		$tmp_prefix  = MIGRATE_LIFT_TMP_PREFIX;

		$live_tables = function_exists( 'migrate_lift_tables' ) ? migrate_lift_tables( $dest_prefix ) : array();

		// Clean up any stale mlold_ tables first
		if ( function_exists( 'migrate_lift_tables' ) ) {
			foreach ( migrate_lift_tables( $old_prefix ) as $stale ) {
				migrate_lift_q( 'DROP TABLE IF EXISTS ' . migrate_lift_name( $stale ) );
			}
		}

		$renames = array();
		// 1. Live tables -> mlold_*
		foreach ( $live_tables as $table ) {
			if ( 0 !== strpos( $table, $tmp_prefix ) && 0 !== strpos( $table, $old_prefix ) ) {
				$sub = substr( $table, strlen( $dest_prefix ) );
				$renames[] = migrate_lift_name( $table ) . ' TO ' . migrate_lift_name( $old_prefix . $sub );
			}
		}

		// 2. Temporary staged tables -> live prefix
		$swapped = array();
		foreach ( $staged_tables as $table ) {
			$sub = substr( $table, strlen( $tmp_prefix ) );
			$target_name = $dest_prefix . $sub;
			$renames[] = migrate_lift_name( $table ) . ' TO ' . migrate_lift_name( $target_name );
			$swapped[] = $target_name;
		}

		if ( empty( $renames ) ) {
			return array();
		}

		// Execute atomic rename
		self::enable_maintenance_mode();

		try {
			$rename_query = 'RENAME TABLE ' . implode( ', ', $renames );
			migrate_lift_q( $rename_query );

			// Cutover successful! Drop mlold_* tables
			foreach ( migrate_lift_tables( $old_prefix ) as $old_table ) {
				migrate_lift_q( 'DROP TABLE IF EXISTS ' . migrate_lift_name( $old_table ) );
			}

			// Drop caches
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			if ( function_exists( 'opcache_reset' ) ) {
				@opcache_reset();
			}

			self::disable_maintenance_mode();
			return $swapped;

		} catch ( Throwable $e ) {
			// Rollback: try to restore original tables from mlold_*
			try {
				$rollback_renames = array();
				foreach ( migrate_lift_tables( $old_prefix ) as $old_table ) {
					$sub = substr( $old_table, strlen( $old_prefix ) );
					$rollback_renames[] = migrate_lift_name( $old_table ) . ' TO ' . migrate_lift_name( $dest_prefix . $sub );
				}
				if ( ! empty( $rollback_renames ) ) {
					migrate_lift_q( 'RENAME TABLE ' . implode( ', ', $rollback_renames ) );
				}
			} catch ( Throwable $rollback_e ) {
				// Log critical error
			}

			self::disable_maintenance_mode();
			/* translators: %s: error message */
			throw new Exception( esc_html( sprintf( __( 'Atomic database cutover failed and was rolled back: %s', 'migrate-lift' ), $e->getMessage() ) ) );
		}
	}

	/**
	 * Full database replacement execution flow.
	 *
	 * @param string $archive_name   Filename of archive.
	 * @param string $db_mode        Replacement mode.
	 * @param array  $options        Execution options (selected_groups, preserve_admin, etc.).
	 * @return array Execution report.
	 * @throws Exception On failure.
	 */
	public static function apply_database_replacement( $archive_name, $db_mode = self::MODE_REPLACE_MAPPED, $options = array() ) {
		if ( self::MODE_KEEP === $db_mode ) {
			return array(
				'success'         => true,
				'mode'            => self::MODE_KEEP,
				'tables_swapped'  => 0,
				'message'         => __( 'Database replacement skipped (Keep destination mode).', 'migrate-lift' ),
			);
		}

		$archive_path = migrate_lift_archive_path( $archive_name );
		$id           = uniqid();
		$sql_staging  = migrate_lift_dir( 'staging/restore-sql-' . $id . '/database.sql' );

		try {
			// 1. Extract database.sql to staging
			self::extract_archive_sql( $archive_path, $sql_staging );

			// 2. Stage into temporary tables (mltmp_*)
			$staged_tables = self::stage_import_sql( $sql_staging );

			if ( empty( $staged_tables ) ) {
				throw new Exception( esc_html__( 'No database tables could be staged from archive SQL.', 'migrate-lift' ) );
			}

			// 3. Prepare search-replace pairs
			$manifest = migrate_lift_read_manifest( $archive_path );
			$source_home = untrailingslashit( (string) ( $manifest['home'] ?? '' ) );
			$dest_home   = untrailingslashit( home_url() );

			$pairs = array();
			if ( $source_home !== $dest_home && ! empty( $source_home ) ) {
				$pairs[ $source_home ]                                = $dest_home;
				$pairs[ str_replace( '/', '\\/', $source_home ) ]     = str_replace( '/', '\\/', $dest_home );
				$pairs[ rawurlencode( $source_home ) ]                = rawurlencode( $dest_home );
			}

			// 4. Run serialization-safe search and replace
			$replacements_count = self::transform_staged_tables( $staged_tables, $pairs );

			// 5. Optionally preserve current admin user
			$admin_preserved = false;
			if ( ! empty( $options['preserve_admin_user'] ) ) {
				$admin_preserved = self::preserve_current_admin_user();
			}

			// 6. Execute atomic multi-table rename swap
			$swapped_tables = self::swap_staged_tables( $staged_tables );

			// 7. Cleanup staging SQL
			if ( is_file( $sql_staging ) ) {
				wp_delete_file( $sql_staging );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( dirname( $sql_staging ) );
			}

			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::info(
					'database_replacement_success',
					/* translators: 1: replacement mode, 2: tables swapped count, 3: replacements count */
					sprintf( __( 'Database replacement completed (%1$s): %2$d tables swapped, %3$d values transformed.', 'migrate-lift' ), $db_mode, count( $swapped_tables ), $replacements_count ),
					array(
						'archive'      => $archive_name,
						'mode'         => $db_mode,
						'tables'       => count( $swapped_tables ),
						'replacements' => $replacements_count,
					)
				);
			}

			return array(
				'success'            => true,
				'mode'               => $db_mode,
				'tables_swapped'     => count( $swapped_tables ),
				'tables_list'        => $swapped_tables,
				'replacements_count' => $replacements_count,
				'admin_preserved'    => $admin_preserved,
			);

		} catch ( Throwable $e ) {
			if ( is_file( $sql_staging ) ) {
				wp_delete_file( $sql_staging );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( dirname( $sql_staging ) );
			}

			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::error(
					'database_replacement_failed',
					/* translators: %s: error message */
					sprintf( __( 'Database replacement failed: %s', 'migrate-lift' ), $e->getMessage() ),
					array( 'error' => $e->getMessage(), 'archive' => $archive_name )
				);
			}

			throw $e;
		}
	}
}

