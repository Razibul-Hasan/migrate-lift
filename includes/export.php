<?php
/**
 * Export: scan wp-content, dump the database, stream everything into one .tgz.
 *
 * Archive layout:
 *   migratelift.json  manifest (source URLs, paths, prefix, versions)
 *   database.sql      one statement per line, table prefix replaced by MIGRATE_LIFT_TOKEN
 *   wp-content/...    themes, plugins, uploads and the rest of wp-content
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

/**
 * Runs one export step.
 *
 * @param string $step "start" or "continue".
 * @return array
 */
function migrate_lift_export_step( $step ) {
	migrate_lift_timer_start();

	if ( 'start' === $step ) {
		migrate_lift_rmdir( migrate_lift_tmp( 'export-tmp' ) );
		foreach ( glob( migrate_lift_dir( '*.part' ) ) as $part ) {
			wp_delete_file( $part );
		}
		wp_mkdir_p( migrate_lift_tmp( 'export-tmp' ) );

		$host = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		migrate_lift_state_put(
			'export',
			array(
				'phase' => 'scan',
				'name'  => $host . '-' . wp_date( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 12, false ) ) . '.tgz',
			)
		);
		return migrate_lift_progress( 1, 'Scanning files…' );
	}

	$s = migrate_lift_state_get( 'export' );
	if ( ! $s ) {
		throw new Exception( esc_html__( 'No export is running. Start it again.', 'migrate-lift' ) );
	}

	if ( 'scan' === $s['phase'] ) {
		migrate_lift_export_scan( $s );
		$s['phase'] = 'db';
		migrate_lift_state_put( 'export', $s );
		return migrate_lift_progress( 4, sprintf( 'Found %s files (%s). Exporting the database…', number_format_i18n( $s['files'] ), size_format( $s['bytes'] ) ) );
	}

	if ( 'db' === $s['phase'] ) {
		if ( migrate_lift_export_db( $s ) ) {
			$s['phase'] = 'archive';
		}
		migrate_lift_state_put( 'export', $s );
		$done = count( $s['tables'] ) ? $s['t'] / count( $s['tables'] ) : 1;
		return migrate_lift_progress( 4 + 8 * $done, sprintf( 'Exporting the database… table %d of %d', min( $s['t'] + 1, count( $s['tables'] ) ), count( $s['tables'] ) ) );
	}

	if ( 'archive' === $s['phase'] ) {
		if ( ! migrate_lift_export_archive( $s ) ) {
			migrate_lift_state_put( 'export', $s );
			$done = $s['bytes'] ? $s['done_bytes'] / $s['bytes'] : 1;
			return migrate_lift_progress( 12 + 87 * $done, sprintf( 'Compressing… %s of %s', size_format( $s['done_bytes'] ), size_format( $s['bytes'] ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( migrate_lift_dir( $s['name'] . '.part' ), migrate_lift_dir( $s['name'] ) );
		migrate_lift_rmdir( migrate_lift_tmp( 'export-tmp' ) );
		migrate_lift_state_delete( 'export' );

		return migrate_lift_progress(
			100,
			sprintf( 'Export finished: %s (%s).', $s['name'], size_format( filesize( migrate_lift_dir( $s['name'] ) ), 1 ) ),
			array(
				'done'     => true,
				'file'     => $s['name'],
				'download' => migrate_lift_download_url( $s['name'] ),
			)
		);
	}

	throw new Exception( esc_html__( 'Unknown export phase.', 'migrate-lift' ) );
}

/**
 * Paths inside wp-content that are never exported: this plugin's storage, backups, caches, logs.
 *
 * @param string $rel Path relative to wp-content, with forward slashes.
 * @return bool
 */
function migrate_lift_is_excluded( $rel ) {
	static $rules = null;
	if ( null === $rules ) {
		$rules = apply_filters(
			'migrate_lift_exclude',
			array(
				MIGRATE_LIFT_STORE,
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

	if ( in_array( basename( $rel ), array( '.git', '.svn', 'node_modules', '.DS_Store', 'Thumbs.db', 'error_log', 'php_errorlog' ), true ) ) {
		return true;
	}
	foreach ( $rules as $rule ) {
		if ( fnmatch( $rule, $rel, FNM_PATHNAME ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Writes the list of files to export.
 *
 * @param array $s State.
 */
function migrate_lift_export_scan( &$s ) {
	$base   = str_replace( '\\', '/', migrate_lift_content_dir() );
	$cut    = strlen( $base ) + 1;
	$list   = fopen( migrate_lift_tmp( 'export-tmp', 'files.txt' ), 'wb' );
	$filter = new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
		function ( $item ) use ( $cut ) {
			return ! migrate_lift_is_excluded( substr( str_replace( '\\', '/', $item->getPathname() ), $cut ) );
		}
	);

	$s['files'] = 0;
	$s['bytes'] = 0;
	foreach ( new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD ) as $item ) {
		$rel = substr( str_replace( '\\', '/', $item->getPathname() ), $cut );
		if ( ! $item->isFile() || false !== strpos( $rel, "\n" ) ) {
			continue;
		}
		fwrite( $list, $rel . "\n" );
		++$s['files'];
		$s['bytes'] += $item->getSize();
	}
	fclose( $list );
}

/**
 * Dumps the site's tables into database.sql, in batches.
 *
 * @param array $s State.
 * @return bool True when every table is dumped.
 */
function migrate_lift_export_db( &$s ) {
	global $wpdb;
	migrate_lift_db_session();

	if ( ! isset( $s['tables'] ) ) {
		$s['tables']   = migrate_lift_tables( $wpdb->prefix );
		$s['t']        = 0;
		$s['row']      = 0;
		$s['sql_size'] = 0;
	}

	$path = migrate_lift_tmp( 'export-tmp', 'database.sql' );
	migrate_lift_truncate( $path, $s['sql_size'] );
	$out = fopen( $path, 'ab' );
	$cut = strlen( $wpdb->prefix );
	$max = 500;

	while ( $s['t'] < count( $s['tables'] ) && ! migrate_lift_out_of_time() ) {
		$table  = $s['tables'][ $s['t'] ];
		$target = migrate_lift_name( MIGRATE_LIFT_TOKEN . substr( $table, $cut ) );

		if ( 0 === $s['row'] ) {
			$created = migrate_lift_rows( 'SHOW CREATE TABLE ' . migrate_lift_name( $table ) );
			$create  = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE ' . $target, $created[0][1] );
			$create  = str_replace( 'REFERENCES `' . $wpdb->prefix, 'REFERENCES `' . MIGRATE_LIFT_TOKEN, $create );
			fwrite( $out, "DROP TABLE IF EXISTS $target;\n" . str_replace( array( "\r\n", "\n" ), ' ', $create ) . ";\n" );
		}

		$info   = migrate_lift_table_info( $table );
		$cols   = implode( ',', array_map( 'migrate_lift_name', $info['cols'] ) );
		$order  = $info['pk'] ? ' ORDER BY ' . implode( ',', array_map( 'migrate_lift_name', $info['pk'] ) ) : '';
		$batch  = isset( $s['batch'] ) ? (int) $s['batch'] : 100;
		$fetch  = migrate_lift_rows( "SELECT $cols FROM " . migrate_lift_name( $table ) . "$order LIMIT " . (int) $s['row'] . ", $batch" );
		$head   = "INSERT INTO $target ($cols) VALUES ";
		$values = array();
		$length = 0;
		$bytes  = 0;
		$rows   = count( $fetch );

		foreach ( $fetch as $row ) {
			foreach ( $row as $i => $value ) {
				if ( null === $value ) {
					$row[ $i ] = 'NULL';
				} elseif ( $info['bit'][ $i ] ) {
					$row[ $i ] = migrate_lift_bit_literal( $value );
				} elseif ( $info['bin'][ $i ] ) {
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
				fwrite( $out, $head . implode( ',', $values ) . ";\n" );
				$values = array();
				$length = 0;
			}
		}
		unset( $fetch );
		if ( $values ) {
			fwrite( $out, $head . implode( ',', $values ) . ";\n" );
		}

		if ( $rows < $batch ) {
			++$s['t'];
			$s['row'] = 0;
			unset( $s['batch'] );
		} else {
			$s['row']  += $batch;
			$s['batch'] = migrate_lift_next_batch( $batch, $bytes, $max );
		}
	}

	fclose( $out );
	clearstatcache();
	$s['sql_size'] = filesize( $path );

	return $s['t'] >= count( $s['tables'] );
}

/**
 * Streams the manifest, database.sql and every listed file into the archive.
 *
 * @param array $s State.
 * @return bool True when the archive is complete.
 */
function migrate_lift_export_archive( &$s ) {
	$part = migrate_lift_dir( $s['name'] . '.part' );

	if ( ! isset( $s['arc_size'] ) ) {
		$sql              = migrate_lift_tmp( 'export-tmp', 'database.sql' );
		$s['arc_size']    = 0;
		$s['list_pos']    = 0;
		$s['done_bytes']  = 0;
		$s['bytes']      += filesize( $sql );
		$s['cur']         = array(
			'abs'  => $sql,
			'name' => 'database.sql',
			'size' => filesize( $sql ),
			'done' => 0,
			'head' => false,
		);
		$s['manifest']    = array_merge(
			migrate_lift_site_info(),
			array(
				'format'  => 1,
				'created' => gmdate( 'c' ),
				'files'   => $s['files'],
				'bytes'   => $s['bytes'],
			)
		);
		$s['manifest_on'] = false;
	}

	migrate_lift_truncate( $part, $s['arc_size'] );
	$tar = new Migrate_Lift_Tar_Writer( $part );

	if ( ! $s['manifest_on'] ) {
		$manifest_json = wp_json_encode( $s['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$tar->add_string( 'migratelift.json', $manifest_json );
		$s['manifest_on'] = true;
	}

	$list     = fopen( migrate_lift_tmp( 'export-tmp', 'files.txt' ), 'rb' );
	$base     = migrate_lift_content_dir() . '/';
	$finished = false;
	fseek( $list, $s['list_pos'] );

	while ( ! migrate_lift_out_of_time() ) {
		if ( empty( $s['cur'] ) ) {
			$line = fgets( $list );
			if ( false === $line ) {
				$tar->finish();
				$finished = true;
				break;
			}
			$s['list_pos'] = ftell( $list );
			$rel           = rtrim( $line, "\n" );
			$abs           = $base . $rel;
			if ( ! is_file( $abs ) || ! is_readable( $abs ) ) {
				continue; // Deleted since the scan.
			}
			$s['cur'] = array(
				'abs'  => $abs,
				'name' => 'wp-content/' . $rel,
				'size' => filesize( $abs ),
				'done' => 0,
				'head' => false,
			);
		}

		if ( migrate_lift_export_file( $tar, $s['cur'] ) ) {
			$s['done_bytes'] += $s['cur']['size'];
			$s['cur']         = null;
		}
	}

	fclose( $list );
	$tar->close();
	clearstatcache();
	$s['arc_size'] = filesize( $part );

	return $finished;
}

/**
 * Copies one file into the archive, stopping early when time is up.
 *
 * @param Migrate_Lift_Tar_Writer $tar Archive.
 * @param array                   $cur Current file state.
 * @return bool True when the file is complete.
 */
function migrate_lift_export_file( $tar, &$cur ) {
	if ( ! $cur['head'] ) {
		$tar->header( $cur['name'], $cur['size'], (int) @filemtime( $cur['abs'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$cur['head'] = true;
	}

	$in = @fopen( $cur['abs'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( $in && $cur['done'] ) {
		fseek( $in, $cur['done'] );
	}
	while ( $cur['done'] < $cur['size'] ) {
		$want = min( 1048576, $cur['size'] - $cur['done'] );
		$data = $in ? fread( $in, $want ) : '';
		if ( false === $data || '' === $data ) {
			$data = str_repeat( "\0", $want ); // The file shrank; keep the tar sizes right.
		}
		$tar->write( $data );
		$cur['done'] += strlen( $data );
		if ( migrate_lift_out_of_time() ) {
			break;
		}
	}
	if ( $in ) {
		fclose( $in );
	}

	if ( $cur['done'] < $cur['size'] ) {
		return false;
	}
	$tar->pad( $cur['size'] );
	return true;
}
