<?php
/**
 * Tools → MigrateLift page, AJAX endpoints and the archive download.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers MigrateLift admin menu under Tools.
 */
function migrate_lift_admin_menu() {
	add_submenu_page(
		'tools.php',
		__( 'MigrateLift', 'migrate-lift' ),
		__( 'MigrateLift', 'migrate-lift' ),
		'manage_options',
		'migrate-lift',
		'migrate_lift_render_page'
	);
}
add_action( 'admin_menu', 'migrate_lift_admin_menu' );

/**
 * Adds a "Migrate" link on the Plugins screen.
 *
 * @param array $links Links.
 * @return array
 */
function migrate_lift_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=migrate-lift' ) ) . '">' . esc_html__( 'Migrate', 'migrate-lift' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( MIGRATE_LIFT_FILE ), 'migrate_lift_action_links' );

/**
 * Redirects to the MigrateLift dashboard on single plugin activation.
 */
function migrate_lift_activation_redirect() {
	if ( ! get_transient( 'migrate_lift_activation_redirect' ) ) {
		return;
	}

	delete_transient( 'migrate_lift_activation_redirect' );

	// Do not redirect on bulk plugin activation.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['activate-multi'] ) ) {
		return;
	}

	// Do not redirect during AJAX, cron, REST API requests, or WP-CLI.
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	// Ensure the user has permission to manage options.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_safe_redirect( admin_url( 'tools.php?page=migrate-lift' ) );
	exit;
}
add_action( 'admin_init', 'migrate_lift_activation_redirect' );

/**
 * Loads the page assets.
 *
 * @param string $hook Screen hook.
 */
function migrate_lift_admin_assets( $hook ) {
	if ( 'tools_page_migrate-lift' !== $hook ) {
		return;
	}
	$url        = plugin_dir_url( MIGRATE_LIFT_FILE );
	$dir        = plugin_dir_path( MIGRATE_LIFT_FILE );
	$asset_file = file_exists( $dir . 'build/index.asset.php' )
		? include $dir . 'build/index.asset.php'
		: array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
			'version'      => MIGRATE_LIFT_VERSION,
		);

	wp_enqueue_style( 'wp-components' );
	if ( file_exists( $dir . 'build/index.css' ) ) {
		$css_file = is_rtl() && file_exists( $dir . 'build/index-rtl.css' ) ? 'build/index-rtl.css' : 'build/index.css';
		wp_enqueue_style(
			'migrate-lift-admin',
			$url . $css_file,
			array( 'wp-components' ),
			$asset_file['version']
		);
	}
	wp_enqueue_script(
		'migrate-lift-admin',
		$url . 'build/index.js',
		$asset_file['dependencies'],
		$asset_file['version'],
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'migrate-lift-admin', 'migrate-lift', $dir . 'languages' );
	}

	// Upload in chunks a bit under the server's upload limit, so big archives get through.
	// Small chunks keep each request short on slow connections and lose little on a retry.
	$chunk = (int) min( 4 * MB_IN_BYTES, max( 256 * KB_IN_BYTES, wp_max_upload_size() * 0.8 ) );

	$data = array(
		'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
		'restUrl'       => esc_url_raw( rest_url( 'migrate-lift/v1/' ) ),
		'restNonce'     => wp_create_nonce( 'wp_rest' ),
		'nonce'         => wp_create_nonce( 'migrate_lift_nonce' ),
		'chunk'         => $chunk,
		'home'          => untrailingslashit( home_url() ),
		'loginUrl'      => wp_login_url(),
		'archives'      => migrate_lift_archives(),
		'isMultisite'   => is_multisite(),
		'pluginVersion' => MIGRATE_LIFT_VERSION,
		'logoUrl'       => esc_url_raw( $url . 'assets/images/logo.png' ),
	);

	wp_localize_script( 'migrate-lift-admin', 'migrateLift', $data );
}
add_action( 'admin_enqueue_scripts', 'migrate_lift_admin_assets' );

/**
 * Renders the React root container.
 */
function migrate_lift_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'migrate-lift' ) );
	}
	echo '<div id="migrate-lift-admin-root" class="migrate-lift-wrap"></div>';
}

/**
 * Wraps an AJAX callback with the nonce, capability check and JSON error handling.
 *
 * @param callable $callback Returns the response data.
 * @param bool     $public   Skip the login check (the callback checks a token itself).
 */
function migrate_lift_ajax( $callback, $public = false ) {
	ob_start(); // Stray notices would break the JSON.
	try {
		if ( ! $public ) {
			$valid_nonce = check_ajax_referer( 'migrate_lift_nonce', '_ajax_nonce', false );

			if ( ! $valid_nonce || ! current_user_can( 'manage_options' ) || is_multisite() ) {
				throw new Exception( 'Your session expired or you are not allowed to do this. Reload the page.' );
			}
		}
		$data = call_user_func( $callback );
		ob_end_clean();
		wp_send_json_success( $data );
	} catch ( Throwable $e ) {
		ob_end_clean();
		wp_send_json_error( array( 'message' => $e->getMessage() ) );
	}
}

/**
 * Reads a POST field.
 *
 * @param string $key Key.
 * @return string
 */
function migrate_lift_post( $key ) {
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

// Export AJAX
add_action(
	'wp_ajax_migrate_lift_export',
	function () {
		migrate_lift_ajax(
			function () {
				return migrate_lift_export_step( migrate_lift_post( 'step' ) );
			}
		);
	}
);

// Import Info AJAX
add_action(
	'wp_ajax_migrate_lift_import_info',
	function () {
		migrate_lift_ajax(
			function () {
				return migrate_lift_import_info( migrate_lift_post( 'file' ) );
			}
		);
	}
);

// Import AJAX
add_action(
	'wp_ajax_migrate_lift_import',
	function () {
		migrate_lift_ajax(
			function () {
				return migrate_lift_import_step( migrate_lift_post( 'step' ), migrate_lift_post( 'file' ), '1' === migrate_lift_post( 'delete_after' ) );
			}
		);
	}
);

// Cleanup AJAX (After the swap the old login is gone, so cleanup is open to anyone holding the import token).
function migrate_lift_ajax_cleanup() {
	migrate_lift_ajax(
		function () {
			return migrate_lift_cleanup_step( migrate_lift_post( 'token' ) );
		},
		true
	);
}
add_action( 'wp_ajax_migrate_lift_cleanup', 'migrate_lift_ajax_cleanup' );
add_action( 'wp_ajax_nopriv_migrate_lift_cleanup', 'migrate_lift_ajax_cleanup' );

// Clean temporary AJAX
add_action(
	'wp_ajax_migrate_lift_clean',
	function () {
		migrate_lift_ajax( 'migrate_lift_clean_step' );
	}
);

// List archives AJAX
add_action(
	'wp_ajax_migrate_lift_list',
	function () {
		migrate_lift_ajax( 'migrate_lift_archives' );
	}
);

// Delete archive AJAX
add_action(
	'wp_ajax_migrate_lift_delete',
	function () {
		migrate_lift_ajax(
			function () {
				$file = sanitize_file_name( migrate_lift_post( 'file' ) );
				if ( function_exists( 'migrate_lift_archive_is_protected' ) && migrate_lift_archive_is_protected( $file ) ) {
					throw new Exception( esc_html__( 'Cannot delete this archive because it is designated as a protected recovery point.', 'migrate-lift' ) );
				}
				$path = migrate_lift_archive_path( $file );
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
				return migrate_lift_archives();
			}
		);
	}
);

// Upload chunk AJAX
add_action(
	'wp_ajax_migrate_lift_upload',
	function () {
		migrate_lift_ajax( 'migrate_lift_upload_chunk' );
	}
);

// Upload status AJAX
add_action(
	'wp_ajax_migrate_lift_upload_status',
	function () {
		migrate_lift_ajax(
			function () {
				$part = migrate_lift_dir( migrate_lift_upload_name() . '.part' );
				clearstatcache();
				return array( 'size' => file_exists( $part ) ? filesize( $part ) : 0 );
			}
		);
	}
);

// Upload cancel AJAX
add_action(
	'wp_ajax_migrate_lift_upload_cancel',
	function () {
		migrate_lift_ajax(
			function () {
				$name = migrate_lift_upload_name();
				$part = migrate_lift_dir( $name . '.part' );
				if ( file_exists( $part ) ) {
					wp_delete_file( $part );
				}
				if ( class_exists( 'Migrate_Lift_Import_Engine' ) ) {
					Migrate_Lift_Import_Engine::cancel_upload( $name );
				}
				return array( 'cancelled' => true );
			}
		);
	}
);

/**
 * The uploaded archive's file name, validated.
 *
 * @return string
 */
function migrate_lift_upload_name() {
	$name = sanitize_file_name( migrate_lift_post( 'name' ) );
	if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(tgz|tar\.gz|zip)$/i', $name ) ) {
		throw new Exception( 'Choose a .tgz or .zip archive made by Migrate Lift.' );
	}
	return $name;
}

/**
 * Appends one uploaded chunk. Returns the final file name with the last chunk.
 *
 * @return array
 */
function migrate_lift_upload_chunk() {
	$name = migrate_lift_upload_name();
	// The nonce is checked in migrate_lift_ajax(). The temporary path comes from PHP and must stay byte-exact for is_uploaded_file().
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$chunk_tmp   = isset( $_FILES['chunk']['tmp_name'] ) ? (string) $_FILES['chunk']['tmp_name'] : '';
	$chunk_error = isset( $_FILES['chunk']['error'] ) ? absint( $_FILES['chunk']['error'] ) : UPLOAD_ERR_NO_FILE; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$chunk_size  = isset( $_FILES['chunk']['size'] ) ? absint( $_FILES['chunk']['size'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( UPLOAD_ERR_OK !== $chunk_error || ! is_uploaded_file( $chunk_tmp ) ) {
		throw new Exception( 'The upload failed (PHP upload error ' . (int) $chunk_error . ').' );
	}

	$part   = migrate_lift_dir( $name . '.part' );
	$offset = (int) migrate_lift_post( 'offset' );
	$total  = (int) migrate_lift_post( 'total' );
	$length = $chunk_size;
	clearstatcache();
	$have = 0 === $offset ? 0 : ( file_exists( $part ) ? filesize( $part ) : 0 );

	if ( $have === $offset ) {
		$data = file_get_contents( $chunk_tmp );
		if ( false === file_put_contents( $part, $data, 0 === $offset ? 0 : FILE_APPEND ) ) {
			throw new Exception( 'Could not save the upload. Is the disk full?' );
		}
	} elseif ( $have !== $offset + $length ) { // Equal means a retried chunk that was already saved.
		throw new Exception( 'The upload got out of step. Start it again.' );
	}

	if ( $offset + $length < $total ) {
		return array( 'received' => $offset + $length );
	}

	$final = wp_unique_filename( migrate_lift_dir(), $name );
	$final_path = migrate_lift_dir( $final );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	rename( $part, $final_path );

	// Quarantine and security verification
	if ( class_exists( 'Migrate_Lift_Import_Engine' ) ) {
		$report = Migrate_Lift_Import_Engine::verify_archive_security( $final_path, $final );
		if ( ! $report['valid'] ) {
			wp_delete_file( $final_path );
			$err_msg = ! empty( $report['errors'] ) ? implode( '; ', $report['errors'] ) : 'Archive failed integrity verification.';
			throw new Exception( esc_html( 'Archive verification failed: ' . $err_msg ) );
		}

		$meta = get_option( 'migrate_lift_backup_metadata', array() );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$meta[ $final ] = array(
			'name'        => $final,
			'file'        => $final,
			'size'        => (int) filesize( $final_path ),
			'time'        => (int) filemtime( $final_path ),
			'verified'    => true,
			'verified_at' => time(),
			'status'      => 'ready',
			'source'      => 'browser_upload',
			'manifest'    => $report['manifest'],
			'warnings'    => $report['warnings'],
			'format'      => $report['format'],
		);
		update_option( 'migrate_lift_backup_metadata', $meta, false );
	}

	return array( 'file' => $final );
}

/**
 * Streams an archive to the browser.
 */
function migrate_lift_download() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	$valid_nonce = check_admin_referer( 'migrate_lift_download' );
	if ( ! $valid_nonce ) {
		wp_die( 'Security check failed.' );
	}

	try {
		$path = migrate_lift_archive_path( isset( $_GET['file'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['file'] ) ) ) : '' );
	} catch ( Exception $e ) {
		wp_die( esc_html( $e->getMessage() ) );
	}

	while ( ob_get_level() ) {
		ob_end_clean();
	}
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
	nocache_headers();

	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	$content_type = 'zip' === $ext ? 'application/zip' : 'application/gzip';

	header( 'Content-Type: ' . $content_type );
	header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file stream for chunked download.
	$in = fopen( $path, 'rb' );
	if ( $in ) {
		while ( ! feof( $in ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file stream.
			echo fread( $in, 1048576 );
			flush();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in );
	}
	exit;
}
add_action( 'admin_post_migrate_lift_download', 'migrate_lift_download' );

