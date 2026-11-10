<?php
/**
 * Import: extract the archive, load the database into temporary tables, switch the URLs,
 * then swap files and tables in one quick step. The current site keeps working until that swap.
 *
 * Phases: prepare → extract → db → replace → swap → cleanup (cleanup runs after the swap,
 * when the old login no longer exists, so it is authorised by a one-time token instead).
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

/**
 * Reads the manifest at the start of an archive.
 *
 * @param string $path Archive path.
 * @return array
 */
function migrate_lift_read_manifest( $path ) {
	if ( preg_match( '/\.zip$/i', $path ) ) {
		if ( ! class_exists( 'Migrate_Lift_Zip_Reader' ) ) {
			require_once dirname( __FILE__ ) . '/class-migrate-lift-zip.php';
		}
		$zip = new Migrate_Lift_Zip_Reader( $path );
		$manifest_raw = $zip->get_entry_contents( 'migratelift.json' );
		$zip->close();

		$manifest = json_decode( (string) $manifest_raw, true );
		if ( ! is_array( $manifest ) || empty( $manifest['home'] ) || empty( $manifest['prefix'] ) ) {
			throw new Exception( esc_html__( 'This is not a valid Migrate Lift archive or the manifest is damaged.', 'migrate-lift' ) );
		}
		return $manifest;
	}

	$tar      = new Migrate_Lift_Tar_Reader( $path );
	$manifest = null;

	while ( $entry = $tar->next() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		if ( 'migratelift.json' === $entry['name'] && $entry['size'] <= 1048576 ) {
			$manifest = json_decode( $tar->read_exact( $entry['size'] ), true );
			break;
		}
		// If not the manifest and past the first few entries, stop.
		if ( $tar->pos > 2097152 ) {
			break;
		}
		$tar->skip( $entry['size'] );
		$tar->skip_pad( $entry['size'] );
	}
	$tar->close();

	if ( ! is_array( $manifest ) || empty( $manifest['home'] ) || empty( $manifest['prefix'] ) ) {
		throw new Exception( esc_html__( 'This is not a valid Migrate Lift archive or the manifest is damaged.', 'migrate-lift' ) );
	}
	return $manifest;
}

/**
 * Archive details and warnings, shown before the import starts.
 *
 * @param string $file Archive name.
 * @return array
 */
function migrate_lift_import_info( $file ) {
	$path     = migrate_lift_archive_path( $file );
	$manifest = migrate_lift_read_manifest( $path );
	$warnings = array();

	if ( version_compare( PHP_VERSION, preg_replace( '/^(\d+\.\d+).*/', '$1', $manifest['php'] ), '<' ) ) {
		$warnings[] = sprintf( 'The archive was made on PHP %s but this server runs PHP %s. Some plugins may need the newer PHP, so switch PHP first if you can.', $manifest['php'], PHP_VERSION );
	}
	if ( version_compare( get_bloginfo( 'version' ), $manifest['wp'], '<' ) ) {
		$warnings[] = sprintf( 'The archive was made with WordPress %s but this site runs %s. Update WordPress here first.', $manifest['wp'], get_bloginfo( 'version' ) );
	}
	if ( untrailingslashit( get_option( 'home' ) ) === $manifest['home'] ) {
		$warnings[] = 'This archive was exported from this same site. Importing it restores the site to that point.';
	}
	$free = function_exists( 'disk_free_space' ) ? @disk_free_space( migrate_lift_content_dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( $free && $free < $manifest['bytes'] * 1.2 ) {
		$warnings[] = sprintf( 'Only %s of disk space is free; the import needs about %s.', size_format( $free ), size_format( $manifest['bytes'] * 1.2 ) );
	}

	return array(
		'file'     => basename( $path ),
		'from'     => $manifest['home'],
		'to'       => untrailingslashit( get_option( 'home' ) ),
		'created'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $manifest['created'] ) ),
		'files'    => number_format_i18n( $manifest['files'] ),
		'size'     => size_format( $manifest['bytes'], 1 ),
		'warnings' => $warnings,
	);
}

/**
 * Runs one import step.
 *
 * @param string $step         "start" or "continue".
 * @param string $file         Archive name (start only).
 * @param bool   $delete_after Delete the archive after a successful import (start only).
 * @return array
 */
function migrate_lift_import_step( $step, $file = '', $delete_after = true ) {
	migrate_lift_timer_start();

	if ( 'start' === $step ) {
		if ( is_multisite() ) {
			throw new Exception( esc_html__( 'Multisite is not supported.', 'migrate-lift' ) );
		}
		$path = migrate_lift_archive_path( $file );
		migrate_lift_state_put(
			'import',
			array(
				'phase'        => 'prepare',
				'file'         => basename( $path ),
				'delete_after' => (bool) $delete_after,
				'token'        => wp_generate_password( 32, false ),
				'manifest'     => migrate_lift_read_manifest( $path ),
				'live'         => migrate_lift_site_info(),
				'pos'          => 0,
				'cur'          => null,
				'files'        => 0,
				'sql_pos'      => 0,
			)
		);
		return migrate_lift_progress( 1, 'Preparing…' );
	}

	$s = migrate_lift_state_get( 'import' );
	if ( ! $s || 'cleanup' === $s['phase'] ) {
		throw new Exception( esc_html__( 'No import is running. Start it again.', 'migrate-lift' ) );
	}
	migrate_lift_db_session();

	switch ( $s['phase'] ) {
		case 'prepare':
			if ( migrate_lift_import_prepare() ) {
				$s['phase'] = 'extract';
			}
			$response = migrate_lift_progress( 2, 'Removing leftovers of earlier imports…' );
			break;

		case 'extract':
			if ( migrate_lift_import_extract( $s ) ) {
				$s['phase'] = 'db';
			}
			$total    = max( 1, $s['manifest']['bytes'] );
			$response = migrate_lift_progress( 2 + 58 * min( 1, $s['pos'] / $total ), sprintf( 'Extracting files… %s of %s', number_format_i18n( $s['files'] ), number_format_i18n( $s['manifest']['files'] ) ) );
			break;

		case 'db':
			if ( migrate_lift_import_db( $s ) ) {
				$s['phase'] = 'replace';
			}
			$total    = max( 1, (int) @filesize( migrate_lift_tmp( 'import-tmp', 'database.sql' ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$response = migrate_lift_progress( 60 + 20 * $s['sql_pos'] / $total, 'Importing the database…' );
			break;

		case 'replace':
			if ( migrate_lift_import_replace( $s ) ) {
				migrate_lift_import_fix_options( $s );
				$s['phase'] = 'swap';
			}
			$count    = max( 1, count( $s['r_tables'] ) );
			$response = migrate_lift_progress( 80 + 18 * $s['rt'] / $count, sprintf( 'Changing %s to %s… table %d of %d', $s['manifest']['home'], $s['live']['home'], min( $s['rt'] + 1, $count ), $count ) );
			break;

		case 'swap':
			migrate_lift_import_swap( $s );
			$s['phase'] = 'cleanup';
			migrate_lift_state_put( 'import', $s );
			return migrate_lift_progress(
				100,
				'The site is imported.',
				array(
					'done'  => true,
					'token' => $s['token'],
				)
			);

		default:
			throw new Exception( esc_html__( 'Unknown import phase.', 'migrate-lift' ) );
	}

	migrate_lift_state_put( 'import', $s );
	return $response;
}

/**
 * Removes temporary files and tables left by an earlier import that did not finish.
 *
 * @return bool True when clean.
 */
function migrate_lift_import_prepare() {
	foreach ( array( 'import-tmp', 'trash' ) as $dir ) {
		if ( ! migrate_lift_rmdir( migrate_lift_tmp( $dir ) ) ) {
			return false;
		}
	}
	foreach ( array_merge( migrate_lift_tables( MIGRATE_LIFT_TMP_PREFIX ), migrate_lift_tables( MIGRATE_LIFT_OLD_PREFIX ) ) as $table ) {
		migrate_lift_q( 'DROP TABLE IF EXISTS %i', array( $table ) );
	}
	wp_mkdir_p( migrate_lift_tmp( 'import-tmp' ) );
	return true;
}

/**
 * Validates an archive entry name and maps it into import-tmp.
 *
 * @param string $name Entry name.
 * @return string|false Relative path, or false to skip the entry.
 */
function migrate_lift_entry_path( $name ) {
	$name = rtrim( preg_replace( '#^\./#', '', str_replace( '\\', '/', $name ) ), '/' );
	if ( '' === $name || false !== strpos( $name, "\0" ) || '/' === $name[0] || preg_match( '#(^|/)\.\.(/|$)#', $name ) || preg_match( '#^[a-z]:#i', $name ) ) {
		return false;
	}
	if ( 'migratelift.json' !== $name && 'database.sql' !== $name && 0 !== strpos( $name, 'wp-content/' ) ) {
		return false;
	}
	return $name;
}

/**
 * Extracts the archive into import-tmp, resuming where the last step stopped.
 *
 * @param array $s State.
 * @return bool True when the end of the archive is reached.
 */
function migrate_lift_import_extract( &$s ) {
	$tar  = new Migrate_Lift_Tar_Reader( migrate_lift_dir( $s['file'] ), $s['pos'] );
	$root = migrate_lift_tmp( 'import-tmp' ) . '/';
	$end  = false;

	while ( ! migrate_lift_out_of_time() ) {
		if ( $s['cur'] ) {
			$cur = &$s['cur'];
			$out = fopen( $root . $cur['path'], 'cb' );
			if ( ! $out ) {
				throw new Exception( esc_html( 'Could not write ' . $cur['path'] ) );
			}
			ftruncate( $out, $cur['done'] );
			fseek( $out, 0, SEEK_END );
			while ( $cur['done'] < $cur['size'] ) {
				$data = $tar->read_exact( min( 1048576, $cur['size'] - $cur['done'] ) );
				if ( false === fwrite( $out, $data ) ) {
					throw new Exception( esc_html( 'Could not write ' . $cur['path'] . '. Is the disk full?' ) );
				}
				$cur['done'] += strlen( $data );
				if ( migrate_lift_out_of_time() ) {
					break;
				}
			}
			fclose( $out );
			if ( $cur['done'] < $cur['size'] ) {
				break;
			}
			$tar->skip_pad( $cur['size'] );
			if ( $cur['mtime'] ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch
				@touch( $root . $cur['path'], $cur['mtime'] );
			}
			if ( 0 === strpos( $cur['path'], 'wp-content/' ) ) {
				++$s['files'];
			}
			unset( $cur );
			$s['cur'] = null;
			$s['pos'] = $tar->pos;
			continue;
		}

		$entry = $tar->next();
		if ( null === $entry ) {
			$end = true;
			break;
		}

		$rel = migrate_lift_entry_path( $entry['name'] );
		if ( false !== $rel && '5' === $entry['type'] ) {
			wp_mkdir_p( $root . $rel );
		} elseif ( false !== $rel && in_array( $entry['type'], array( '0', '7' ), true ) ) {
			wp_mkdir_p( dirname( $root . $rel ) );
			$s['cur'] = array(
				'path'  => $rel,
				'size'  => $entry['size'],
				'done'  => 0,
				'mtime' => $entry['mtime'],
			);
			continue; // pos is saved once the file is written.
		} else {
			$tar->skip( $entry['size'] );
			$tar->skip_pad( $entry['size'] );
		}
		$s['pos'] = $tar->pos;
	}

	$s['pos'] = $tar->pos;
	$tar->close();

	if ( $end ) {
		if ( ! is_file( $root . 'database.sql' ) || ! is_dir( $root . 'wp-content' ) ) {
			throw new Exception( esc_html__( 'The archive has no database or no wp-content folder.', 'migrate-lift' ) );
		}
	}
	return $end;
}

/**
 * Runs database.sql into temporary tables (MIGRATE_LIFT_TMP_PREFIX), one line per statement.
 *
 * @param array $s State.
 * @return bool True when finished.
 */
function migrate_lift_import_db( &$s ) {
	if ( ! isset( $s['fix_collation'] ) ) {
		$s['fix_collation'] = ! migrate_lift_col( 'SHOW COLLATION WHERE Collation = %s', array( 'utf8mb4_0900_ai_ci' ) );
	}

	$in = fopen( migrate_lift_tmp( 'import-tmp', 'database.sql' ), 'rb' );
	fseek( $in, $s['sql_pos'] );
	$tokens = array( '`' . MIGRATE_LIFT_TOKEN );
	$tmp    = '`' . MIGRATE_LIFT_TMP_PREFIX;
	$end    = false;

	while ( ! migrate_lift_out_of_time() ) {
		$line = fgets( $in );
		if ( false === $line ) {
			$end = true;
			break;
		}
		$line = rtrim( $line, "\r\n" );

		$matched_token = false;
		foreach ( $tokens as $t ) {
			if ( 0 === strpos( $line, "INSERT INTO $t" ) ) {
				$line = "INSERT IGNORE INTO $tmp" . substr( $line, strlen( "INSERT INTO $t" ) );
				$matched_token = true;
				break;
			} elseif ( 0 === strpos( $line, 'CREATE TABLE ' ) && false !== strpos( $line, $t ) ) {
				$line = str_replace( $t, $tmp, $line );
				if ( $s['fix_collation'] ) {
					$line = preg_replace( '/utf8mb4_0900_\w+/', 'utf8mb4_unicode_520_ci', $line );
				}
				$matched_token = true;
				break;
			} elseif ( 0 === strpos( $line, "DROP TABLE IF EXISTS $t" ) ) {
				$line = str_replace( $t, $tmp, $line );
				$matched_token = true;
				break;
			}
		}

		if ( ! $matched_token ) {
			continue; // Only our own statement types are run.
		}

		migrate_lift_run_dump_statement( $line );
		$s['sql_pos'] = ftell( $in );
	}
	fclose( $in );

	return $end;
}

/**
 * Search and replace pairs: old URLs (plain, JSON-escaped and URL-encoded) and old server paths.
 *
 * @param array $s State.
 * @return array from => to, applied with strtr (longest match first, never replaced twice).
 */
function migrate_lift_replace_pairs( $s ) {
	$pairs = array();
	$json  = function ( $text ) {
		return substr( wp_json_encode( $text, JSON_UNESCAPED_UNICODE ), 1, -1 );
	};

	// siteurl first so home wins when both point at the same old URL.
	foreach ( array( 'siteurl', 'home' ) as $key ) {
		$from = untrailingslashit( $s['manifest'][ $key ] );
		$to   = untrailingslashit( $s['live'][ $key ] );
		if ( $from === $to ) {
			continue;
		}
		$from_bare = preg_replace( '#^https?:#i', '', $from );
		$to_bare   = preg_replace( '#^https?:#i', '', $to );
		$set       = array(
			'http:' . $from_bare  => $to,
			'https:' . $from_bare => $to,
			$from_bare            => $to_bare,
		);
		foreach ( $set as $a => $b ) {
			$pairs[ $a ]                 = $b;
			$pairs[ $json( $a ) ]        = $json( $b );
			$pairs[ rawurlencode( $a ) ] = rawurlencode( $b );
		}
	}

	$from = rtrim( $s['manifest']['abspath'], '/\\' );
	$to   = rtrim( $s['live']['abspath'], '/\\' );
	if ( $from !== $to ) {
		foreach ( array_unique( array( $from, str_replace( '\\', '/', $from ), str_replace( '/', '\\', $from ) ) ) as $a ) {
			$pairs[ $a ]          = $to;
			$pairs[ $json( $a ) ] = $json( $to );
		}
	}

	foreach ( $pairs as $a => $b ) {
		if ( $a === $b || '' === $a ) {
			unset( $pairs[ $a ] );
		}
	}
	return $pairs;
}

/**
 * Cheap substrings that every replaceable value contains. Used to skip rows quickly.
 *
 * @param array $s     State.
 * @param array $pairs Replace pairs.
 * @return array
 */
function migrate_lift_replace_needles( $s, $pairs ) {
	$needles = array();
	foreach ( array( 'siteurl', 'home' ) as $key ) {
		$host = wp_parse_url( $s['manifest'][ $key ], PHP_URL_HOST );
		if ( $host ) {
			$needles[] = $host;
		}
	}
	foreach ( array_keys( $pairs ) as $from ) {
		if ( ! preg_match( '#^(https?:|https?%3A|//|\\\\/|%2F)#i', $from ) ) {
			$needles[] = $from; // Paths.
		}
	}
	return array_values( array_unique( $needles ) );
}

/**
 * Replaces inside a value, keeping serialized data valid.
 *
 * @param mixed $value Value.
 * @param array $pairs Pairs.
 * @return mixed
 */
function migrate_lift_replace_value( $value, $pairs ) {
	if ( is_string( $value ) ) {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore
			if ( false !== $data || 'b:0;' === $value ) {
				return serialize( migrate_lift_replace_value( $data, $pairs ) ); // phpcs:ignore
			}
		}
		return strtr( $value, $pairs );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = migrate_lift_replace_value( $item, $pairs );
		}
		return $value;
	}
	if ( $value instanceof stdClass ) {
		foreach ( get_object_vars( $value ) as $key => $item ) {
			$value->$key = migrate_lift_replace_value( $item, $pairs );
		}
	}
	return $value; // Other objects stay as they are; their class is not loaded here.
}

/**
 * Search and replace over every temporary table.
 *
 * @param array $s State.
 * @return bool True when finished.
 */
function migrate_lift_import_replace( &$s ) {
	if ( ! isset( $s['r_tables'] ) ) {
		$s['r_tables'] = migrate_lift_tables( MIGRATE_LIFT_TMP_PREFIX );
		$s['rt']       = 0;
		$s['r_last']   = null;
		$s['r_off']    = 0;
	}

	$pairs = migrate_lift_replace_pairs( $s );
	if ( ! $pairs ) {
		$s['rt'] = count( $s['r_tables'] );
		return true;
	}
	$needles = migrate_lift_replace_needles( $s, $pairs );
	$batch   = 100;

	while ( $s['rt'] < count( $s['r_tables'] ) && ! migrate_lift_out_of_time() ) {
		$table = $s['r_tables'][ $s['rt'] ];
		$info  = migrate_lift_table_info( $table );
		$text  = array();
		foreach ( $info['cols'] as $i => $col ) {
			if ( $info['text'][ $i ] && ! in_array( $col, $info['pk'], true ) ) {
				$text[] = $col;
			}
		}

		$rows = 0;
		if ( ! $text ) {
			$rows = 0;
		} elseif ( ! $info['pk'] ) {
			// No key to update rows by: plain REPLACE(), not serialization-safe, but such tables are rare.
			foreach ( $text as $col ) {
				foreach ( $pairs as $from => $to ) {
					migrate_lift_q( 'UPDATE %i SET %i = REPLACE(%i, %s, %s)', array( $table, $col, $col, $from, $to ) );
				}
			}
		} else {
			$columns = array_merge( $info['pk'], $text );
			$select  = migrate_lift_name_placeholders( count( $columns ) );
			$order   = migrate_lift_name_placeholders( count( $info['pk'] ) );
			$args    = array_merge( $columns, array( $table ) );
			if ( 1 === count( $info['pk'] ) ) {
				// Single key: only fetch rows that contain a needle, paging by key.
				$match = array();
				foreach ( $text as $col ) {
					foreach ( $needles as $needle ) {
						$match[] = 'INSTR(%i, %s) > 0';
						array_push( $args, $col, $needle );
					}
				}
				$where = '(' . implode( ' OR ', $match ) . ')';
				if ( null !== $s['r_last'] ) {
					$where .= ' AND %i > %s';
					array_push( $args, $info['pk'][0], $s['r_last'] );
				}
				$sql = "SELECT $select FROM %i WHERE $where ORDER BY $order LIMIT %d";
				array_push( $args, $info['pk'][0], $batch );
			} else {
				$sql  = "SELECT $select FROM %i ORDER BY $order LIMIT %d, %d";
				$args = array_merge( $args, $info['pk'], array( (int) $s['r_off'], $batch ) );
			}

			$fetch = migrate_lift_rows( $sql, $args, ARRAY_A );
			$rows  = count( $fetch );
			foreach ( $fetch as $row ) {
				$set = array();
				foreach ( $text as $col ) {
					$value = $row[ $col ];
					if ( null === $value || ! migrate_lift_has_needle( $value, $needles ) ) {
						continue;
					}
					$new = migrate_lift_replace_value( $value, $pairs );
					if ( $new !== $value ) {
						$set[ $col ] = $new;
					}
				}
				if ( $set ) {
					$key = array();
					foreach ( $info['pk'] as $col ) {
						$key[ $col ] = $row[ $col ];
					}
					migrate_lift_update_row( $table, $set, $key );
				}
				$s['r_last'] = $row[ $info['pk'][0] ];
			}
			unset( $fetch );
		}

		if ( $rows < $batch ) {
			++$s['rt'];
			$s['r_last'] = null;
			$s['r_off']  = 0;
		} else {
			$s['r_off'] += $batch;
		}
	}

	return $s['rt'] >= count( $s['r_tables'] );
}

/**
 * Whether a value contains any needle.
 *
 * @param string $value   Value.
 * @param array  $needles Needles.
 * @return bool
 */
function migrate_lift_has_needle( $value, $needles ) {
	foreach ( $needles as $needle ) {
		if ( false !== stripos( $value, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Final fixes on the temporary tables before they go live.
 *
 * @param array $s State.
 */
function migrate_lift_import_fix_options( $s ) {
	global $wpdb;
	$options = MIGRATE_LIFT_TMP_PREFIX . 'options';
	$old     = $s['manifest']['prefix'];
	$new     = $s['live']['prefix'];

	foreach ( array( 'home', 'siteurl' ) as $key ) {
		migrate_lift_q( 'UPDATE %i SET option_value = %s WHERE option_name = %s', array( $options, $s['live'][ $key ], $key ) );
	}

	// Cached data from the old site; WordPress rebuilds it.
	migrate_lift_q(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name = %s',
		array( $options, $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%', 'rewrite_rules' )
	);

	// The table prefix is part of some keys.
	if ( $old !== $new ) {
		migrate_lift_q( 'UPDATE %i SET option_name = %s WHERE option_name = %s', array( $options, $new . 'user_roles', $old . 'user_roles' ) );
		migrate_lift_q(
			'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s',
			array( MIGRATE_LIFT_TMP_PREFIX . 'usermeta', $new, strlen( $old ) + 1, $wpdb->esc_like( $old ) . '%' )
		);
	}

	// Keep this plugin active under the folder name it has here.
	$rows = migrate_lift_rows( 'SELECT option_value FROM %i WHERE option_name = %s', array( $options, 'active_plugins' ) );
	if ( $rows ) {
		$active = maybe_unserialize( $rows[0][0] );
		$active = array_values( array_diff( is_array( $active ) ? $active : array(), array( $s['manifest']['plugin'] ?? $s['live']['plugin'], $s['live']['plugin'] ) ) );
		array_unshift( $active, $s['live']['plugin'] );
		migrate_lift_q( 'UPDATE %i SET option_value = %s WHERE option_name = %s', array( $options, serialize( $active ), 'active_plugins' ) ); // phpcs:ignore
	}
}

/**
 * Swaps wp-content and the database tables. Old files go to the trash folder and old tables
 * get MIGRATE_LIFT_OLD_PREFIX; cleanup deletes both. If anything fails, the file moves are undone.
 *
 * @param array $s State.
 */
function migrate_lift_import_swap( $s ) {
	global $wpdb;
	$content = migrate_lift_content_dir();
	$new     = migrate_lift_tmp( 'import-tmp', 'wp-content' );
	$trash   = migrate_lift_tmp( 'trash' );
	$self    = dirname( $s['live']['plugin'] );
	$skip    = array( $self, dirname( $s['manifest']['plugin'] ) );
	$moves   = array();

	$move = function ( $from, $to ) use ( &$moves ) {
		if ( ! file_exists( $from ) ) {
			return;
		}
		if ( ! migrate_lift_move( $from, $to ) ) {
			throw new Exception( esc_html( 'Could not move ' . $from . '. Check file permissions.' ) );
		}
		$moves[] = array( $from, $to );
	};

	if ( ! is_dir( $new ) ) {
		throw new Exception( esc_html__( 'The extracted wp-content folder is missing. Import again.', 'migrate-lift' ) );
	}

	try {
		wp_mkdir_p( $trash . '/plugins' );

		// 1. Old wp-content out (except this plugin and its storage).
		foreach ( migrate_lift_ls( $content ) as $item ) {
			if ( MIGRATE_LIFT_STORE === $item ) {
				continue;
			}
			if ( 'plugins' === $item && is_dir( "$content/plugins" ) ) {
				foreach ( migrate_lift_ls( "$content/plugins" ) as $plugin ) {
					if ( $plugin !== $self ) {
						$move( "$content/plugins/$plugin", "$trash/plugins/$plugin" );
					}
				}
				continue;
			}
			$move( "$content/$item", "$trash/$item" );
		}

		// 2. New wp-content in.
		foreach ( migrate_lift_ls( $new ) as $item ) {
			if ( MIGRATE_LIFT_STORE === $item ) {
				continue;
			}
			if ( 'plugins' === $item ) {
				wp_mkdir_p( "$content/plugins" );
				foreach ( migrate_lift_ls( "$new/plugins" ) as $plugin ) {
					if ( ! in_array( $plugin, $skip, true ) ) {
						$move( "$new/plugins/$plugin", "$content/plugins/$plugin" );
					}
				}
				continue;
			}
			$move( "$new/$item", "$content/$item" );
		}

		// 3. Tables: live → MIGRATE_LIFT_OLD_PREFIX, temporary → live prefix, in one atomic RENAME.
		$prefix  = $wpdb->prefix;
		$renames = array();
		foreach ( migrate_lift_tables( $prefix ) as $table ) {
			if ( 0 !== strpos( $table, MIGRATE_LIFT_TMP_PREFIX ) && 0 !== strpos( $table, MIGRATE_LIFT_OLD_PREFIX ) ) {
				$renames[] = array( $table, MIGRATE_LIFT_OLD_PREFIX . substr( $table, strlen( $prefix ) ) );
			}
		}
		$imported = migrate_lift_tables( MIGRATE_LIFT_TMP_PREFIX );
		if ( ! in_array( MIGRATE_LIFT_TMP_PREFIX . 'options', $imported, true ) ) {
			throw new Exception( esc_html__( 'The imported options table is missing. Import again.', 'migrate-lift' ) );
		}
		foreach ( $imported as $table ) {
			$renames[] = array( $table, $prefix . substr( $table, strlen( MIGRATE_LIFT_TMP_PREFIX ) ) );
		}
		migrate_lift_rename_tables( $renames );
	} catch ( Throwable $e ) {
		foreach ( array_reverse( $moves ) as $done ) {
			migrate_lift_move( $done[1], $done[0] );
		}
		throw $e;
	}

	// 4. Drop caches that still hold the old site. The new site is already live, so a failing
	// purge (the old cache plugin's code is still loaded in this request) must not stop the import.
	try {
		wp_cache_flush();
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
			@sg_cachepress_purge_everything(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	} catch ( Throwable $e ) {
		unset( $e );
	}
}

/**
 * Runs one cleanup step after a finished import. Authorised by the import's one-time token.
 *
 * @param string $token Token.
 * @return array
 */
function migrate_lift_cleanup_step( $token ) {
	migrate_lift_timer_start();
	$s = migrate_lift_state_get( 'import' );
	if ( ! $s || 'cleanup' !== $s['phase'] || ! hash_equals( $s['token'], (string) $token ) ) {
		throw new Exception( esc_html__( 'Invalid cleanup request.', 'migrate-lift' ) );
	}

	if ( ! migrate_lift_rmdir( migrate_lift_tmp( 'trash' ) ) || ! migrate_lift_rmdir( migrate_lift_tmp( 'import-tmp' ) ) ) {
		return migrate_lift_progress( 50, 'Deleting the old files…' );
	}
	foreach ( migrate_lift_tables( MIGRATE_LIFT_OLD_PREFIX ) as $table ) {
		migrate_lift_q( 'DROP TABLE IF EXISTS %i', array( $table ) );
	}
	if ( $s['delete_after'] && is_file( migrate_lift_dir( $s['file'] ) ) ) {
		wp_delete_file( migrate_lift_dir( $s['file'] ) );
	}
	migrate_lift_state_delete( 'import' );

	return migrate_lift_progress( 100, 'Old files and tables deleted.', array( 'done' => true ) );
}

/**
 * Removes every temporary file and table (admin button). Archives are kept.
 *
 * @return array
 */
function migrate_lift_clean_step() {
	migrate_lift_timer_start();
	foreach ( array( 'export-tmp', 'import-tmp', 'trash' ) as $dir ) {
		if ( ! migrate_lift_rmdir( migrate_lift_tmp( $dir ) ) ) {
			return migrate_lift_progress( 50, 'Deleting temporary files…' );
		}
	}
	foreach ( array_merge( glob( migrate_lift_dir( '*.part' ) ), glob( migrate_lift_private_dir( '*-state.json' ) ) ) as $file ) {
		wp_delete_file( $file );
	}
	foreach ( array_merge( migrate_lift_tables( MIGRATE_LIFT_TMP_PREFIX ), migrate_lift_tables( MIGRATE_LIFT_OLD_PREFIX ) ) as $table ) {
		migrate_lift_q( 'DROP TABLE IF EXISTS %i', array( $table ) );
	}
	return migrate_lift_progress( 100, 'Temporary files removed.', array( 'done' => true ) );
}
