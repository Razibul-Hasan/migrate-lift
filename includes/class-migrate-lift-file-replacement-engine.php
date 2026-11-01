<?php
/**
 * MigrateLift File Replacement Engine.
 *
 * Implements safe destination file replacement policies with staging,
 * diff previews, canonical path protection, and automatic rollback.
 *
 * Supported Modes:
 * 1. add_missing: Add missing files only (preserves all destination files).
 * 2. overwrite_matching: Overwrite matching files, keep destination-only files (safe default).
 * 3. sync_managed: Synchronize managed WordPress content (prunes destination-only managed files after verification).
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

class Migrate_Lift_File_Replacement_Engine {

	const MODE_ADD_MISSING        = 'add_missing';
	const MODE_OVERWRITE_MATCHING = 'overwrite_matching';
	const MODE_SYNC_MANAGED       = 'sync_managed';

	/**
	 * Returns supported file replacement modes with localized labels and descriptions.
	 *
	 * @return array
	 */
	public static function get_supported_modes() {
		return array(
			self::MODE_OVERWRITE_MATCHING => array(
				'id'          => self::MODE_OVERWRITE_MATCHING,
				'title'       => __( 'Overwrite matching files (Recommended)', 'migrate-lift' ),
				'description' => __( 'Replaces files present in the backup with newer archive copies. Any files that exist only on this destination site are preserved.', 'migrate-lift' ),
				'recommended' => true,
				'destructive' => false,
			),
			self::MODE_ADD_MISSING        => array(
				'id'          => self::MODE_ADD_MISSING,
				'title'       => __( 'Add missing only', 'migrate-lift' ),
				'description' => __( 'Only extracts files from the backup that do not already exist on this site. Existing files are never modified or overwritten.', 'migrate-lift' ),
				'recommended' => false,
				'destructive' => false,
			),
			self::MODE_SYNC_MANAGED       => array(
				'id'          => self::MODE_SYNC_MANAGED,
				'title'       => __( 'Synchronize managed WordPress content', 'migrate-lift' ),
				'description' => __( 'Extracts backup files and prunes destination-only files inside themes, plugins, and uploads after verified staging. Requires explicit confirmation.', 'migrate-lift' ),
				'recommended' => false,
				'destructive' => true,
			),
		);
	}

	/**
	 * Checks whether a relative path inside wp-content is protected and must NEVER be deleted or overwritten.
	 *
	 * @param string $relative_path Path relative to ABSPATH or WP_CONTENT_DIR.
	 * @return bool True if protected.
	 */
	public static function is_protected_path( $relative_path ) {
		$clean = str_replace( '\\', '/', trim( (string) $relative_path, '/\\' ) );

		// 1. Root configuration & web server rules
		if (
			'wp-config.php' === $clean ||
			'.htaccess' === $clean ||
			'web.config' === $clean ||
			'.user.ini' === $clean ||
			'php.ini' === $clean ||
			'nginx.conf' === $clean
		) {
			return true;
		}

		// 2. Anything outside wp-content
		if ( 0 !== strpos( $clean, 'wp-content/' ) && 'wp-content' !== $clean ) {
			return true;
		}

		// Strip leading wp-content/ for folder inspection
		$sub = substr( $clean, strlen( 'wp-content/' ) );

		// 3. MigrateLift private storage folder & recovery archives
		$store_name = defined( 'MIGRATE_LIFT_STORE' ) ? MIGRATE_LIFT_STORE : 'migrate-lift-files';
		if ( 0 === strpos( $sub, $store_name . '/' ) || $sub === $store_name ) {
			return true;
		}

		// 4. Active MigrateLift plugin runtime folder
		$self_plugin = defined( 'MIGRATE_LIFT_FILE' ) ? dirname( plugin_basename( MIGRATE_LIFT_FILE ) ) : 'migrate-lift';
		if ( 0 === strpos( $sub, 'plugins/' . $self_plugin . '/' ) || $sub === 'plugins/' . $self_plugin ) {
			return true;
		}

		// 5. Must-Use plugins
		if ( 0 === strpos( $sub, 'mu-plugins/' ) || $sub === 'mu-plugins' ) {
			return true;
		}

		// 6. Dot files and VCS inside wp-content
		if ( preg_match( '#(^|/)\.(git|svn|hg|env|htaccess|user\.ini)#i', $sub ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Validates whether an archive entry path is safe for extraction.
	 * Rejects path traversal, null bytes, absolute paths, and Windows drive letters.
	 *
	 * @param string $path Archive entry path.
	 * @return bool
	 */
	public static function is_safe_entry_path( $path ) {
		$clean = str_replace( '\\', '/', (string) $path );

		if ( '' === $clean || false !== strpos( $clean, "\0" ) ) {
			return false;
		}
		if ( '/' === $clean[0] || preg_match( '#^[a-zA-Z]:#', $clean ) ) {
			return false;
		}
		if ( preg_match( '#(^|/)\.\.(/|$)#', $clean ) ) {
			return false;
		}

		// Only allow wp-content entries and metadata/database
		if (
			'migratelift.json' !== $clean &&
			'database.sql' !== $clean &&
			0 !== strpos( $clean, 'wp-content/' )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Generates a comprehensive diff preview between archive files and destination filesystem.
	 *
	 * @param string $archive_name Filename of backup archive.
	 * @param string $file_mode    Selected replacement mode.
	 * @return array Diff preview metrics and file lists.
	 * @throws Exception On missing archive or read failure.
	 */
	public static function generate_diff_preview( $archive_name, $file_mode = self::MODE_OVERWRITE_MATCHING ) {
		$archive_path = migrate_lift_archive_path( $archive_name );
		if ( ! is_file( $archive_path ) ) {
			throw new Exception( esc_html__( 'Archive file not found in storage library.', 'migrate-lift' ) );
		}

		$content_root = untrailingslashit( WP_CONTENT_DIR );
		$is_zip       = (bool) preg_match( '/\.zip$/i', $archive_path );

		$archive_files = array(); // rel_path => size
		$total_bytes   = 0;

		if ( $is_zip ) {
			if ( ! class_exists( 'Migrate_Lift_Zip_Reader' ) ) {
				require_once dirname( __FILE__ ) . '/class-migrate-lift-zip.php';
			}
			$zip = new Migrate_Lift_Zip_Reader( $archive_path );
			foreach ( $zip->get_entries() as $entry ) {
				$name = str_replace( '\\', '/', $entry['name'] );
				if ( ! self::is_safe_entry_path( $name ) || self::is_protected_path( $name ) ) {
					continue;
				}
				if ( 0 === strpos( $name, 'wp-content/' ) && ! empty( $entry['size'] ) ) {
					$rel = substr( $name, strlen( 'wp-content/' ) );
					$archive_files[ $rel ] = (int) $entry['size'];
					$total_bytes          += (int) $entry['size'];
				}
			}
			$zip->close();
		} else {
			if ( ! class_exists( 'Migrate_Lift_Tar_Reader' ) ) {
				require_once dirname( __FILE__ ) . '/class-migrate-lift-tar.php';
			}
			$tar = new Migrate_Lift_Tar_Reader( $archive_path );
			while ( ( $entry = $tar->next() ) !== null ) {
				$name = str_replace( '\\', '/', $entry['name'] );
				if ( ! self::is_safe_entry_path( $name ) || self::is_protected_path( $name ) ) {
					$tar->skip( $entry['size'] );
					$tar->skip_pad( $entry['size'] );
					continue;
				}
				if ( 0 === strpos( $name, 'wp-content/' ) && in_array( $entry['type'], array( '0', '7' ), true ) ) {
					$rel = substr( $name, strlen( 'wp-content/' ) );
					$archive_files[ $rel ] = (int) $entry['size'];
					$total_bytes          += (int) $entry['size'];
				}
				$tar->skip( $entry['size'] );
				$tar->skip_pad( $entry['size'] );
			}
			$tar->close();
		}

		$creates   = array();
		$updates   = array();
		$skips     = array();
		$conflicts = array();

		// Compare archive files against destination
		foreach ( $archive_files as $rel => $size ) {
			$dest_file = $content_root . '/' . $rel;
			$dest_exists = file_exists( $dest_file );

			if ( ! $dest_exists ) {
				$creates[] = array(
					'path' => 'wp-content/' . $rel,
					'size' => $size,
				);
			} else {
				// Destination file exists
				if ( self::MODE_ADD_MISSING === $file_mode ) {
					$skips[] = array(
						'path'   => 'wp-content/' . $rel,
						'size'   => $size,
						'reason' => __( 'File exists on destination (Add missing mode)', 'migrate-lift' ),
					);
				} else {
					// Check writability
					if ( ! self::is_file_writable( $dest_file ) ) {
						$conflicts[] = array(
							'path'   => 'wp-content/' . $rel,
							'reason' => __( 'Destination file is not writable by PHP process.', 'migrate-lift' ),
						);
					}
					$updates[] = array(
						'path' => 'wp-content/' . $rel,
						'size' => $size,
					);
				}
			}
		}

		// In sync_managed mode, find destination-only managed files to prune
		$deletes = array();
		if ( self::MODE_SYNC_MANAGED === $file_mode ) {
			$managed_subdirs = array( 'themes', 'plugins', 'uploads' );
			foreach ( $managed_subdirs as $subdir ) {
				$dir_path = $content_root . '/' . $subdir;
				if ( ! is_dir( $dir_path ) ) {
					continue;
				}

				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir_path, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ( $iterator as $item ) {
					if ( $item->isDir() ) {
						continue;
					}
					$full_path = str_replace( '\\', '/', $item->getPathname() );
					$rel = substr( $full_path, strlen( $content_root . '/' ) );

					// Skip protected files
					if ( self::is_protected_path( 'wp-content/' . $rel ) ) {
						continue;
					}

					// If destination file is NOT in archive, mark for pruning
					if ( ! isset( $archive_files[ $rel ] ) ) {
						$deletes[] = array(
							'path' => 'wp-content/' . $rel,
							'size' => (int) $item->getSize(),
						);
					}
				}
			}
		}

		return array(
			'mode'                         => $file_mode,
			'archive_name'                 => $archive_name,
			'total_incoming_files'         => count( $archive_files ),
			'total_incoming_bytes'         => $total_bytes,
			'counts'                       => array(
				'create' => count( $creates ),
				'update' => count( $updates ),
				'delete' => count( $deletes ),
				'skip'   => count( $skips ),
			),
			'creates'                      => array_slice( $creates, 0, 50 ),
			'updates'                      => array_slice( $updates, 0, 50 ),
			'deletes'                      => array_slice( $deletes, 0, 50 ),
			'skips'                        => array_slice( $skips, 0, 20 ),
			'conflicts'                    => $conflicts,
			'has_conflicts'                => ! empty( $conflicts ),
			'requires_delete_confirmation' => ( count( $deletes ) > 0 ),
		);
	}

	/**
	 * Stages archive wp-content files into a dedicated temporary staging directory.
	 *
	 * @param string $archive_name Filename of archive.
	 * @param string $staging_dir  Destination staging folder.
	 * @return int Number of staged files.
	 * @throws Exception On extract failure or corrupted archive.
	 */
	public static function stage_archive_files( $archive_name, $staging_dir ) {
		$archive_path = migrate_lift_archive_path( $archive_name );
		if ( ! is_file( $archive_path ) ) {
			throw new Exception( esc_html__( 'Archive file not found in storage library.', 'migrate-lift' ) );
		}

		wp_mkdir_p( $staging_dir );
		$is_zip = (bool) preg_match( '/\.zip$/i', $archive_path );
		$count  = 0;

		if ( $is_zip ) {
			if ( ! class_exists( 'Migrate_Lift_Zip_Reader' ) ) {
				require_once dirname( __FILE__ ) . '/class-migrate-lift-zip.php';
			}
			$zip = new Migrate_Lift_Zip_Reader( $archive_path );
			foreach ( $zip->get_entries() as $entry ) {
				$name = str_replace( '\\', '/', $entry['name'] );
				if ( ! self::is_safe_entry_path( $name ) || self::is_protected_path( $name ) ) {
					continue;
				}
				if ( 0 === strpos( $name, 'wp-content/' ) ) {
					$rel = substr( $name, strlen( 'wp-content/' ) );
					$target_file = $staging_dir . '/' . $rel;
					wp_mkdir_p( dirname( $target_file ) );

					$contents = $zip->get_entry_contents( $entry['name'] );
					if ( false === @file_put_contents( $target_file, $contents ) ) {
						$zip->close();
						/* translators: %s: relative file path */
						throw new Exception( esc_html( sprintf( __( 'Could not write staged file: %s', 'migrate-lift' ), $rel ) ) );
					}
					++$count;
				}
			}
			$zip->close();
		} else {
			if ( ! class_exists( 'Migrate_Lift_Tar_Reader' ) ) {
				require_once dirname( __FILE__ ) . '/class-migrate-lift-tar.php';
			}
			$tar = new Migrate_Lift_Tar_Reader( $archive_path );
			while ( ( $entry = $tar->next() ) !== null ) {
				$name = str_replace( '\\', '/', $entry['name'] );
				if ( ! self::is_safe_entry_path( $name ) || self::is_protected_path( $name ) ) {
					$tar->skip( $entry['size'] );
					$tar->skip_pad( $entry['size'] );
					continue;
				}
				if ( 0 === strpos( $name, 'wp-content/' ) ) {
					$rel = substr( $name, strlen( 'wp-content/' ) );
					$target_file = $staging_dir . '/' . $rel;

					if ( '5' === $entry['type'] ) {
						wp_mkdir_p( $target_file );
					} elseif ( in_array( $entry['type'], array( '0', '7' ), true ) ) {
						wp_mkdir_p( dirname( $target_file ) );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct handle for staging tar files.
						$out = @fopen( $target_file, 'wb' );
						if ( ! $out ) {
							$tar->close();
							/* translators: %s: relative file path */
							throw new Exception( esc_html( sprintf( __( 'Could not open staged file for writing: %s', 'migrate-lift' ), $rel ) ) );
						}
						$written = 0;
						while ( $written < $entry['size'] ) {
							$chunk_size = min( 1048576, $entry['size'] - $written );
							$chunk      = $tar->read_exact( $chunk_size );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
							fwrite( $out, $chunk );
							$written += strlen( $chunk );
						}
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $out );
						$tar->skip_pad( $entry['size'] );
						if ( ! empty( $entry['mtime'] ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
							@touch( $target_file, $entry['mtime'] );
						}
						++$count;
						continue;
					}
				}
				$tar->skip( $entry['size'] );
				$tar->skip_pad( $entry['size'] );
			}
			$tar->close();
		}

		return $count;
	}

	/**
	 * Executes safe file replacement with automatic rollback on any failure.
	 *
	 * @param string $archive_name Filename of archive.
	 * @param string $file_mode    Selected mode (add_missing, overwrite_matching, sync_managed).
	 * @param array  $options      Execution options (confirm_delete, etc.).
	 * @return array Execution summary report.
	 * @throws Exception On permission error, blocker, or failure.
	 */
	public static function apply_file_replacement( $archive_name, $file_mode = self::MODE_OVERWRITE_MATCHING, $options = array() ) {
		// 1. Validate recovery checkpoint exists before replacement!
		if ( class_exists( 'Migrate_Lift_Restore_Preflight' ) ) {
			$recovery = Migrate_Lift_Restore_Preflight::get_recovery_status();
			if ( empty( $recovery['has_checkpoint'] ) ) {
				throw new Exception( esc_html__( 'Safety requirement: A verified destination recovery checkpoint must exist before file replacement can execute.', 'migrate-lift' ) );
			}
		}

		// 2. Validate diff and check for required deletion confirmation
		$diff = self::generate_diff_preview( $archive_name, $file_mode );
		if ( $diff['has_conflicts'] ) {
			throw new Exception( esc_html__( 'Cannot execute file replacement: Destination has file permission conflicts.', 'migrate-lift' ) );
		}

		if ( self::MODE_SYNC_MANAGED === $file_mode && $diff['counts']['delete'] > 0 ) {
			if ( empty( $options['confirm_delete'] ) ) {
				throw new Exception( esc_html__( 'Explicit confirmation is required to delete destination-only files under synchronize mode.', 'migrate-lift' ) );
			}
		}

		$id           = uniqid();
		$staging_dir  = migrate_lift_dir( 'staging/restore-files-' . $id );
		$rollback_dir = migrate_lift_dir( 'staging/rollback-files-' . $id );
		$content_root = defined( 'WP_CONTENT_DIR' ) ? untrailingslashit( constant( 'WP_CONTENT_DIR' ) ) : '';

		wp_mkdir_p( $staging_dir );
		wp_mkdir_p( $rollback_dir );

		$created_count = 0;
		$updated_count = 0;
		$deleted_count = 0;
		$moves_applied = array(); // track operations for rollback

		try {
			// Phase A: Stage extracted files into temporary holding directory
			self::stage_archive_files( $archive_name, $staging_dir );

			// Phase B: Iterate through staged files and apply to destination
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $staging_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $item ) {
				if ( $item->isDir() ) {
					continue;
				}
				$staged_path = str_replace( '\\', '/', $item->getPathname() );
				$rel         = substr( $staged_path, strlen( $staging_dir . '/' ) );

				// Safety check: skip protected paths
				if ( self::is_protected_path( 'wp-content/' . $rel ) ) {
					continue;
				}

				$dest_path     = $content_root . '/' . $rel;
				$rollback_path = $rollback_dir . '/' . $rel;

				if ( ! file_exists( $dest_path ) ) {
					// File does not exist in destination -> Create
					wp_mkdir_p( dirname( $dest_path ) );
					// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
					if ( ! @copy( $staged_path, $dest_path ) ) {
						/* translators: %s: relative file path */
						throw new Exception( esc_html( sprintf( __( 'Failed to copy new file to destination: %s', 'migrate-lift' ), $rel ) ) );
					}
					$moves_applied[] = array( 'type' => 'created', 'dest' => $dest_path );
					++$created_count;
				} else {
					// Destination file already exists
					if ( self::MODE_ADD_MISSING === $file_mode ) {
						// In add_missing mode: do not touch existing file!
						continue;
					}

					// Backup original to rollback folder before overwriting
					wp_mkdir_p( dirname( $rollback_path ) );
					// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
					if ( ! @copy( $dest_path, $rollback_path ) ) {
						/* translators: %s: relative file path */
						throw new Exception( esc_html( sprintf( __( 'Failed to backup existing file to rollback snapshot: %s', 'migrate-lift' ), $rel ) ) );
					}

					// Overwrite destination file
					// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
					if ( ! @copy( $staged_path, $dest_path ) ) {
						/* translators: %s: relative file path */
						throw new Exception( esc_html( sprintf( __( 'Failed to overwrite destination file: %s', 'migrate-lift' ), $rel ) ) );
					}
					$moves_applied[] = array( 'type' => 'updated', 'dest' => $dest_path, 'rollback' => $rollback_path );
					++$updated_count;
				}
			}

			// Phase C: If sync_managed mode, prune destination-only files
			if ( self::MODE_SYNC_MANAGED === $file_mode && ! empty( $diff['deletes'] ) ) {
				foreach ( $diff['deletes'] as $del_item ) {
					$rel = substr( $del_item['path'], strlen( 'wp-content/' ) );
					$dest_path = $content_root . '/' . $rel;

					if ( file_exists( $dest_path ) && ! self::is_protected_path( 'wp-content/' . $rel ) ) {
						$rollback_path = $rollback_dir . '/' . $rel;
						wp_mkdir_p( dirname( $rollback_path ) );
						@copy( $dest_path, $rollback_path );

						if ( wp_delete_file( $dest_path ) ) {
							$moves_applied[] = array( 'type' => 'deleted', 'dest' => $dest_path, 'rollback' => $rollback_path );
							++$deleted_count;
						}
					}
				}
			}

			// Clean up staging and rollback directories on success
			if ( function_exists( 'migrate_lift_rmdir' ) ) {
				migrate_lift_rmdir( $staging_dir );
				migrate_lift_rmdir( $rollback_dir );
			}

			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::info(
					'file_replacement_success',
					/* translators: 1: replacement mode, 2: files created count, 3: files updated count, 4: files deleted count */
					sprintf( __( 'File replacement completed (%1$s): %2$d created, %3$d updated, %4$d pruned.', 'migrate-lift' ), $file_mode, $created_count, $updated_count, $deleted_count ),
					array(
						'archive' => $archive_name,
						'mode'    => $file_mode,
						'created' => $created_count,
						'updated' => $updated_count,
						'deleted' => $deleted_count,
					)
				);
			}

			return array(
				'success'       => true,
				'mode'          => $file_mode,
				'files_created' => $created_count,
				'files_updated' => $updated_count,
				'files_deleted' => $deleted_count,
				'total_applied' => $created_count + $updated_count + $deleted_count,
			);

		} catch ( Throwable $e ) {
			// ROLLBACK: Restore every modified or deleted file from rollback directory!
			foreach ( array_reverse( $moves_applied ) as $applied ) {
				if ( 'created' === $applied['type'] && file_exists( $applied['dest'] ) ) {
					wp_delete_file( $applied['dest'] );
				} elseif ( 'updated' === $applied['type'] && ! empty( $applied['rollback'] ) && file_exists( $applied['rollback'] ) ) {
					@copy( $applied['rollback'], $applied['dest'] );
				} elseif ( 'deleted' === $applied['type'] && ! empty( $applied['rollback'] ) && file_exists( $applied['rollback'] ) ) {
					@copy( $applied['rollback'], $applied['dest'] );
				}
			}

			// Clean up staging and rollback
			if ( function_exists( 'migrate_lift_rmdir' ) ) {
				migrate_lift_rmdir( $staging_dir );
				migrate_lift_rmdir( $rollback_dir );
			}

			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::error(
					'file_replacement_rollback',
					/* translators: %s: error message */
					sprintf( __( 'File replacement failed and was rolled back: %s', 'migrate-lift' ), $e->getMessage() ),
					array( 'error' => $e->getMessage(), 'archive' => $archive_name )
				);
			}

			/* translators: %s: error message */
			throw new Exception( esc_html( sprintf( __( 'File replacement interrupted and rolled back: %s', 'migrate-lift' ), $e->getMessage() ) ) );
		}
	}

	/**
	 * Checks whether a file or directory is writable cross-platform.
	 *
	 * @param string $file File path.
	 * @return bool
	 */
	public static function is_file_writable( $file ) {
		if ( function_exists( 'wp_is_writable' ) ) {
			return wp_is_writable( $file );
		}
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$fp = @fopen( $file, 'a' );
			if ( false !== $fp ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return true;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return is_writable( $file );
		}
		$dir = dirname( $file );
		if ( is_dir( $dir ) ) {
			$test_f = $dir . '/.ml_test_' . uniqid();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$fp = @fopen( $test_f, 'w' );
			if ( false !== $fp ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				wp_delete_file( $test_f );
				return true;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		return is_writable( $file );
	}
}
