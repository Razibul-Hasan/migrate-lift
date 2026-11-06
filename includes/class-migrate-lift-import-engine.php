<?php
/**
 * Large Backup Import Engine: Staging, Browser Resumable Chunks, Quarantine & Security Validation.
 *
 * Implements:
 * - Dedicated private staging directory for cPanel File Manager and SFTP uploads.
 * - Strict isolation: only plugin-controlled staging folder is scanned, never arbitrary paths or external URLs.
 * - Quarantine pipeline: all imported and browser-uploaded archives are quarantined until verified.
 * - Security verification: magic bytes signature, path traversal, symlink rules, archive bomb/expansion ratio protection.
 * - Web-root exposure protection with .htaccess deny rules and public exposure detection.
 * - Resumable chunked browser uploads with offset verification, receipts, pause, resume, and cancellation.
 * - Direct integration with Migrate_Lift_Activity_Logger.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

class Migrate_Lift_Import_Engine {

	/**
	 * Dedicated staging directory relative to main storage.
	 */
	const STAGING_SUBDIR = 'import';

	/**
	 * Dedicated quarantine directory relative to main storage.
	 */
	const QUARANTINE_SUBDIR = 'quarantine';

	/**
	 * Archive bomb protection: maximum allowed uncompressed-to-compressed expansion ratio.
	 */
	const MAX_EXPANSION_RATIO = 100;

	/**
	 * Maximum allowed total uncompressed size (200 GB safety ceiling).
	 */
	const MAX_TOTAL_UNCOMPRESSED = 214748364800;

	/**
	 * Maximum allowed file entry count in archive.
	 */
	const MAX_FILE_ENTRIES = 500000;

	/**
	 * Option key for storing web exposure check cache.
	 */
	const OPTION_WEB_EXPOSURE = 'migrate_lift_web_exposure_status';

	/**
	 * Initializes staging and quarantine environments with deny rules.
	 */
	public static function init() {
		self::ensure_directories();
	}

	/**
	 * Ensures staging and quarantine directories exist and are protected against direct web execution.
	 */
	public static function ensure_directories() {
		$staging_dir    = self::get_staging_dir();
		$quarantine_dir = self::get_quarantine_dir();

		foreach ( array( $staging_dir, $quarantine_dir ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$htaccess = $dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
				@file_put_contents( $htaccess, $rules );
			}
			$index = $dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, "<?php\n// Silence is golden.\nexit;\n" );
			}
		}
	}

	/**
	 * Returns the absolute path to the dedicated SFTP/cPanel staging directory.
	 *
	 * @return string
	 */
	public static function get_staging_dir() {
		return migrate_lift_dir( self::STAGING_SUBDIR );
	}

	/**
	 * Returns the absolute path to the quarantine directory.
	 *
	 * @return string
	 */
	public static function get_quarantine_dir() {
		return migrate_lift_dir( self::QUARANTINE_SUBDIR );
	}

	/**
	 * Checks if files inside the storage / staging directory are publicly exposed via HTTP.
	 *
	 * @param bool $force_refresh Whether to bypass cached result.
	 * @return array Status report with exposed boolean and diagnostic details.
	 */
	public static function check_web_exposure( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::OPTION_WEB_EXPOSURE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$quarantine_dir = self::get_quarantine_dir();
		$canary_token   = 'ml-probe-' . wp_generate_password( 16, false );
		$canary_file    = $quarantine_dir . '/' . $canary_token . '.txt';
		$canary_content = 'MIGRATE_LIFT_CANARY_TEST_' . time();

		@file_put_contents( $canary_file, $canary_content );

		$content_url = content_url();
		$relative    = MIGRATE_LIFT_STORE . '/' . self::QUARANTINE_SUBDIR . '/' . $canary_token . '.txt';
		$test_url    = trailingslashit( $content_url ) . $relative;

		$exposed = false;
		$status_code = null;
		$warning = '';

		if ( function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get(
				$test_url,
				array(
					'timeout'     => 5,
					'sslverify'   => apply_filters( 'migrate_lift_ssl_verify', true ),
					'redirection' => 0,
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$status_code = wp_remote_retrieve_response_code( $response );
				$body        = wp_remote_retrieve_body( $response );
				if ( 200 === $status_code && false !== strpos( $body, $canary_content ) ) {
					$exposed = true;
					$warning = __( 'Your web server serves files from the storage directory directly over HTTP. Apache .htaccess deny rules are ignored (common on Nginx, LiteSpeed or IIS). Please configure your server to deny public access to wp-content/migrate-lift-files/.', 'migrate-lift' );
				}
			}
		}

		wp_delete_file( $canary_file );

		$report = array(
			'exposed'     => $exposed,
			'status_code' => $status_code,
			'warning'     => $warning,
			'checked_at'  => time(),
			'test_url'    => $test_url,
		);

		set_transient( self::OPTION_WEB_EXPOSURE, $report, 12 * HOUR_IN_SECONDS );
		return $report;
	}

	/**
	 * Returns staging directory facts, limits, and guidance for SFTP / cPanel users.
	 *
	 * @return array
	 */
	public static function get_staging_info() {
		self::ensure_directories();
		$staging_dir = self::get_staging_dir();
		$free_space  = function_exists( 'disk_free_space' ) ? @disk_free_space( $staging_dir ) : null;
		$total_space = function_exists( 'disk_total_space' ) ? @disk_total_space( $staging_dir ) : null;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$is_writable = function_exists( 'wp_is_writable' ) ? wp_is_writable( $staging_dir ) : is_writable( $staging_dir );
		$exposure    = self::check_web_exposure( false );

		$rel_path = 'wp-content/' . MIGRATE_LIFT_STORE . '/' . self::STAGING_SUBDIR . '/';

		return array(
			'staging_dir'          => $staging_dir,
			'staging_dir_relative' => $rel_path,
			'is_writable'          => $is_writable,
			'web_exposed'          => ! empty( $exposure['exposed'] ),
			'web_warning'          => $exposure['warning'] ?? '',
			'disk_free_bytes'      => false !== $free_space ? (int) $free_space : null,
			'disk_total_bytes'     => false !== $total_space ? (int) $total_space : null,
			'server_limits'        => array(
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				'post_max_size'       => ini_get( 'post_max_size' ),
				'memory_limit'        => ini_get( 'memory_limit' ),
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'max_upload_size'     => wp_max_upload_size(),
			),
		);
	}

	/**
	 * Scans only the plugin-controlled staging directory for eligible archives.
	 * Strictly rejects directory traversal, symlinks outside, and foreign paths.
	 *
	 * @return array Discovered archive records.
	 */
	public static function scan_staged_archives() {
		self::ensure_directories();
		$staging_dir = self::get_staging_dir();
		$items       = array();

		if ( ! is_dir( $staging_dir ) ) {
			return $items;
		}

		$files = @scandir( $staging_dir );
		if ( false === $files ) {
			return $items;
		}

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file || '.htaccess' === $file || 'index.php' === $file ) {
				continue;
			}

			// Strictly reject files with traversal or non-matching extensions.
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(tgz|tar\.gz|zip)$/i', $file ) ) {
				continue;
			}

			$full_path = $staging_dir . '/' . $file;
			if ( ! is_file( $full_path ) || is_link( $full_path ) ) {
				continue;
			}

			$size  = (int) filesize( $full_path );
			$mtime = (int) filemtime( $full_path );

			$items[] = array(
				'filename'      => $file,
				'name'          => $file,
				'size'          => $size,
				'size_formatted'=> size_format( $size, 1 ),
				'mtime'         => $mtime,
				'date'          => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mtime ),
				'format'        => preg_match( '/\.zip$/i', $file ) ? 'zip' : 'tgz',
				'status'        => 'discovered',
				'staging_path'  => $file,
			);
		}

		usort(
			$items,
			function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);

		return $items;
	}

	/**
	 * Validates filename format strictly and prevents directory traversal or URL references.
	 *
	 * @param string $filename Candidate filename.
	 * @return string Sanitized basename.
	 * @throws Exception If filename is invalid or contains traversal.
	 */
	public static function sanitize_archive_filename( $filename ) {
		$raw = (string) $filename;

		// Strictly reject any traversal, null bytes, absolute paths, or protocol prefixes.
		if ( false !== strpos( $raw, '..' )
			|| false !== strpos( $raw, '/' )
			|| false !== strpos( $raw, '\\' )
			|| false !== strpos( $raw, "\0" )
			|| preg_match( '/^[a-zA-Z]:/', $raw )
			|| false !== strpos( $raw, '://' ) ) {
			throw new Exception( esc_html__( 'Path traversal or external reference detected.', 'migrate-lift' ) );
		}

		$name = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $raw ) : preg_replace( '/[^A-Za-z0-9._-]/', '', $raw );

		if ( empty( $name ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(tgz|tar\.gz|zip)$/i', $name ) ) {
			throw new Exception( esc_html__( 'Invalid archive filename. Choose a .tgz or .zip archive created by MigrateLift.', 'migrate-lift' ) );
		}

		return $name;
	}

	/**
	 * Inspects archive file magic bytes to verify genuine archive format.
	 *
	 * @param string $path Absolute file path.
	 * @return string 'tgz' or 'zip'
	 * @throws Exception On format mismatch or corrupt signature.
	 */
	public static function verify_magic_bytes( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new Exception( esc_html__( 'Cannot open archive to verify magic byte signature.', 'migrate-lift' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$bytes = fread( $handle, 4 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( strlen( $bytes ) < 2 ) {
			throw new Exception( esc_html__( 'Archive file is empty or truncated.', 'migrate-lift' ) );
		}

		// Gzip magic bytes: 0x1F, 0x8B
		if ( "\x1f\x8b" === substr( $bytes, 0, 2 ) ) {
			return 'tgz';
		}

		// ZIP magic bytes: 'PK\x03\x04', 'PK\x05\x06' (empty zip), 'PK\x07\x08' (spanned)
		if ( 'PK' === substr( $bytes, 0, 2 ) ) {
			return 'zip';
		}

		throw new Exception( esc_html__( 'File signature does not match gzip or zip format. Malformed or tampered file.', 'migrate-lift' ) );
	}

	/**
	 * Runs exhaustive security verification on a quarantined archive:
	 * - Magic bytes verification
	 * - Archive bomb / expansion ratio protection
	 * - Path traversal defense
	 * - Symlink policy enforcement
	 * - Nested archive defense
	 * - Manifest structure and checksum verification
	 * - WordPress & PHP compatibility checks
	 *
	 * @param string $path Archive absolute path.
	 * @param string $filename Archive filename.
	 * @return array Verification report.
	 */
	public static function verify_archive_security( $path, $filename = '' ) {
		clearstatcache( true, $path );
		$compressed_size = (int) filesize( $path );

		$report = array(
			'valid'            => false,
			'format'           => '',
			'compressed_size'  => $compressed_size,
			'uncompressed_size'=> 0,
			'expansion_ratio'  => 0.0,
			'file_count'       => 0,
			'manifest'         => null,
			'errors'           => array(),
			'warnings'         => array(),
			'has_database'     => false,
			'has_content'      => false,
			'verified_at'      => time(),
		);

		if ( $compressed_size < 100 ) {
			$report['errors'][] = __( 'Archive is smaller than 100 bytes and cannot contain a valid site.', 'migrate-lift' );
			return $report;
		}

		// 1. Magic byte format check.
		try {
			$format = self::verify_magic_bytes( $path );
			$report['format'] = $format;
		} catch ( Exception $e ) {
			$report['errors'][] = $e->getMessage();
			return $report;
		}

		// 2. Structural and manifest inspection.
		$total_uncompressed = 0;
		$file_count         = 0;
		$manifest           = null;
		$has_db             = false;
		$has_wp_content     = false;

		try {
			if ( 'zip' === $format ) {
				if ( ! class_exists( 'Migrate_Lift_Zip_Reader' ) ) {
					require_once dirname( __FILE__ ) . '/class-migrate-lift-zip.php';
				}
				$zip = new Migrate_Lift_Zip_Reader( $path );

				$manifest_raw = $zip->get_entry_contents( 'migratelift.json' );
				$has_db = $zip->has_entry( 'database.sql' );

				// Inspect entries.
				$entries = $zip->get_entries();
				foreach ( $entries as $name => $entry ) {
					$file_count++;
					$entry_size = (int) ( $entry['size'] ?? 0 );
					$total_uncompressed += $entry_size;

					// Path traversal check.
					self::assert_entry_safe_path( $name );

					if ( 0 === strpos( $name, 'wp-content/' ) ) {
						$has_wp_content = true;
					}

					// Nested archive check.
					if ( preg_match( '/\.(tar|tgz|tar\.gz|zip|gz|bz2|xz)$/i', $name ) && ! preg_match( '#wp-content/uploads/#i', $name ) ) {
						// Suspicious nested archive outside uploads.
						/* translators: %s: Entry name. */
						$report['warnings'][] = sprintf( __( 'Nested archive detected: %s', 'migrate-lift' ), $name );
					}
				}
				$zip->close();

				if ( $manifest_raw ) {
					$manifest = json_decode( (string) $manifest_raw, true );
				}
			} else {
				// TGZ / Tar.gz stream inspection.
				if ( ! class_exists( 'Migrate_Lift_Tar_Reader' ) ) {
					require_once dirname( __FILE__ ) . '/class-migrate-lift-tar.php';
				}
				$tar = new Migrate_Lift_Tar_Reader( $path );

				while ( ( $entry = $tar->next() ) !== null ) {
					$file_count++;
					$entry_size = (int) $entry['size'];
					$total_uncompressed += $entry_size;

					if ( $file_count > self::MAX_FILE_ENTRIES ) {
						/* translators: %d: Maximum allowed entries count. */
						throw new Exception( esc_html( sprintf( __( 'Archive exceeds maximum allowed entry count (%d). Potential archive bomb.', 'migrate-lift' ), self::MAX_FILE_ENTRIES ) ) );
					}

					// Path traversal check.
					self::assert_entry_safe_path( $entry['name'] );

					// Symlink rule: disallow symlinks (tar type '2') in backup archives to avoid symlink traversal.
					if ( '2' === $entry['type'] ) {
						/* translators: %s: Disallowed symlink entry name. */
						throw new Exception( esc_html( sprintf( __( 'Archive contains disallowed symlink entry (%s). Symlinks are rejected for security.', 'migrate-lift' ), $entry['name'] ) ) );
					}

					// Manifest check.
					if ( 'migratelift.json' === $entry['name'] && $entry_size <= 2097152 ) {
						$manifest_raw = $tar->read_exact( $entry_size );
						$manifest     = json_decode( $manifest_raw, true );
						$tar->skip_pad( $entry_size );
						continue;
					}

					if ( 'database.sql' === $entry['name'] ) {
						$has_db = true;
					}

					if ( 0 === strpos( $entry['name'], 'wp-content/' ) ) {
						$has_wp_content = true;
					}

					// Nested archive check.
					if ( preg_match( '/\.(tar|tgz|tar\.gz|zip|gz|bz2|xz)$/i', $entry['name'] ) && ! preg_match( '#wp-content/uploads/#i', $entry['name'] ) ) {
						/* translators: %s: Entry name. */
						$report['warnings'][] = sprintf( __( 'Nested archive detected: %s', 'migrate-lift' ), $entry['name'] );
					}

					$tar->skip( $entry_size );
					$tar->skip_pad( $entry_size );

					// Continuous expansion bomb defense.
					if ( $total_uncompressed > self::MAX_TOTAL_UNCOMPRESSED ) {
						/* translators: %s: Maximum total uncompressed size. */
						throw new Exception( esc_html( sprintf( __( 'Archive uncompressed size exceeds maximum ceiling (%s). Rejected.', 'migrate-lift' ), size_format( self::MAX_TOTAL_UNCOMPRESSED ) ) ) );
					}
				}
				$tar->close();
			}
		} catch ( Exception $e ) {
			$report['errors'][] = $e->getMessage();
			return $report;
		}

		$report['file_count']        = $file_count;
		$report['uncompressed_size'] = $total_uncompressed;
		$ratio                       = $compressed_size > 0 ? ( $total_uncompressed / $compressed_size ) : 0;
		$report['expansion_ratio']   = round( $ratio, 2 );
		$report['has_database']      = $has_db;
		$report['has_content']       = $has_wp_content;

		// 3. Archive bomb / expansion ratio threshold defense.
		if ( $ratio > self::MAX_EXPANSION_RATIO && $total_uncompressed > 52428800 ) {
			$report['errors'][] = sprintf(
				/* translators: 1: Expansion ratio, 2: Maximum allowed expansion ratio. */
				__( 'Archive expansion ratio (%1$.1f:1) exceeds security limit (%2$d:1). Archive rejected as potential decompression bomb.', 'migrate-lift' ),
				$ratio,
				self::MAX_EXPANSION_RATIO
			);
			return $report;
		}

		// 4. Manifest requirements.
		if ( ! is_array( $manifest ) || empty( $manifest['home'] ) || empty( $manifest['prefix'] ) ) {
			$report['errors'][] = __( 'Archive is missing a valid migratelift.json manifest or the manifest is corrupt.', 'migrate-lift' );
			return $report;
		}
		$report['manifest'] = $manifest;

		// 5. Critical component check.
		if ( ! $has_db ) {
			$report['errors'][] = __( 'Archive does not contain database.sql.', 'migrate-lift' );
		}
		if ( ! $has_wp_content ) {
			$report['warnings'][] = __( 'Archive does not contain a wp-content folder (database-only or partial backup).', 'migrate-lift' );
		}

		// 6. Environment compatibility warnings (informational for restore preflight).
		if ( ! empty( $manifest['php'] ) && version_compare( PHP_VERSION, preg_replace( '/^(\d+\.\d+).*/', '$1', $manifest['php'] ), '<' ) ) {
			$report['warnings'][] = sprintf(
				/* translators: 1: Archive PHP version, 2: Server PHP version. */
				__( 'Archive was generated on PHP %1$s; this server runs PHP %2$s.', 'migrate-lift' ),
				$manifest['php'],
				PHP_VERSION
			);
		}
		if ( ! empty( $manifest['wp'] ) && version_compare( get_bloginfo( 'version' ), $manifest['wp'], '<' ) ) {
			$report['warnings'][] = sprintf(
				/* translators: 1: Archive WordPress version, 2: Server WordPress version. */
				__( 'Archive was generated on WordPress %1$s; this server runs WordPress %2$s.', 'migrate-lift' ),
				$manifest['wp'],
				get_bloginfo( 'version' )
			);
		}

		$free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( migrate_lift_content_dir() ) : false;
		if ( $free_space && $total_uncompressed > 0 && $free_space < ( $total_uncompressed * 1.25 ) ) {
			$report['warnings'][] = sprintf(
				/* translators: 1: Available disk space, 2: Archive uncompressed size. */
				__( 'Available disk space (%1$s) may be tight for this %2$s archive.', 'migrate-lift' ),
				size_format( $free_space ),
				size_format( $total_uncompressed )
			);
		}

		if ( empty( $report['errors'] ) ) {
			$report['valid'] = true;
		}

		return $report;
	}

	/**
	 * Strictly validates an entry name for directory traversal, absolute paths, or escaping.
	 *
	 * @param string $entry_name Path inside archive.
	 * @throws Exception If traversal detected.
	 */
	public static function assert_entry_safe_path( $entry_name ) {
		$name = str_replace( '\\', '/', (string) $entry_name );

		if ( '' === trim( $name ) ) {
			return;
		}

		// Null bytes.
		if ( false !== strpos( $name, "\0" ) ) {
			throw new Exception( esc_html__( 'Archive entry contains illegal null byte.', 'migrate-lift' ) );
		}

		// Absolute path or drive prefix.
		if ( '/' === $name[0] || preg_match( '/^[a-zA-Z]:/', $name ) ) {
			/* translators: %s: Archive entry name. */
			throw new Exception( esc_html( sprintf( __( 'Path traversal attempt detected (absolute path): %s', 'migrate-lift' ), $name ) ) );
		}

		// Directory traversal segment: ../ or .. at start/end
		if ( preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
			/* translators: %s: Archive entry name. */
			throw new Exception( esc_html( sprintf( __( 'Path traversal attempt detected (parent directory traversal): %s', 'migrate-lift' ), $name ) ) );
		}

		// Whitelisted root targets for MigrateLift archives:
		// migratelift.json, database.sql, and files inside wp-content/
		$clean = rtrim( preg_replace( '#^\./#', '', $name ), '/' );
		$is_allowed = ( 'migratelift.json' === $clean
			|| 'database.sql' === $clean
			|| 0 === strpos( $clean, 'wp-content/' ) );

		if ( ! $is_allowed ) {
			/* translators: %s: Archive entry name. */
			throw new Exception( esc_html( sprintf( __( 'Archive contains unauthorized entry outside allowed boundaries: %s', 'migrate-lift' ), $clean ) ) );
		}
	}

	/**
	 * Imports an archive discovered in the dedicated staging directory into quarantine,
	 * verifies security, and moves to main backup library with "Ready for restore" status.
	 *
	 * @param string $filename File in staging folder.
	 * @return array Import result.
	 * @throws Exception On invalid path or verification failure.
	 */
	public static function import_staged_archive( $filename ) {
		self::ensure_directories();
		$clean_name = self::sanitize_archive_filename( $filename );

		$staging_file = self::get_staging_dir() . '/' . $clean_name;
		if ( ! is_file( $staging_file ) ) {
			/* translators: %s: Cleaned archive filename. */
			throw new Exception( esc_html( sprintf( __( 'File "%s" not found in staging folder.', 'migrate-lift' ), $clean_name ) ) );
		}

		// Move file to quarantine.
		$quarantine_dir  = self::get_quarantine_dir();
		$quarantine_file = $quarantine_dir . '/' . $clean_name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $staging_file, $quarantine_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $staging_file, $quarantine_file ) || ! wp_delete_file( $staging_file ) ) {
				throw new Exception( esc_html__( 'Failed moving archive into quarantine for inspection.', 'migrate-lift' ) );
			}
		}

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			Migrate_Lift_Activity_Logger::info(
				'archive_quarantined',
				/* translators: %s: Cleaned archive filename. */
				sprintf( __( 'Archive %s placed in quarantine for security verification.', 'migrate-lift' ), $clean_name ),
				array( 'filename' => $clean_name, 'source' => 'sftp_staging' )
			);
		}

		// Run security checks.
		$report = self::verify_archive_security( $quarantine_file, $clean_name );

		if ( ! $report['valid'] ) {
			$err_msg = ! empty( $report['errors'] ) ? implode( '; ', $report['errors'] ) : __( 'Archive failed integrity verification.', 'migrate-lift' );
			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::error(
					'import_verification_failed',
					/* translators: 1: Archive filename, 2: Error message. */
					sprintf( __( 'Staged archive %1$s rejected: %2$s', 'migrate-lift' ), $clean_name, $err_msg ),
					array( 'filename' => $clean_name, 'errors' => $report['errors'] )
				);
			}
			/* translators: %s: Error message. */
			throw new Exception( esc_html( sprintf( __( 'Archive rejected during quarantine inspection: %s', 'migrate-lift' ), $err_msg ) ) );
		}

		// Verification passed! Move to main storage library.
		$final_dest = wp_unique_filename( migrate_lift_dir(), $clean_name );
		$final_path = migrate_lift_dir( $final_dest );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $quarantine_file, $final_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $quarantine_file, $final_path ) || ! wp_delete_file( $quarantine_file ) ) {
				throw new Exception( esc_html__( 'Failed moving verified archive to library storage.', 'migrate-lift' ) );
			}
		}

		// Store metadata.
		$meta = get_option( 'migrate_lift_backup_metadata', array() );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$item_meta = array(
			'name'                 => $final_dest,
			'file'                 => $final_dest,
			'size'                 => (int) filesize( $final_path ),
			'raw_estimate'         => $report['uncompressed_size'],
			'reduction_percentage' => $report['expansion_ratio'] > 1 ? ( 1 - ( 1 / $report['expansion_ratio'] ) ) * 100 : 0.0,
			'time'                 => (int) filemtime( $final_path ),
			'verified'             => true,
			'verified_at'          => time(),
			'status'               => 'ready', // Ready for restore!
			'source'               => 'sftp_import',
			'manifest'             => $report['manifest'],
			'warnings'             => $report['warnings'],
			'format'               => $report['format'],
		);

		if ( ! empty( $report['manifest']['database_sections']['sql_sha256'] ) ) {
			$item_meta['checksum'] = $report['manifest']['database_sections']['sql_sha256'];
		}

		$meta[ $final_dest ] = $item_meta;
		update_option( 'migrate_lift_backup_metadata', $meta, false );

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			Migrate_Lift_Activity_Logger::info(
				'archive_imported',
				/* translators: %s: Final destination archive filename. */
				sprintf( __( 'Archive %s successfully verified and imported. Ready for restore.', 'migrate-lift' ), $final_dest ),
				array(
					'file'     => $final_dest,
					'size'     => $item_meta['size'],
					'warnings' => $report['warnings'],
				)
			);
		}

		return array(
			'success'  => true,
			'file'     => $final_dest,
			'archive'  => $item_meta,
			'report'   => $report,
			'warnings' => $report['warnings'],
			/* translators: %s: Final destination archive filename. */
			'message'  => sprintf( __( 'Archive "%s" successfully verified and imported. Status: Ready for restore.', 'migrate-lift' ), $final_dest ),
		);
	}

	/**
	 * Returns current received offset for an in-progress chunked upload.
	 *
	 * @param string $filename Archive filename.
	 * @return array
	 */
	public static function get_upload_status( $filename ) {
		self::ensure_directories();
		$clean_name = self::sanitize_archive_filename( $filename );
		$part_path  = self::get_quarantine_dir() . '/' . $clean_name . '.part';

		clearstatcache( true, $part_path );
		$size = file_exists( $part_path ) ? (int) filesize( $part_path ) : 0;

		return array(
			'filename' => $clean_name,
			'size'     => $size,
			'resumable'=> $size > 0,
		);
	}

	/**
	 * Handles one uploaded chunk for browser chunked uploads.
	 *
	 * @param string $filename Target filename.
	 * @param int    $offset Expected starting byte offset.
	 * @param int    $total Total expected file size.
	 * @param string $chunk_tmp Uploaded temp file path.
	 * @param int    $chunk_size Uploaded chunk byte length.
	 * @param string $client_md5 Optional client-provided MD5 hash of this chunk.
	 * @return array Receipt or completed archive record.
	 * @throws Exception On boundary mismatch, disk exhaustion, or verification failure.
	 */
	public static function handle_upload_chunk( $filename, $offset, $total, $chunk_tmp, $chunk_size, $client_md5 = '' ) {
		self::ensure_directories();
		$clean_name = self::sanitize_archive_filename( $filename );
		$part_path  = self::get_quarantine_dir() . '/' . $clean_name . '.part';

		clearstatcache( true, $part_path );
		$current_size = ( 0 === $offset ) ? 0 : ( file_exists( $part_path ) ? (int) filesize( $part_path ) : 0 );

		// Validate chunk MD5 if provided.
		if ( ! empty( $client_md5 ) ) {
			$actual_md5 = md5_file( $chunk_tmp );
			if ( $actual_md5 !== $client_md5 ) {
				throw new Exception( esc_html__( 'Chunk checksum mismatch. Transfer corrupted; retrying chunk.', 'migrate-lift' ) );
			}
		}

		// Check boundary.
		if ( $current_size === $offset ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			$chunk_data = file_get_contents( $chunk_tmp );
			if ( false === $chunk_data ) {
				throw new Exception( esc_html__( 'Could not read uploaded chunk.', 'migrate-lift' ) );
			}

			$flags = ( 0 === $offset ) ? 0 : FILE_APPEND;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $part_path, $chunk_data, $flags );

			if ( false === $written || $written !== $chunk_size ) {
				throw new Exception( esc_html__( 'Could not write chunk to disk. Storage full or permission denied.', 'migrate-lift' ) );
			}
		} elseif ( $current_size === ( $offset + $chunk_size ) ) {
			// Idempotent retry: chunk already saved.
		} else {
			/* translators: 1: Current received bytes, 2: Expected offset bytes. */
			throw new Exception( esc_html( sprintf( __( 'Upload out of sequence. Server has %1$d bytes, chunk expects %2$d bytes. Please resume.', 'migrate-lift' ), $current_size, $offset ) ) );
		}

		$new_size = $offset + $chunk_size;
		$receipt  = md5( $clean_name . ':' . $offset . ':' . $new_size );

		// If more chunks remain:
		if ( $new_size < $total ) {
			return array(
				'done'     => false,
				'received' => $new_size,
				'total'    => $total,
				'receipt'  => $receipt,
				'progress' => (int) round( ( $new_size / $total ) * 100 ),
			);
		}

		// Upload complete! Assemble and quarantine.
		$quarantine_dir  = self::get_quarantine_dir();
		$quarantine_file = $quarantine_dir . '/' . $clean_name;

		if ( file_exists( $quarantine_file ) ) {
			wp_delete_file( $quarantine_file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $part_path, $quarantine_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $part_path, $quarantine_file ) || ! wp_delete_file( $part_path ) ) {
				throw new Exception( esc_html__( 'Could not finalize uploaded archive in quarantine.', 'migrate-lift' ) );
			}
		}

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			Migrate_Lift_Activity_Logger::info(
				'browser_upload_completed',
				/* translators: 1: Cleaned archive filename, 2: Total file size. */
				sprintf( __( 'Browser upload of %1$s completed (%2$s). Beginning quarantine inspection.', 'migrate-lift' ), $clean_name, size_format( $total ) ),
				array( 'filename' => $clean_name, 'size' => $total )
			);
		}

		// Run quarantine security checks.
		$report = self::verify_archive_security( $quarantine_file, $clean_name );

		if ( ! $report['valid'] ) {
			$err_msg = ! empty( $report['errors'] ) ? implode( '; ', $report['errors'] ) : __( 'Archive failed integrity verification.', 'migrate-lift' );
			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::error(
					'import_verification_failed',
					/* translators: 1: Archive filename, 2: Error message. */
					sprintf( __( 'Uploaded archive %1$s rejected: %2$s', 'migrate-lift' ), $clean_name, $err_msg ),
					array( 'filename' => $clean_name, 'errors' => $report['errors'] )
				);
			}
			/* translators: %s: Error message. */
			throw new Exception( esc_html( sprintf( __( 'Archive rejected during quarantine inspection: %s', 'migrate-lift' ), $err_msg ) ) );
		}

		// Move verified archive from quarantine to main backup library.
		$final_dest = wp_unique_filename( migrate_lift_dir(), $clean_name );
		$final_path = migrate_lift_dir( $final_dest );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $quarantine_file, $final_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $quarantine_file, $final_path ) || ! wp_delete_file( $quarantine_file ) ) {
				throw new Exception( esc_html__( 'Failed moving verified archive to library storage.', 'migrate-lift' ) );
			}
		}

		// Register metadata.
		$meta = get_option( 'migrate_lift_backup_metadata', array() );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$item_meta = array(
			'name'                 => $final_dest,
			'file'                 => $final_dest,
			'size'                 => (int) filesize( $final_path ),
			'raw_estimate'         => $report['uncompressed_size'],
			'reduction_percentage' => $report['expansion_ratio'] > 1 ? ( 1 - ( 1 / $report['expansion_ratio'] ) ) * 100 : 0.0,
			'time'                 => (int) filemtime( $final_path ),
			'verified'             => true,
			'verified_at'          => time(),
			'status'               => 'ready', // Ready for restore
			'source'               => 'browser_upload',
			'manifest'             => $report['manifest'],
			'warnings'             => $report['warnings'],
			'format'               => $report['format'],
		);

		if ( ! empty( $report['manifest']['database_sections']['sql_sha256'] ) ) {
			$item_meta['checksum'] = $report['manifest']['database_sections']['sql_sha256'];
		}

		$meta[ $final_dest ] = $item_meta;
		update_option( 'migrate_lift_backup_metadata', $meta, false );

		if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
			Migrate_Lift_Activity_Logger::info(
				'archive_imported',
				/* translators: %s: Cleaned archive filename. */
				sprintf( __( 'Uploaded archive %s verified and imported. Status: Ready for restore.', 'migrate-lift' ), $final_dest ),
				array( 'file' => $final_dest, 'size' => $item_meta['size'] )
			);
		}

		return array(
			'done'     => true,
			'file'     => $final_dest,
			'archive'  => $item_meta,
			'report'   => $report,
			'warnings' => $report['warnings'],
			'progress' => 100,
			/* translators: %s: Cleaned archive filename. */
			'message'  => sprintf( __( 'Archive "%s" uploaded and verified. Ready for restore.', 'migrate-lift' ), $final_dest ),
		);
	}

	/**
	 * Cancels an in-progress chunked upload and cleans up partial files.
	 *
	 * @param string $filename Archive filename.
	 * @return bool
	 */
	public static function cancel_upload( $filename ) {
		try {
			$clean_name = self::sanitize_archive_filename( $filename );
			$part_path  = self::get_quarantine_dir() . '/' . $clean_name . '.part';
			$quarantine = self::get_quarantine_dir() . '/' . $clean_name;

			if ( file_exists( $part_path ) ) {
				wp_delete_file( $part_path );
			}
			if ( file_exists( $quarantine ) ) {
				wp_delete_file( $quarantine );
			}

			if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
				Migrate_Lift_Activity_Logger::info(
					'upload_cancelled',
					/* translators: %s: Cleaned archive filename. */
					sprintf( __( 'Upload of %s was cancelled by user; staging files cleaned up.', 'migrate-lift' ), $clean_name ),
					array( 'filename' => $clean_name )
				);
			}

			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}
}
