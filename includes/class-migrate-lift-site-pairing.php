<?php
/**
 * Site Pairing & Security Engine.
 *
 * Implements cryptographically secure, time-limited, single-use site pairing,
 * SSRF target validation, and HMAC-SHA256 authenticated cross-site communications
 * with replay protection.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrate_Lift_Site_Pairing
 */
class Migrate_Lift_Site_Pairing {

	/**
	 * Default pairing token lifespan: 15 minutes (900 seconds).
	 */
	const TOKEN_LIFESPAN = 900;

	/**
	 * Allowed clock skew window for request signatures: 5 minutes (300 seconds).
	 */
	const SIGNATURE_WINDOW = 300;

	/**
	 * Option key for pending pairing tokens.
	 */
	const OPTION_PENDING_TOKENS = 'migrate_lift_pending_pairings';

	/**
	 * Option key for established paired sites.
	 */
	const OPTION_PAIRED_SITES = 'migrate_lift_paired_sites';

	/**
	 * Generates a short-lived single-use pairing token on the destination site.
	 *
	 * @return array Token details including formatted pairing key.
	 */
	public static function generate_pairing_token() {
		self::prune_expired_tokens();

		$token  = bin2hex( random_bytes( 16 ) );
		$secret = bin2hex( random_bytes( 24 ) );
		$now    = time();
		$expiry = $now + self::TOKEN_LIFESPAN;

		$pending = get_option( self::OPTION_PENDING_TOKENS, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}

		$pending[ $token ] = array(
			'token'      => $token,
			'secret'     => $secret,
			'created_at' => $now,
			'expires_at' => $expiry,
			'consumed'   => false,
		);

		update_option( self::OPTION_PENDING_TOKENS, $pending, false );

		$endpoint = esc_url_raw( rest_url( MIGRATE_LIFT_REST_NAMESPACE . '/pair/handshake' ) );
		$site_url = esc_url_raw( home_url() );

		$payload = array(
			'v'        => 1,
			'endpoint' => $endpoint,
			'site_url' => $site_url,
			'site_name'=> get_bloginfo( 'name' ),
			'token'    => $token,
			'secret'   => $secret,
			'exp'      => $expiry,
		);

		$key_string = 'MLPAIR:' . base64_encode( wp_json_encode( $payload ) );

		Migrate_Lift_Activity_Logger::info(
			'pairing_token_generated',
			sprintf( 'Generated pairing key expiring at %s', wp_date( 'Y-m-d H:i:s', $expiry ) ),
			array( 'expires_at' => $expiry )
		);

		return array(
			'pairing_key' => $key_string,
			'expires_at'  => $expiry,
			'expires_in'  => self::TOKEN_LIFESPAN,
			'site_url'    => $site_url,
		);
	}

	/**
	 * Whether private addresses and plain HTTP are allowed, which is only for local development.
	 *
	 * @param string $url Target URL.
	 * @return bool
	 */
	public static function is_local_transfer_allowed( $url = '' ) {
		$allow_local = defined( 'MIGRATE_LIFT_ALLOW_LOCAL_TRANSFER' ) && MIGRATE_LIFT_ALLOW_LOCAL_TRANSFER;
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			$allow_local = true;
		}
		return (bool) apply_filters( 'migrate_lift_allow_local_transfer', $allow_local, $url );
	}

	/**
	 * Adds the safety options every cross-site request uses. WordPress checks the address again when
	 * it connects, so a host name cannot be switched to an internal address after validation.
	 *
	 * @param array $args Request arguments.
	 * @return array
	 */
	public static function request_args( array $args ) {
		return array_merge(
			array(
				'redirection'        => 0, // Redirects could lead to an address that was never validated.
				'sslverify'          => apply_filters( 'migrate_lift_ssl_verify', true ),
				'reject_unsafe_urls' => ! self::is_local_transfer_allowed(),
			),
			$args
		);
	}

	/**
	 * Whether two URLs are on the same host and port.
	 *
	 * @param string $url_a First URL.
	 * @param string $url_b Second URL.
	 * @return bool
	 */
	protected static function is_same_host( $url_a, $url_b ) {
		$a = wp_parse_url( (string) $url_a );
		$b = wp_parse_url( (string) $url_b );
		return ! empty( $a['host'] ) && ! empty( $b['host'] )
			&& strtolower( $a['host'] ) === strtolower( $b['host'] )
			&& (int) ( $a['port'] ?? 0 ) === (int) ( $b['port'] ?? 0 );
	}

	/**
	 * Builds the URL of a route on the paired site, using the REST base that site reported so
	 * custom REST prefixes and plain permalinks work.
	 *
	 * @param array  $pair    Paired site record.
	 * @param string $subpath Route, such as /transfer/init.
	 * @return string
	 */
	public static function remote_rest_url( $pair, $subpath ) {
		$base = ! empty( $pair['rest_base'] )
			? $pair['rest_base']
			: untrailingslashit( $pair['remote_url'] ) . '/wp-json/' . MIGRATE_LIFT_REST_NAMESPACE;
		return untrailingslashit( $base ) . $subpath;
	}

	/**
	 * Validates a target URL against SSRF vulnerabilities (rejecting private/internal IP ranges
	 * and cloud metadata services), unless local transfer mode is explicitly enabled.
	 *
	 * @param string $url Target URL to test.
	 * @return true|WP_Error True if valid, WP_Error if unsafe.
	 */
	public static function validate_target_url( $url ) {
		$url = trim( (string) $url );
		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'URL cannot be empty.', 'migrate-lift' ) );
		}

		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error( 'malformed_url', __( 'The provided target URL is malformed.', 'migrate-lift' ) );
		}

		$scheme = strtolower( $parsed['scheme'] );
		$host   = strtolower( $parsed['host'] );

		// Only HTTP and HTTPS are permitted.
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_scheme', __( 'Only HTTP and HTTPS protocols are supported for pairing.', 'migrate-lift' ) );
		}

		// Allow local development overrides if explicitly defined or WP_ENVIRONMENT_TYPE is local.
		if ( self::is_local_transfer_allowed( $url ) ) {
			return true;
		}

		// Pairing keys and signing keys must never cross the internet unencrypted.
		if ( 'https' !== $scheme ) {
			return new WP_Error( 'https_required', __( 'Both sites must use HTTPS for a direct transfer.', 'migrate-lift' ) );
		}

		// Reject obvious localhost and loopback hostnames.
		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return new WP_Error( 'ssrf_loopback_blocked', __( 'Localhost and loopback destinations are restricted to prevent SSRF vulnerabilities.', 'migrate-lift' ) );
		}

		// Resolve host to IP address to inspect IP boundaries.
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$dns_records = @dns_get_record( $host, DNS_A + DNS_AAAA );
			if ( is_array( $dns_records ) ) {
				foreach ( $dns_records as $record ) {
					if ( isset( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					} elseif ( isset( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
			// Fallback to gethostbyname if dns_get_record returned empty.
			if ( empty( $ips ) ) {
				$resolved = @gethostbyname( $host );
				if ( $resolved && $resolved !== $host ) {
					$ips[] = $resolved;
				}
			}
		}

		// Block targets where host could not be resolved.
		if ( empty( $ips ) ) {
			/* translators: %s: host name */
			return new WP_Error( 'dns_resolution_failed', sprintf( __( 'Could not resolve hostname: %s', 'migrate-lift' ), $host ) );
		}

		foreach ( $ips as $ip ) {
			// Check IPv4 private and reserved ranges.
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$long = ip2long( $ip );
				if ( false === $long ) {
					continue;
				}

				// Loopback: 127.0.0.0/8
				if ( ( $long & 0xFF000000 ) === 0x7F000000 ) {
					return new WP_Error( 'ssrf_loopback_ip', __( 'Target IP resolves to a restricted loopback address (127.0.0.0/8).', 'migrate-lift' ) );
				}
				// RFC1918 Private: 10.0.0.0/8
				if ( ( $long & 0xFF000000 ) === 0x0A000000 ) {
					return new WP_Error( 'ssrf_private_network', __( 'Target IP resolves to private network space (10.0.0.0/8).', 'migrate-lift' ) );
				}
				// RFC1918 Private: 172.16.0.0/12
				if ( ( $long & 0xFFF00000 ) === 0xAC100000 ) {
					return new WP_Error( 'ssrf_private_network', __( 'Target IP resolves to private network space (172.16.0.0/12).', 'migrate-lift' ) );
				}
				// RFC1918 Private: 192.168.0.0/16
				if ( ( $long & 0xFFFF0000 ) === 0xC0A80000 ) {
					return new WP_Error( 'ssrf_private_network', __( 'Target IP resolves to private network space (192.168.0.0/16).', 'migrate-lift' ) );
				}
				// Link-Local & Cloud Metadata: 169.254.0.0/16 (e.g. AWS/GCP 169.254.169.254)
				if ( ( $long & 0xFFFF0000 ) === 0xA9FE0000 ) {
					return new WP_Error( 'ssrf_cloud_metadata', __( 'Target IP resolves to link-local or cloud metadata service (169.254.0.0/16).', 'migrate-lift' ) );
				}
				// Carrier-grade NAT: 100.64.0.0/10
				if ( ( $long & 0xFFC00000 ) === 0x64400000 ) {
					return new WP_Error( 'ssrf_private_network', __( 'Target IP resolves to private network space (100.64.0.0/10).', 'migrate-lift' ) );
				}
			}

			// Everything else that is not a public address, including IPv6 loopback, link-local and unique-local.
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error( 'ssrf_private_network', __( 'Target IP resolves to a private or reserved address.', 'migrate-lift' ) );
			}
		}

		return true;
	}

	/**
	 * Parses and decodes a raw pairing key string.
	 *
	 * @param string $key_string Raw pairing key starting with MLPAIR:.
	 * @return array|WP_Error Decoded payload or WP_Error.
	 */
	public static function parse_pairing_key( $key_string ) {
		$key_string = trim( (string) $key_string );
		if ( 0 !== strpos( $key_string, 'MLPAIR:' ) ) {
			return new WP_Error( 'invalid_pairing_format', __( 'Invalid pairing key format. Expected MLPAIR: prefix.', 'migrate-lift' ) );
		}

		$encoded = substr( $key_string, 7 );
		$json    = base64_decode( $encoded, true );
		if ( false === $json ) {
			return new WP_Error( 'pairing_decode_failed', __( 'Could not decode pairing key base64 data.', 'migrate-lift' ) );
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || empty( $payload['endpoint'] ) || empty( $payload['token'] ) || empty( $payload['exp'] ) ) {
			return new WP_Error( 'pairing_payload_invalid', __( 'Pairing key payload is missing required connection fields.', 'migrate-lift' ) );
		}

		if ( time() > (int) $payload['exp'] ) {
			return new WP_Error( 'pairing_key_expired', __( 'This pairing key has expired. Please generate a new key on the destination site.', 'migrate-lift' ) );
		}

		return $payload;
	}

	/**
	 * Consumes a single-use pending pairing token on the destination site.
	 *
	 * @param string $token_string 32-char hex token.
	 * @return array|WP_Error Pending record or WP_Error.
	 */
	public static function consume_pairing_token( $token_string ) {
		$pending = get_option( self::OPTION_PENDING_TOKENS, array() );
		if ( ! is_array( $pending ) || ! isset( $pending[ $token_string ] ) ) {
			return new WP_Error( 'token_not_found', __( 'Pairing token not found or already consumed.', 'migrate-lift' ), array( 'status' => 404 ) );
		}

		$record = $pending[ $token_string ];

		// Remove token immediately to guarantee single-use.
		unset( $pending[ $token_string ] );
		update_option( self::OPTION_PENDING_TOKENS, $pending, false );

		if ( ! empty( $record['consumed'] ) ) {
			return new WP_Error( 'token_already_consumed', __( 'Pairing token has already been consumed.', 'migrate-lift' ), array( 'status' => 409 ) );
		}

		if ( time() > (int) $record['expires_at'] ) {
			return new WP_Error( 'token_expired', __( 'Pairing token has expired.', 'migrate-lift' ), array( 'status' => 410 ) );
		}

		return $record;
	}

	/**
	 * Handles incoming handshake request on the destination site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_destination_handshake( $request ) {
		$token       = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$source_url  = esc_url_raw( (string) $request->get_param( 'source_url' ) );
		$source_name = sanitize_text_field( (string) $request->get_param( 'source_name' ) );
		$source_rest = esc_url_raw( (string) $request->get_param( 'rest_base' ) );

		if ( is_multisite() ) {
			return new WP_Error( 'multisite_unsupported', __( 'Multisite is not supported.', 'migrate-lift' ), array( 'status' => 403 ) );
		}

		if ( empty( $token ) || empty( $source_url ) ) {
			return new WP_Error( 'missing_params', __( 'Token and source_url parameters are required.', 'migrate-lift' ), array( 'status' => 400 ) );
		}

		// Validate source URL against SSRF.
		$ssrf_check = self::validate_target_url( $source_url );
		if ( is_wp_error( $ssrf_check ) ) {
			return new WP_Error( 'ssrf_source_rejected', $ssrf_check->get_error_message(), array( 'status' => 403 ) );
		}

		// Validate and consume single-use token.
		$token_record = self::consume_pairing_token( $token );
		if ( is_wp_error( $token_record ) ) {
			return $token_record;
		}

		// Establish shared credentials.
		$pair_id   = 'pair_' . bin2hex( random_bytes( 8 ) );
		$auth_key  = bin2hex( random_bytes( 32 ) );
		$paired_at = time();

		$paired_sites = get_option( self::OPTION_PAIRED_SITES, array() );
		if ( ! is_array( $paired_sites ) ) {
			$paired_sites = array();
		}

		$paired_sites[ $pair_id ] = array(
			'pair_id'     => $pair_id,
			'role'        => 'destination',
			'remote_url'  => $source_url,
			'remote_name' => $source_name,
			'rest_base'   => self::is_same_host( $source_rest, $source_url ) ? $source_rest : '',
			'auth_key'    => $auth_key,
			'paired_at'   => $paired_at,
			'last_active' => $paired_at,
			'status'      => 'connected',
		);

		update_option( self::OPTION_PAIRED_SITES, $paired_sites, false );

		Migrate_Lift_Activity_Logger::info(
			'site_paired_as_destination',
			sprintf( 'Paired successfully with source site %s (%s)', $source_name, $source_url ),
			array( 'pair_id' => $pair_id, 'source_url' => $source_url )
		);

		// Disk space info for preflight.
		$free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( migrate_lift_dir() ) : 0;

		return rest_ensure_response(
			array(
				'success'           => true,
				'pair_id'           => $pair_id,
				'auth_key'          => $auth_key,
				'destination_url'   => esc_url_raw( home_url() ),
				'destination_name'  => get_bloginfo( 'name' ),
				'wp_version'        => get_bloginfo( 'version' ),
				'plugin_version'    => MIGRATE_LIFT_VERSION,
				'php_version'       => PHP_VERSION,
				'max_upload_size'   => wp_max_upload_size(),
				'disk_free_space'   => $free_space,
				'transfer_endpoint' => esc_url_raw( rest_url( MIGRATE_LIFT_REST_NAMESPACE . '/transfer' ) ),
				'rest_base'         => esc_url_raw( rest_url( MIGRATE_LIFT_REST_NAMESPACE ) ),
			)
		);
	}

	/**
	 * Initiates pairing connection from the source site to the destination site.
	 *
	 * @param string $pairing_key Raw pairing key string.
	 * @return array|WP_Error Connected site metadata or WP_Error.
	 */
	public static function initiate_source_connection( $pairing_key ) {
		$parsed = self::parse_pairing_key( $pairing_key );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$endpoint = (string) $parsed['endpoint'];
		$dest_url = (string) ( $parsed['site_url'] ?? '' );

		// SSRF check both URLs: the request goes to the endpoint, and it must belong to the site.
		foreach ( array( $dest_url, $endpoint ) as $target ) {
			$ssrf = self::validate_target_url( $target );
			if ( is_wp_error( $ssrf ) ) {
				return $ssrf;
			}
		}
		if ( ! self::is_same_host( $endpoint, $dest_url ) ) {
			return new WP_Error( 'pairing_endpoint_mismatch', __( 'This pairing key points to a different host than its site. Generate a new key on the destination site.', 'migrate-lift' ) );
		}

		$body = array(
			'token'       => $parsed['token'],
			'source_url'  => esc_url_raw( home_url() ),
			'source_name' => get_bloginfo( 'name' ),
			'rest_base'   => esc_url_raw( rest_url( MIGRATE_LIFT_REST_NAMESPACE ) ),
			'wp_version'  => get_bloginfo( 'version' ),
			'version'     => MIGRATE_LIFT_VERSION,
		);

		$response = wp_remote_post(
			$endpoint,
			self::request_args(
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
					'timeout' => 20,
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'handshake_failed',
				/* translators: %s: error message */
				sprintf( __( 'Handshake request to destination failed: %s', 'migrate-lift' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $raw_body, true );

		if ( 200 !== $status_code || empty( $data['success'] ) ) {
			$msg = ! empty( $data['message'] ) ? $data['message'] : ( ! empty( $data['code'] ) ? $data['code'] : 'HTTP ' . $status_code );
			/* translators: %s: rejection message */
			return new WP_Error( 'destination_rejected_handshake', sprintf( __( 'Destination rejected pairing: %s', 'migrate-lift' ), $msg ), array( 'status' => $status_code ) );
		}

		$pair_id  = sanitize_text_field( $data['pair_id'] );
		$auth_key = sanitize_text_field( $data['auth_key'] );
		$rest     = esc_url_raw( $data['rest_base'] ?? '' );
		$now      = time();

		$paired_sites = get_option( self::OPTION_PAIRED_SITES, array() );
		if ( ! is_array( $paired_sites ) ) {
			$paired_sites = array();
		}

		$paired_sites[ $pair_id ] = array(
			'pair_id'           => $pair_id,
			'role'              => 'source',
			'remote_url'        => $dest_url,
			'remote_name'       => sanitize_text_field( $data['destination_name'] ?? '' ),
			'rest_base'         => self::is_same_host( $rest, $dest_url ) ? $rest : '',
			'auth_key'          => $auth_key,
			'destination_meta'  => array(
				'wp_version'      => sanitize_text_field( $data['wp_version'] ?? '' ),
				'plugin_version'  => sanitize_text_field( $data['plugin_version'] ?? '' ),
				'php_version'     => sanitize_text_field( $data['php_version'] ?? '' ),
				'max_upload_size' => (int) ( $data['max_upload_size'] ?? 0 ),
				'disk_free_space' => (float) ( $data['disk_free_space'] ?? 0 ),
			),
			'transfer_endpoint' => esc_url_raw( $data['transfer_endpoint'] ?? '' ),
			'paired_at'         => $now,
			'last_active'       => $now,
			'status'            => 'connected',
		);

		update_option( self::OPTION_PAIRED_SITES, $paired_sites, false );

		Migrate_Lift_Activity_Logger::info(
			'site_paired_as_source',
			sprintf( 'Paired successfully with destination site %s (%s)', $data['destination_name'] ?? $dest_url, $dest_url ),
			array( 'pair_id' => $pair_id, 'dest_url' => $dest_url )
		);

		return array(
			'success'          => true,
			'pair_id'          => $pair_id,
			'remote_url'       => $dest_url,
			'remote_name'      => $data['destination_name'] ?? '',
			'destination_meta' => $paired_sites[ $pair_id ]['destination_meta'],
			'status'           => 'connected',
		);
	}

	/**
	 * Computes HMAC-SHA256 signature headers for cross-site requests.
	 *
	 * @param string $pair_id     Pair identifier.
	 * @param string $body_string Raw payload body string.
	 * @return array|WP_Error Request headers or error.
	 */
	public static function sign_request( $pair_id, $body_string = '' ) {
		$pair = self::get_paired_site( $pair_id );
		if ( ! $pair || empty( $pair['auth_key'] ) ) {
			return new WP_Error( 'unknown_pairing', __( 'No active pairing record found for this identifier.', 'migrate-lift' ) );
		}

		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		$payload   = $timestamp . '.' . $nonce . '.' . $body_string;
		$signature = hash_hmac( 'sha256', $payload, $pair['auth_key'] );

		return array(
			'X-ML-Pair-Id'   => $pair_id,
			'X-ML-Timestamp' => $timestamp,
			'X-ML-Nonce'     => $nonce,
			'X-ML-Signature' => $signature,
		);
	}

	/**
	 * Verifies HMAC-SHA256 signature, clock skew, and replay nonce on incoming cross-site requests.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error True on success, WP_Error on validation failure.
	 */
	public static function verify_request_signature( $request ) {
		$pair_id   = $request->get_header( 'x-ml-pair-id' );
		$timestamp = (int) $request->get_header( 'x-ml-timestamp' );
		$nonce     = sanitize_key( (string) $request->get_header( 'x-ml-nonce' ) );
		$signature = sanitize_text_field( (string) $request->get_header( 'x-ml-signature' ) );

		if ( empty( $pair_id ) || empty( $timestamp ) || empty( $nonce ) || empty( $signature ) ) {
			return new WP_Error( 'missing_signature_headers', __( 'Required HMAC signature headers are missing.', 'migrate-lift' ), array( 'status' => 401 ) );
		}

		$pair = self::get_paired_site( $pair_id );
		if ( ! $pair || empty( $pair['auth_key'] ) || 'connected' !== $pair['status'] ) {
			return new WP_Error( 'unauthorized_pairing', __( 'Pairing ID is invalid or revoked.', 'migrate-lift' ), array( 'status' => 403 ) );
		}

		// 1. Clock skew check (prevent replay of old requests).
		$now = time();
		if ( abs( $now - $timestamp ) > self::SIGNATURE_WINDOW ) {
			return new WP_Error( 'signature_timestamp_expired', __( 'Request timestamp is outside the allowed 5-minute skew window.', 'migrate-lift' ), array( 'status' => 403 ) );
		}

		// 2. Compute and verify expected HMAC signature with constant-time comparison.
		$raw_body = $request->get_body();
		$payload  = (string) $timestamp . '.' . $nonce . '.' . $raw_body;
		$expected = hash_hmac( 'sha256', $payload, $pair['auth_key'] );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'invalid_signature', __( 'HMAC signature mismatch.', 'migrate-lift' ), array( 'status' => 403 ) );
		}

		// 3. Nonce cache check (prevent replay attacks).
		$nonce_transient = 'migrate_lift_sig_' . substr( hash( 'sha256', $nonce ), 0, 32 );
		if ( get_transient( $nonce_transient ) ) {
			return new WP_Error( 'replay_attack_detected', __( 'Signature nonce has already been used (replay detected).', 'migrate-lift' ), array( 'status' => 403 ) );
		}
		// Cache nonce for double the window (10 mins).
		set_transient( $nonce_transient, 1, self::SIGNATURE_WINDOW * 2 );

		// Update last active timestamp.
		self::update_last_active( $pair_id );

		return true;
	}

	/**
	 * Sends a signed ping request to verify round-trip cross-site connectivity.
	 *
	 * @param string $pair_id Pair identifier.
	 * @return array|WP_Error Ping result or error.
	 */
	public static function verify_round_trip( $pair_id ) {
		$pair = self::get_paired_site( $pair_id );
		if ( ! $pair ) {
			return new WP_Error( 'pairing_not_found', __( 'Pairing not found.', 'migrate-lift' ) );
		}

		$endpoint = self::remote_rest_url( $pair, '/pair/ping' );

		$body_str = wp_json_encode( array( 'client_time' => time(), 'pair_id' => $pair_id ) );
		$headers  = self::sign_request( $pair_id, $body_str );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$headers['Content-Type'] = 'application/json';
		$headers['Accept']       = 'application/json';

		$start_time = microtime( true );
		$response   = wp_remote_post(
			$endpoint,
			self::request_args(
				array(
					'headers' => $headers,
					'body'    => $body_str,
					'timeout' => 15,
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'ping_failed', sprintf( __( 'Connection check failed: %s', 'migrate-lift' ), $response->get_error_message() ) );
		}

		$latency_ms  = round( ( microtime( true ) - $start_time ) * 1000 );
		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code || empty( $data['pong'] ) ) {
			return new WP_Error(
				'ping_error',
				/* translators: 1: HTTP status code, 2: response message */
				sprintf( __( 'Remote site responded with HTTP %1$d: %2$s', 'migrate-lift' ), $status_code, $data['message'] ?? 'Check failed' )
			);
		}

		self::update_last_active( $pair_id );

		return array(
			'connected'    => true,
			'latency_ms'   => $latency_ms,
			'remote_url'   => $pair['remote_url'],
			'remote_name'  => $pair['remote_name'],
			'remote_time'  => $data['server_time'] ?? time(),
			'last_checked' => time(),
		);
	}

	/**
	 * Revokes a paired site locally and attempts to notify the remote end.
	 *
	 * @param string $pair_id Pair identifier.
	 * @return bool
	 */
	public static function revoke_pairing( $pair_id ) {
		$pair = self::get_paired_site( $pair_id );
		if ( ! $pair ) {
			return false;
		}

		// Best-effort remote notification.
		try {
			$endpoint = self::remote_rest_url( $pair, '/pair/revoke-remote' );
			$body_str = wp_json_encode( array( 'pair_id' => $pair_id, 'revoked_at' => time() ) );
			$headers  = self::sign_request( $pair_id, $body_str );
			if ( ! is_wp_error( $headers ) ) {
				$headers['Content-Type'] = 'application/json';
				wp_remote_post(
					$endpoint,
					self::request_args(
						array(
							'headers'  => $headers,
							'body'     => $body_str,
							'timeout'  => 5,
							'blocking' => false, // Non-blocking best effort.
						)
					)
				);
			}
		} catch ( Exception $_e ) {
			// Continue with local cleanup regardless
		}

		$paired = get_option( self::OPTION_PAIRED_SITES, array() );
		if ( isset( $paired[ $pair_id ] ) ) {
			unset( $paired[ $pair_id ] );
			update_option( self::OPTION_PAIRED_SITES, $paired, false );
		}

		Migrate_Lift_Activity_Logger::info(
			'site_pairing_revoked',
			sprintf( 'Revoked pairing with %s (%s)', $pair['remote_name'] ?? 'Remote Site', $pair['remote_url'] ),
			array( 'pair_id' => $pair_id )
		);

		return true;
	}

	/**
	 * Returns all paired sites with sensitive secret credentials masked.
	 *
	 * @return array
	 */
	public static function get_paired_sites_summary() {
		$paired = get_option( self::OPTION_PAIRED_SITES, array() );
		if ( ! is_array( $paired ) ) {
			return array();
		}

		$summary = array();
		foreach ( $paired as $id => $item ) {
			$summary[] = array(
				'pair_id'          => $item['pair_id'],
				'role'             => $item['role'],
				'remote_url'       => $item['remote_url'],
				'remote_name'      => $item['remote_name'],
				'paired_at'        => $item['paired_at'],
				'last_active'      => $item['last_active'],
				'status'           => $item['status'],
				'destination_meta' => $item['destination_meta'] ?? null,
			);
		}

		return $summary;
	}

	/**
	 * Gets a single paired site record including credentials.
	 *
	 * @param string $pair_id Pair identifier.
	 * @return array|null
	 */
	public static function get_paired_site( $pair_id ) {
		$paired = get_option( self::OPTION_PAIRED_SITES, array() );
		return isset( $paired[ $pair_id ] ) ? $paired[ $pair_id ] : null;
	}

	/**
	 * Updates the last active timestamp for a paired site.
	 *
	 * @param string $pair_id Pair identifier.
	 */
	protected static function update_last_active( $pair_id ) {
		$paired = get_option( self::OPTION_PAIRED_SITES, array() );
		if ( isset( $paired[ $pair_id ] ) ) {
			$paired[ $pair_id ]['last_active'] = time();
			update_option( self::OPTION_PAIRED_SITES, $paired, false );
		}
	}

	/**
	 * Removes expired pending tokens from storage.
	 */
	public static function prune_expired_tokens() {
		$pending = get_option( self::OPTION_PENDING_TOKENS, array() );
		if ( ! is_array( $pending ) ) {
			return;
		}

		$now     = time();
		$changed = false;
		foreach ( $pending as $token => $data ) {
			if ( $now > (int) ( $data['expires_at'] ?? 0 ) ) {
				unset( $pending[ $token ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_PENDING_TOKENS, $pending, false );
		}
	}
}

