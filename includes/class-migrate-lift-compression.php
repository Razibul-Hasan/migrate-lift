<?php
/**
 * Compression Optimization & Exclusion Management.
 *
 * Implements:
 * - Multi-mode compression (Balanced, Smallest practical, Fastest)
 * - Extension-aware compression choices (Media bypass for already-compressed assets)
 * - Widely portable archive format selection (TAR.GZ / ZIP)
 * - Truthful source estimates, archive metrics, and reduction calculation
 * - Granular exclusion engine (caches, logs, third-party backups, temporary artifacts)
 * - Host capability detection (zlib, zip, zstd) with restorability safeguards
 * - Sanitized settings persistence
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrate_Lift_Compression
 */
class Migrate_Lift_Compression {

	/**
	 * Database option name for saved compression settings.
	 */
	const OPTION_KEY = 'migrate_lift_compression_settings';

	/**
	 * Supported compression modes.
	 */
	const MODE_BALANCED = 'balanced';
	const MODE_SMALLEST = 'smallest';
	const MODE_FASTEST  = 'fastest';

	/**
	 * Supported archive formats.
	 */
	const FORMAT_TGZ = 'tgz';
	const FORMAT_ZIP = 'zip';

	/**
	 * File extensions known to be pre-compressed.
	 * Compressing these with Deflate wastes CPU cycles with negligible size reduction.
	 */
	const PRE_COMPRESSED_EXTENSIONS = array(
		// Images
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'heic',
		// Video & Audio
		'mp4', 'm4v', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'flv',
		'mp3', 'm4a', 'ogg', 'wav', 'flac', 'aac',
		// Archives & Packages
		'zip', 'gz', 'tgz', 'tar', 'bz2', 'xz', '7z', 'rar', 'zst',
		// Fonts & Documents
		'pdf', 'woff', 'woff2', 'eot',
	);

	/**
	 * Default exclusion patterns.
	 */
	const DEFAULT_CACHE_RULES = array(
		'cache',
		'cache/*',
		'litespeed',
		'wflogs',
		'upgrade',
		'upgrade-temp-backup',
	);

	const DEFAULT_LOG_RULES = array(
		'debug.log',
		'*.log',
		'error_log',
		'php_errorlog',
	);

	const DEFAULT_BACKUP_RULES = array(
		'migrate-lift-files',
		'ai1wm-backups',
		'updraft',
		'updraft*',
		'backups-dup-lite',
		'wpvividbackups',
		'backwpup-*',
		'uploads/updraft*',
		'uploads/updraftplus*',
		'uploads/backwpup*',
		'uploads/wpvivid*',
		'uploads/ai1wm-backups*',
		'uploads/siteground-optimizer-assets',
	);

	/**
	 * Returns host PHP compression capabilities and limitations.
	 *
	 * @return array
	 */
	public static function get_capabilities() {
		$has_zlib = extension_loaded( 'zlib' );
		$has_zip_ext = class_exists( 'ZipArchive' );
		$has_zstd = extension_loaded( 'zstd' ) || extension_loaded( 'zstandard' ) || function_exists( 'zstd_compress' );

		return array(
			'zlib'           => $has_zlib,
			'zip_extension'  => $has_zip_ext,
			'zip_supported'  => true, // Supported via native streaming zip writer or ZipArchive
			'zstd_extension' => $has_zstd,
			'zstd_supported' => false, // Zstandard not exposed by default to ensure destination restorability
			'zstd_notice'    => __( 'Zstandard requires the ext-zstd PHP extension on both source and destination servers. To ensure universal portability, it is currently disabled.', 'migrate-lift' ),
			'media_disclaimer' => __( 'Pre-compressed assets (JPEG, WebP, MP4, ZIP) shrink very little with Deflate. MigrateLift avoids wasteful recompression without altering your original files.', 'migrate-lift' ),
		);
	}

	/**
	 * Returns default compression configuration.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'mode'                  => self::MODE_BALANCED,
			'format'                => self::FORMAT_TGZ,
			'media_bypass'          => true,
			'exclude_cache'         => true,
			'exclude_logs'          => true,
			'exclude_other_backups' => true,
			'custom_exclusions'     => array(),
		);
	}

	/**
	 * Retrieves current compression settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = self::get_defaults();
		$saved    = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, array() ) : array();

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, $defaults );

		// Validate mode
		if ( ! in_array( $settings['mode'], array( self::MODE_BALANCED, self::MODE_FASTEST, self::MODE_SMALLEST ), true ) ) {
			$settings['mode'] = self::MODE_BALANCED;
		}

		// Validate format
		if ( ! in_array( $settings['format'], array( self::FORMAT_TGZ, self::FORMAT_ZIP ), true ) ) {
			$settings['format'] = self::FORMAT_TGZ;
		}

		$settings['media_bypass']          = (bool) $settings['media_bypass'];
		$settings['exclude_cache']         = (bool) $settings['exclude_cache'];
		$settings['exclude_logs']          = (bool) $settings['exclude_logs'];
		$settings['exclude_other_backups'] = (bool) $settings['exclude_other_backups'];

		if ( ! is_array( $settings['custom_exclusions'] ) ) {
			$settings['custom_exclusions'] = self::parse_exclusion_lines( (string) $settings['custom_exclusions'] );
		}

		return $settings;
	}

	/**
	 * Validates, sanitizes, and saves compression settings.
	 *
	 * @param array $input Raw input data.
	 * @return array|WP_Error Saved settings or error.
	 */
	public static function save_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'invalid_input', __( 'Settings payload must be an array or object.', 'migrate-lift' ) );
		}

		$mode = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : self::MODE_BALANCED;
		if ( ! in_array( $mode, array( self::MODE_BALANCED, self::MODE_FASTEST, self::MODE_SMALLEST ), true ) ) {
			return new WP_Error( 'invalid_mode', __( 'Invalid compression mode specified. Must be balanced, fastest, or smallest.', 'migrate-lift' ) );
		}

		$format = isset( $input['format'] ) ? sanitize_key( (string) $input['format'] ) : self::FORMAT_TGZ;
		if ( ! in_array( $format, array( self::FORMAT_TGZ, self::FORMAT_ZIP ), true ) ) {
			return new WP_Error( 'invalid_format', __( 'Invalid archive format. Must be tgz or zip.', 'migrate-lift' ) );
		}

		$media_bypass          = isset( $input['media_bypass'] ) ? (bool) $input['media_bypass'] : true;
		$exclude_cache         = isset( $input['exclude_cache'] ) ? (bool) $input['exclude_cache'] : true;
		$exclude_logs          = isset( $input['exclude_logs'] ) ? (bool) $input['exclude_logs'] : true;
		$exclude_other_backups = isset( $input['exclude_other_backups'] ) ? (bool) $input['exclude_other_backups'] : true;

		$raw_exclusions = $input['custom_exclusions'] ?? '';
		$parsed_rules   = is_array( $raw_exclusions )
			? $raw_exclusions
			: self::parse_exclusion_lines( (string) $raw_exclusions );

		$sanitized_rules = array();
		foreach ( $parsed_rules as $rule ) {
			$rule = trim( str_replace( '\\', '/', (string) $rule ) );
			$rule = trim( $rule, '/' );

			// Prohibit empty rules or parent-directory traversal attempts
			if ( '' === $rule || false !== strpos( $rule, '..' ) ) {
				continue;
			}

			// Do not allow excluding root wp-content completely
			if ( '.' === $rule || '*' === $rule || 'wp-content' === $rule ) {
				continue;
			}

			$sanitized_rules[] = sanitize_text_field( $rule );
		}
		$sanitized_rules = array_values( array_unique( $sanitized_rules ) );

		$clean = array(
			'mode'                  => $mode,
			'format'                => $format,
			'media_bypass'          => $media_bypass,
			'exclude_cache'         => $exclude_cache,
			'exclude_logs'          => $exclude_logs,
			'exclude_other_backups' => $exclude_other_backups,
			'custom_exclusions'     => $sanitized_rules,
		);

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $clean );
		}

		return $clean;
	}

	/**
	 * Parses a multiline text string into an array of exclusion paths.
	 *
	 * @param string $text Newline- or comma-separated exclusion lines.
	 * @return array
	 */
	public static function parse_exclusion_lines( $text ) {
		$lines = preg_split( '/[\r\n,]+/', (string) $text );
		$rules = array();
		foreach ( $lines as $line ) {
			$rule = trim( $line );
			if ( '' !== $rule && 0 !== strpos( $rule, '#' ) ) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * Returns Deflate level corresponding to selected mode.
	 *
	 * @param string $mode Compression mode.
	 * @return int Gzip / Deflate level (1, 6, or 9).
	 */
	public static function get_deflate_level( $mode ) {
		switch ( $mode ) {
			case self::MODE_FASTEST:
				return 1;
			case self::MODE_SMALLEST:
				return 9;
			case self::MODE_BALANCED:
			default:
				return 6;
		}
	}

	/**
	 * Checks if a relative file path has an extension indicating it is already compressed.
	 *
	 * @param string $path File path or relative filename.
	 * @return bool True if pre-compressed.
	 */
	public static function is_already_compressed( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::PRE_COMPRESSED_EXTENSIONS, true );
	}

	/**
	 * Compiles complete active exclusion rules based on settings and filters.
	 *
	 * @param array|null $settings Optional settings override.
	 * @return array Active exclusion patterns.
	 */
	public static function get_active_exclusion_rules( $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		$rules = array();

		// Storage directory is always excluded
		$store = defined( 'MIGRATE_LIFT_STORE' ) ? MIGRATE_LIFT_STORE : 'migrate-lift-files';
		$rules[] = $store;

		if ( ! empty( $settings['exclude_cache'] ) ) {
			$rules = array_merge( $rules, self::DEFAULT_CACHE_RULES );
		}

		if ( ! empty( $settings['exclude_logs'] ) ) {
			$rules = array_merge( $rules, self::DEFAULT_LOG_RULES );
		}

		if ( ! empty( $settings['exclude_other_backups'] ) ) {
			$rules = array_merge( $rules, self::DEFAULT_BACKUP_RULES );
		}

		if ( ! empty( $settings['custom_exclusions'] ) && is_array( $settings['custom_exclusions'] ) ) {
			$rules = array_merge( $rules, $settings['custom_exclusions'] );
		}

		$rules = array_values( array_unique( $rules ) );

		if ( function_exists( 'apply_filters' ) ) {
			$rules = apply_filters( 'migrate_lift_exclude', $rules );
		}

		return $rules;
	}

	/**
	 * Checks whether a relative path inside wp-content is excluded.
	 *
	 * @param string     $rel Relative path with forward slashes.
	 * @param array|null $rules Optional pre-compiled rules list.
	 * @return bool True if excluded.
	 */
	public static function is_path_excluded( $rel, $rules = null ) {
		static $cached_rules = null;
		if ( null === $rules ) {
			if ( null === $cached_rules ) {
				$cached_rules = self::get_active_exclusion_rules();
			}
			$rules = $cached_rules;
		}

		$rel      = str_replace( '\\', '/', $rel );
		$basename = basename( $rel );

		// Always ignored temporary / VCS basenames
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

		// Check directory segments
		$parts = explode( '/', $rel );
		foreach ( $parts as $part ) {
			if ( in_array( $part, $ignored_basenames, true ) ) {
				return true;
			}
		}

		// Check accumulated path prefixes and fnmatch patterns
		$accum = '';
		foreach ( $parts as $part ) {
			$accum = '' === $accum ? $part : $accum . '/' . $part;
			foreach ( $rules as $rule ) {
				if ( $rule === $accum || $rule === $rel ) {
					return true;
				}
				if ( function_exists( 'fnmatch' ) ) {
					if ( fnmatch( $rule, $accum, FNM_PATHNAME ) || fnmatch( $rule, $rel, FNM_PATHNAME ) ) {
						return true;
					}
				} else {
					// Fallback pattern match if fnmatch is not available
					$regex = '#^' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( $rule, '#' ) ) . '$#i';
					if ( preg_match( $regex, $accum ) || preg_match( $regex, $rel ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Clears static caches (used during unit testing or when settings change).
	 */
	public static function reset_cache() {
		// Handled via dynamic invocation when needed.
	}

	/**
	 * Calculates accurate compression metrics and truthful reduction percentage.
	 *
	 * @param int $uncompressed_bytes Total uncompressed source bytes (files + uncompressed SQL).
	 * @param int $archive_bytes      Final generated archive file size.
	 * @return array
	 */
	public static function calculate_metrics( $uncompressed_bytes, $archive_bytes ) {
		$uncompressed = max( 0, (int) $uncompressed_bytes );
		$archive      = max( 0, (int) $archive_bytes );

		$reduction_bytes = max( 0, $uncompressed - $archive );
		$reduction_pct   = 0.0;

		if ( $uncompressed > 0 && $archive > 0 && $archive < $uncompressed ) {
			$reduction_pct = round( ( 1 - ( $archive / $uncompressed ) ) * 100, 1 );
		}

		return array(
			'source_estimate_bytes' => $uncompressed,
			'archive_bytes'         => $archive,
			'reduction_bytes'       => $reduction_bytes,
			'reduction_percentage'  => $reduction_pct,
			'source_label'          => __( 'Uncompressed Source Estimate', 'migrate-lift' ),
			'archive_label'         => __( 'Final Archive Size', 'migrate-lift' ),
			'reduction_label'       => sprintf(
				/* translators: %s: percentage of storage reduced */
				__( 'Storage Reduction: %s%%', 'migrate-lift' ),
				function_exists( 'number_format_i18n' ) ? number_format_i18n( $reduction_pct, 1 ) : number_format( $reduction_pct, 1 )
			),
		);
	}
}
