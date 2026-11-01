<?php
/**
 * Direct Transfer Engine (Part 14).
 *
 * Implements fault-tolerant, resumable server-to-server archive transfer
 * over authenticated HTTPS using bounded chunk streaming, per-part SHA-256 checksums,
 * exponential backoff retry, and idempotent state persistence.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrate_Lift_Direct_Transfer_Engine
 */
class Migrate_Lift_Direct_Transfer_Engine {

	/**
	 * Default transfer chunk size in bytes (2 MiB).
	 */
	const DEFAULT_CHUNK_SIZE = 2097152;

	/**
	 * Maximum retry attempts per chunk before pausing/failing.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Subdirectory inside storage folder for incoming direct transfers.
	 */
	const INCOMING_DIR = 'incoming';

	/**
	 * Initializes the transfer engine and registers job handler.
	 */
	public static function init() {
		if ( class_exists( 'Migrate_Lift_Job_Engine' ) ) {
			Migrate_Lift_Job_Engine::register_handler( 'direct_transfer', array( __CLASS__, 'handle_transfer_step' ) );
		}
	}

	/**
	 * Returns directory for incoming transfer temporary chunks.
	 *
	 * @param string $transfer_id Optional transfer identifier.
	 * @return string Path to directory.
	 */
	public static function get_incoming_dir( $transfer_id = '' ) {
		$base = migrate_lift_dir( self::INCOMING_DIR );
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $base . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		if ( ! empty( $transfer_id ) ) {
			$clean = sanitize_file_name( $transfer_id );
			$path  = $base . '/' . $clean;
			if ( ! is_dir( $path ) ) {
				wp_mkdir_p( $path );
			}
			return $path;
		}

		return $base;
	}

	/**
	 * Initiates a new direct transfer background job on the source site.
	 *
	 * @param string $pair_id  Paired destination identifier.
	 * @param string $archive  Archive filename inside migrate_lift_dir().
	 * @param array  $options  Transfer options.
	 * @return array|WP_Error Job record or error.
	 */
	public static function start_transfer_job( $pair_id, $archive, $options = array() ) {
		if ( ! class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
			return new WP_Error( 'pairing_missing', __( 'Site pairing module is not available.', 'migrate-lift' ) );
		}

		$pair = Migrate_Lift_Site_Pairing::get_paired_site( $pair_id );
		if ( ! $pair || 'connected' !== ( $pair['status'] ?? '' ) ) {
			return new WP_Error( 'unpaired_destination', __( 'The selected destination site is not currently connected.', 'migrate-lift' ) );
		}

		$archive = sanitize_file_name( $archive );
		$filepath = migrate_lift_dir( $archive );
		if ( ! file_exists( $filepath ) ) {
			/* translators: %s: archive name */
			return new WP_Error( 'archive_not_found', sprintf( __( 'Archive %s does not exist.', 'migrate-lift' ), $archive ) );
		}

		$file_size = (float) filesize( $filepath );
		if ( $file_size <= 0 ) {
			return new WP_Error( 'empty_archive', __( 'Archive file is empty.', 'migrate-lift' ) );
		}

		// Calculate archive checksum if not provided.
		$sha256 = ! empty( $options['sha256'] ) ? sanitize_text_field( $options['sha256'] ) : hash_file( 'sha256', $filepath );
		$chunk_size = ! empty( $options['chunk_size'] ) ? (int) $options['chunk_size'] : self::DEFAULT_CHUNK_SIZE;
		$chunk_size = max( 262144, min( 8388608, $chunk_size ) ); // 256KB to 8MB bounds
		$total_chunks = (int) ceil( $file_size / $chunk_size );

		$transfer_id = 'xfer_' . bin2hex( random_bytes( 8 ) );

		$params = array(
			'transfer_id'  => $transfer_id,
			'pair_id'      => $pair_id,
			'archive'      => $archive,
			'filepath'     => $filepath,
			'total_bytes'  => $file_size,
			'sha256'       => $sha256,
			'chunk_size'   => $chunk_size,
			'total_chunks' => $total_chunks,
			'remote_url'   => $pair['remote_url'],
			'remote_name'  => $pair['remote_name'],
		);

		// Initial checkpoint state
		$initial_checkpoint = array(
			'stage'             => 'init',
			'chunks_completed'  => 0,
			'bytes_transferred' => 0,
			'last_chunk_index'  => -1,
			'retries_current'   => 0,
			'speed_bps'         => 0,
			'started_at'        => time(),
		);

		return Migrate_Lift_Job_Engine::create_job(
			'direct_transfer',
			$params,
			'transfer_' . md5( $pair_id . '_' . $archive )
		);
	}

	/**
	 * Resumable step handler for direct_transfer job.
	 *
	 * @param array  $job       Job data array.
	 * @param string $worker_id Worker lock identifier.
	 * @return array Updated job state.
	 */
	public static function handle_transfer_step( $job, $worker_id ) {
		$params     = $job['params'];
		$checkpoint = ! empty( $job['checkpoint'] ) ? $job['checkpoint'] : array(
			'stage'             => 'init',
			'chunks_completed'  => 0,
			'bytes_transferred' => 0,
			'last_chunk_index'  => -1,
			'retries_current'   => 0,
			'speed_bps'         => 0,
			'started_at'        => time(),
		);

		$pair_id      = $params['pair_id'];
		$transfer_id  = $params['transfer_id'];
		$archive      = $params['archive'];
		$filepath     = $params['filepath'];
		$total_bytes  = (float) $params['total_bytes'];
		$sha256       = $params['sha256'];
		$chunk_size   = (int) $params['chunk_size'];
		$total_chunks = (int) $params['total_chunks'];

		if ( ! file_exists( $filepath ) ) {
			throw new Exception( esc_html( sprintf( 'Source archive %s missing from disk during transfer.', $archive ) ) );
		}

		// Stage 1: Remote Session Initialization
		if ( 'init' === $checkpoint['stage'] ) {
			$init_res = self::send_remote_init( $pair_id, array(
				'transfer_id'  => $transfer_id,
				'archive'      => $archive,
				'total_bytes'  => $total_bytes,
				'sha256'       => $sha256,
				'chunk_size'   => $chunk_size,
				'total_chunks' => $total_chunks,
			) );

			if ( is_wp_error( $init_res ) ) {
				throw new Exception( esc_html( $init_res->get_error_message() ) );
			}

			// If destination already has some chunks (e.g. from previous attempt), resume from next
			$existing_chunks = is_array( $init_res['received_chunks'] ?? null ) ? $init_res['received_chunks'] : array();
			$checkpoint['stage'] = 'transferring';
			$checkpoint['existing_chunks'] = $existing_chunks;
			$checkpoint['chunks_completed'] = count( $existing_chunks );
			$checkpoint['bytes_transferred'] = count( $existing_chunks ) * $chunk_size;
		}

		// Stage 2: Streaming Chunks Loop
		if ( 'transferring' === $checkpoint['stage'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file stream for chunked transfer.
			$handle = @fopen( $filepath, 'rb' );
			if ( ! $handle ) {
				throw new Exception( esc_html( sprintf( 'Could not open %s for reading.', $archive ) ) );
			}

			$time_start_step = microtime( true );
			$existing_set    = array_flip( $checkpoint['existing_chunks'] ?? array() );

			for ( $chunk_idx = $checkpoint['last_chunk_index'] + 1; $chunk_idx < $total_chunks; $chunk_idx++ ) {
				// Check time budget
				if ( function_exists( 'migrate_lift_out_of_time' ) && migrate_lift_out_of_time() ) {
					break;
				}

				// Skip if chunk was already received by destination in resumed session
				if ( isset( $existing_set[ $chunk_idx ] ) ) {
					$checkpoint['last_chunk_index'] = $chunk_idx;
					continue;
				}

				$offset = (float) $chunk_idx * $chunk_size;
				fseek( $handle, (int) $offset );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Binary chunk read for remote transfer.
				$chunk_bytes = fread( $handle, $chunk_size );

				if ( false === $chunk_bytes || strlen( $chunk_bytes ) === 0 ) {
					break;
				}

				$chunk_len = strlen( $chunk_bytes );
				$chunk_sha = hash( 'sha256', $chunk_bytes );

				// Send chunk to destination with retry
				$send_ok = false;
				$error_msg = '';

				for ( $attempt = 0; $attempt < self::MAX_RETRIES; $attempt++ ) {
					$chunk_res = self::send_remote_chunk( $pair_id, array(
						'transfer_id'  => $transfer_id,
						'chunk_index'  => $chunk_idx,
						'total_chunks' => $total_chunks,
						'chunk_sha256' => $chunk_sha,
						'chunk_data'   => base64_encode( $chunk_bytes ),
					) );

					if ( ! is_wp_error( $chunk_res ) && ! empty( $chunk_res['success'] ) ) {
						$send_ok = true;
						break;
					}

					$error_msg = is_wp_error( $chunk_res ) ? $chunk_res->get_error_message() : ( $chunk_res['message'] ?? 'Chunk failed' );
					// Short backoff delay
					usleep( 250000 * ( 1 << $attempt ) );
				}

				if ( ! $send_ok ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );
					throw new Exception( esc_html( sprintf( 'Failed to transfer chunk %d/%d: %s', $chunk_idx + 1, $total_chunks, $error_msg ) ) );
				}

				$checkpoint['last_chunk_index']   = $chunk_idx;
				$checkpoint['chunks_completed']   += 1;
				$checkpoint['bytes_transferred']  += $chunk_len;

				// Calculate transfer speed
				$elapsed_step = microtime( true ) - $time_start_step;
				if ( $elapsed_step > 0.1 ) {
					$checkpoint['speed_bps'] = round( ( $checkpoint['chunks_completed'] * $chunk_size ) / ( time() - $checkpoint['started_at'] + 1 ) );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			if ( $checkpoint['chunks_completed'] >= $total_chunks ) {
				$checkpoint['stage'] = 'finalizing';
			}
		}

		// Stage 3: Remote Finalization & Integrity Verification
		if ( 'finalizing' === $checkpoint['stage'] ) {
			$fin_res = self::send_remote_finalize( $pair_id, array(
				'transfer_id' => $transfer_id,
				'archive'     => $archive,
				'sha256'      => $sha256,
				'total_bytes' => $total_bytes,
			) );

			if ( is_wp_error( $fin_res ) ) {
				throw new Exception( esc_html( sprintf( 'Finalizing transfer on destination failed: %s', $fin_res->get_error_message() ) ) );
			}

			$checkpoint['stage']       = 'completed';
			$checkpoint['verified']    = true;
			$checkpoint['finished_at'] = time();

			return array(
				'state'      => Migrate_Lift_Job_Engine::STATE_COMPLETED,
				'progress'   => 100,
				'checkpoint' => $checkpoint,
				/* translators: %s: archive name */
				'message'    => sprintf( __( 'Transfer of %s verified and completed successfully.', 'migrate-lift' ), $archive ),
				'results'    => $fin_res,
			);
		}

		$progress = $total_chunks > 0 ? min( 99, round( ( $checkpoint['chunks_completed'] / $total_chunks ) * 100 ) ) : 0;
		$msg = sprintf(
			/* translators: 1: archive name, 2: current chunk index, 3: total chunks, 4: transferred bytes formatted, 5: total bytes formatted */
			__( 'Streaming %1$s (%2$d/%3$d chunks, %4$s/%5$s)', 'migrate-lift' ),
			$archive,
			$checkpoint['chunks_completed'],
			$total_chunks,
			size_format( $checkpoint['bytes_transferred'] ),
			size_format( $total_bytes )
		);

		return array(
			'state'      => Migrate_Lift_Job_Engine::STATE_RUNNING,
			'progress'   => $progress,
			'checkpoint' => $checkpoint,
			'message'    => $msg,
		);
	}

	/**
	 * Destination handler: Initializes incoming transfer session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_destination_init( $request ) {
		$transfer_id  = sanitize_file_name( (string) $request->get_param( 'transfer_id' ) );
		$archive      = sanitize_file_name( (string) $request->get_param( 'archive' ) );
		$total_bytes  = (float) $request->get_param( 'total_bytes' );
		$sha256       = sanitize_text_field( (string) $request->get_param( 'sha256' ) );
		$chunk_size   = (int) $request->get_param( 'chunk_size' );
		$total_chunks = (int) $request->get_param( 'total_chunks' );

		if ( empty( $transfer_id ) || empty( $archive ) || $total_bytes <= 0 ) {
			return new WP_Error( 'invalid_init_payload', __( 'Missing required transfer initialization parameters.', 'migrate-lift' ), array( 'status' => 400 ) );
		}

		// Preflight disk capacity: Require at least 1.5x archive size free space.
		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( migrate_lift_dir() );
			if ( false !== $free && $free < ( $total_bytes * 1.5 ) ) {
				return new WP_Error(
					'insufficient_destination_disk',
					sprintf(
						/* translators: 1: available disk space, 2: required disk space */
						__( 'Destination has insufficient disk space (%1$s free, %2$s required).', 'migrate-lift' ),
						size_format( $free ),
						size_format( $total_bytes * 1.5 )
					),
					array( 'status' => 507 )
				);
			}
		}

		$session_dir = self::get_incoming_dir( $transfer_id );
		$manifest    = array(
			'transfer_id'  => $transfer_id,
			'archive'      => $archive,
			'total_bytes'  => $total_bytes,
			'sha256'       => $sha256,
			'chunk_size'   => $chunk_size,
			'total_chunks' => $total_chunks,
			'created_at'   => time(),
			'received'     => array(),
		);

		// If resuming existing session, detect already written chunk files
		$received = array();
		if ( file_exists( $session_dir . '/manifest.json' ) ) {
			$existing = json_decode( (string) file_get_contents( $session_dir . '/manifest.json' ), true );
			if ( is_array( $existing ) && ( $existing['sha256'] ?? '' ) === $sha256 ) {
				$manifest['received'] = $existing['received'] ?? array();
				$received = array_keys( $manifest['received'] );
			}
		}

		file_put_contents( $session_dir . '/manifest.json', wp_json_encode( $manifest ), LOCK_EX );

		return rest_ensure_response(
			array(
				'success'         => true,
				'transfer_id'     => $transfer_id,
				'status'          => 'ready',
				'received_chunks' => array_map( 'intval', $received ),
			)
		);
	}

	/**
	 * Destination handler: Receives and writes an incoming bounded chunk.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_destination_chunk( $request ) {
		$transfer_id  = sanitize_file_name( (string) $request->get_param( 'transfer_id' ) );
		$chunk_index  = (int) $request->get_param( 'chunk_index' );
		$chunk_sha256 = sanitize_text_field( (string) $request->get_param( 'chunk_sha256' ) );
		$chunk_b64    = (string) $request->get_param( 'chunk_data' );

		if ( empty( $transfer_id ) || empty( $chunk_b64 ) ) {
			return new WP_Error( 'invalid_chunk_payload', __( 'Chunk payload is missing or empty.', 'migrate-lift' ), array( 'status' => 400 ) );
		}

		$session_dir = self::get_incoming_dir( $transfer_id );
		if ( ! file_exists( $session_dir . '/manifest.json' ) ) {
			return new WP_Error( 'session_not_found', __( 'Transfer session not found or expired.', 'migrate-lift' ), array( 'status' => 404 ) );
		}

		$chunk_data = base64_decode( $chunk_b64, true );
		if ( false === $chunk_data ) {
			return new WP_Error( 'chunk_decode_failed', __( 'Could not decode base64 chunk data.', 'migrate-lift' ), array( 'status' => 400 ) );
		}

		// Verify per-chunk SHA-256 checksum
		$calculated_sha = hash( 'sha256', $chunk_data );
		if ( ! hash_equals( $chunk_sha256, $calculated_sha ) ) {
			return new WP_Error( 'chunk_checksum_mismatch', __( 'Chunk SHA-256 checksum verification failed.', 'migrate-lift' ), array( 'status' => 422 ) );
		}

		$chunk_filename = sprintf( 'chunk_%06d.part', $chunk_index );
		$chunk_filepath = $session_dir . '/' . $chunk_filename;

		// Idempotent write
		file_put_contents( $chunk_filepath, $chunk_data, LOCK_EX );

		// Update manifest
		$manifest = json_decode( (string) file_get_contents( $session_dir . '/manifest.json' ), true );
		$manifest['received'][ (string) $chunk_index ] = array(
			'size'   => strlen( $chunk_data ),
			'sha256' => $chunk_sha256,
			'time'   => time(),
		);
		file_put_contents( $session_dir . '/manifest.json', wp_json_encode( $manifest ), LOCK_EX );

		return rest_ensure_response(
			array(
				'success'     => true,
				'chunk_index' => $chunk_index,
				'received'    => count( $manifest['received'] ),
			)
		);
	}

	/**
	 * Destination handler: Finalizes transfer, reassembles and verifies complete archive.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_destination_finalize( $request ) {
		$transfer_id = sanitize_file_name( (string) $request->get_param( 'transfer_id' ) );
		$archive     = sanitize_file_name( (string) $request->get_param( 'archive' ) );
		$expected_sha= sanitize_text_field( (string) $request->get_param( 'sha256' ) );
		$expected_len= (float) $request->get_param( 'total_bytes' );

		$session_dir = self::get_incoming_dir( $transfer_id );
		if ( ! file_exists( $session_dir . '/manifest.json' ) ) {
			return new WP_Error( 'session_not_found', __( 'Transfer session not found.', 'migrate-lift' ), array( 'status' => 404 ) );
		}

		$manifest = json_decode( (string) file_get_contents( $session_dir . '/manifest.json' ), true );
		$total_chunks = (int) $manifest['total_chunks'];

		// Check all chunks exist
		for ( $i = 0; $i < $total_chunks; $i++ ) {
			$part_file = $session_dir . '/' . sprintf( 'chunk_%06d.part', $i );
			if ( ! file_exists( $part_file ) ) {
				/* translators: %d: chunk index */
				return new WP_Error( 'missing_chunk', sprintf( __( 'Chunk %d is missing. Transfer incomplete.', 'migrate-lift' ), $i ), array( 'status' => 400 ) );
			}
		}

		// Reassemble into temporary assembled file
		$assembled_file = $session_dir . '/assembled.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct handle for chunk reassembly.
		$out = @fopen( $assembled_file, 'wb' );
		if ( ! $out ) {
			return new WP_Error( 'reassemble_failed', __( 'Could not open assembled file for writing.', 'migrate-lift' ), array( 'status' => 500 ) );
		}

		for ( $i = 0; $i < $total_chunks; $i++ ) {
			$part_file = $session_dir . '/' . sprintf( 'chunk_%06d.part', $i );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct handle for chunk reassembly.
			$in = @fopen( $part_file, 'rb' );
			if ( $in ) {
				while ( ! feof( $in ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$buf = fread( $in, 1048576 );
					if ( false !== $buf && strlen( $buf ) > 0 ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						fwrite( $out, $buf );
					}
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $in );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		// Verify overall archive checksum
		$actual_sha = hash_file( 'sha256', $assembled_file );
		if ( ! hash_equals( $expected_sha, $actual_sha ) ) {
			wp_delete_file( $assembled_file );
			return new WP_Error(
				'archive_checksum_failed',
				/* translators: 1: expected checksum, 2: actual checksum */
				sprintf( __( 'Overall archive checksum verification failed (expected %1$s, got %2$s).', 'migrate-lift' ), $expected_sha, $actual_sha ),
				array( 'status' => 422 )
			);
		}

		// Move verified archive into standard MigrateLift storage folder
		$dest_archive_path = migrate_lift_dir( $archive );
		// If archive exists, do not overwrite if marked protected
		if ( file_exists( $dest_archive_path ) && function_exists( 'migrate_lift_archive_is_protected' ) && migrate_lift_archive_is_protected( $archive ) ) {
			$archive = 'migrated_' . time() . '_' . $archive;
			$dest_archive_path = migrate_lift_dir( $archive );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $assembled_file, $dest_archive_path );

		// Clean up session directory
		self::cleanup_incoming_session( $transfer_id );

		Migrate_Lift_Activity_Logger::info(
			'direct_transfer_received',
			sprintf( 'Direct transfer archive %s verified and saved (%s)', $archive, size_format( (float) filesize( $dest_archive_path ) ) ),
			array( 'archive' => $archive, 'sha256' => $actual_sha, 'transfer_id' => $transfer_id )
		);

		return rest_ensure_response(
			array(
				'success'  => true,
				'archive'  => $archive,
				'size'     => (float) filesize( $dest_archive_path ),
				'sha256'   => $actual_sha,
				'verified' => true,
				'message'  => __( 'Archive transfer verified and stored safely on destination.', 'migrate-lift' ),
			)
		);
	}

	/**
	 * Deletes incoming temporary transfer chunk files.
	 *
	 * @param string $transfer_id Transfer identifier.
	 */
	public static function cleanup_incoming_session( $transfer_id ) {
		$session_dir = self::get_incoming_dir( $transfer_id );
		if ( ! is_dir( $session_dir ) ) {
			return;
		}

		$files = glob( $session_dir . '/*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $f ) {
				if ( is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $session_dir );
	}

	/**
	 * Sends remote transfer initialization request.
	 *
	 * @param string $pair_id Pair ID.
	 * @param array  $payload Payload.
	 * @return array|WP_Error
	 */
	protected static function send_remote_init( $pair_id, $payload ) {
		return self::send_signed_remote_request( $pair_id, '/transfer/init', $payload );
	}

	/**
	 * Sends a single remote chunk.
	 *
	 * @param string $pair_id Pair ID.
	 * @param array  $payload Payload.
	 * @return array|WP_Error
	 */
	protected static function send_remote_chunk( $pair_id, $payload ) {
		return self::send_signed_remote_request( $pair_id, '/transfer/chunk', $payload );
	}

	/**
	 * Sends remote transfer finalize request.
	 *
	 * @param string $pair_id Pair ID.
	 * @param array  $payload Payload.
	 * @return array|WP_Error
	 */
	protected static function send_remote_finalize( $pair_id, $payload ) {
		return self::send_signed_remote_request( $pair_id, '/transfer/finalize', $payload );
	}

	/**
	 * Sends an authenticated, signed REST request to the paired remote site.
	 *
	 * @param string $pair_id Pair ID.
	 * @param string $subpath Route subpath (e.g. '/transfer/init').
	 * @param array  $payload Request data array.
	 * @return array|WP_Error Decoded response array or WP_Error.
	 */
	public static function send_signed_remote_request( $pair_id, $subpath, $payload = array() ) {
		$pair = Migrate_Lift_Site_Pairing::get_paired_site( $pair_id );
		if ( ! $pair ) {
			return new WP_Error( 'pairing_missing', __( 'Pairing record missing.', 'migrate-lift' ) );
		}

		$endpoint = untrailingslashit( $pair['remote_url'] ) . '/wp-json/' . MIGRATE_LIFT_REST_NAMESPACE . $subpath;
		$body_str = wp_json_encode( $payload );

		$headers = Migrate_Lift_Site_Pairing::sign_request( $pair_id, $body_str );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$headers['Content-Type'] = 'application/json';
		$headers['Accept']       = 'application/json';

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'     => $headers,
				'body'        => $body_str,
				'timeout'     => 60,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'migrate_lift_ssl_verify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 || empty( $data['success'] ) ) {
			$msg = ! empty( $data['message'] ) ? $data['message'] : ( ! empty( $data['code'] ) ? $data['code'] : 'HTTP ' . $status );
			/* translators: 1: HTTP status code, 2: remote error message */
			return new WP_Error( 'remote_error_' . $status, sprintf( __( 'Remote error (%1$d): %2$s', 'migrate-lift' ), $status, $msg ), array( 'status' => $status ) );
		}

		return $data;
	}
}

