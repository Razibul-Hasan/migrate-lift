<?php
/**
 * REST API Endpoints under namespace migrate-lift/v1.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers MigrateLift REST API routes.
 */
function migrate_lift_register_rest_routes() {
	$namespace = MIGRATE_LIFT_REST_NAMESPACE;

	// Permission callback for admin routes.
	$admin_permission = function () {
		return current_user_can( 'manage_options' ) && ! is_multisite();
	};

	// GET /migrate-lift/v1/health
	register_rest_route(
		$namespace,
		'/health',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_health',
			'permission_callback' => $admin_permission,
		)
	);

	// GET /migrate-lift/v1/status
	register_rest_route(
		$namespace,
		'/status',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_status',
			'permission_callback' => $admin_permission,
		)
	);

	// GET /migrate-lift/v1/archives
	register_rest_route(
		$namespace,
		'/archives',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_archives',
			'permission_callback' => $admin_permission,
		)
	);

	// GET /migrate-lift/v1/archives/(?P<file>[a-zA-Z0-9._-]+)
	register_rest_route(
		$namespace,
		'/archives/(?P<file>[a-zA-Z0-9._-]+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_get_archive_details',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'file' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	// GET /migrate-lift/v1/dashboard/summary
	register_rest_route(
		$namespace,
		'/dashboard/summary',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_dashboard_summary',
			'permission_callback' => $admin_permission,
		)
	);

	// DELETE /migrate-lift/v1/archives/(?P<file>[a-zA-Z0-9._-]+)
	register_rest_route(
		$namespace,
		'/archives/(?P<file>[a-zA-Z0-9._-]+)',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'migrate_lift_rest_delete_archive',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'file' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	// POST /migrate-lift/v1/export
	register_rest_route(
		$namespace,
		'/export',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_export',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/import
	register_rest_route(
		$namespace,
		'/import',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_import',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/cleanup
	register_rest_route(
		$namespace,
		'/cleanup',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_cleanup',
			'permission_callback' => '__return_true', // Validated via token in callback.
		)
	);

	// POST /migrate-lift/v1/clean
	register_rest_route(
		$namespace,
		'/clean',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_clean',
			'permission_callback' => $admin_permission,
		)
	);

	// GET /migrate-lift/v1/jobs
	register_rest_route(
		$namespace,
		'/jobs',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_get_jobs',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'state'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'kind'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'limit'  => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 50,
					'sanitize_callback' => 'absint',
				),
				'offset' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	// POST /migrate-lift/v1/jobs
	register_rest_route(
		$namespace,
		'/jobs',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_create_job',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'kind'            => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'params'          => array(
					'required' => false,
					'type'     => 'object',
					'default'  => array(),
				),
				'idempotency_key' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// GET /migrate-lift/v1/jobs/(?P<id>[a-zA-Z0-9_-]+)
	register_rest_route(
		$namespace,
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_get_job',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	// POST /migrate-lift/v1/jobs/(?P<id>[a-zA-Z0-9_-]+)/step
	register_rest_route(
		$namespace,
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)/step',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_step_job',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'id'        => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'worker_id' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// POST /migrate-lift/v1/jobs/(?P<id>[a-zA-Z0-9_-]+)/pause
	register_rest_route(
		$namespace,
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)/pause',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pause_job',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	// POST /migrate-lift/v1/jobs/(?P<id>[a-zA-Z0-9_-]+)/resume
	register_rest_route(
		$namespace,
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)/resume',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_resume_job',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	// POST /migrate-lift/v1/jobs/(?P<id>[a-zA-Z0-9_-]+)/cancel
	register_rest_route(
		$namespace,
		'/jobs/(?P<id>[a-zA-Z0-9_-]+)/cancel',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_cancel_job',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	// GET /migrate-lift/v1/activity
	register_rest_route(
		$namespace,
		'/activity',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_get_activity',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'search' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'level'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'job_id' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'limit'  => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 50,
					'sanitize_callback' => 'absint',
				),
				'offset' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	// POST /migrate-lift/v1/activity/clear
	register_rest_route(
		$namespace,
		'/activity/clear',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_clear_activity',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/backup/start
	register_rest_route(
		$namespace,
		'/backup/start',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_start_backup',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'params'          => array(
					'required' => false,
					'type'     => 'object',
					'default'  => array(),
				),
				'idempotency_key' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// POST /migrate-lift/v1/backup/verify
	register_rest_route(
		$namespace,
		'/backup/verify',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_verify_backup',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'file' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	// GET /migrate-lift/v1/settings/compression
	register_rest_route(
		$namespace,
		'/settings/compression',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_get_compression_settings',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/settings/compression
	register_rest_route(
		$namespace,
		'/settings/compression',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_save_compression_settings',
			'permission_callback' => $admin_permission,
		)
	);

	// GET /migrate-lift/v1/settings/schedule
	register_rest_route(
		$namespace,
		'/settings/schedule',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_get_schedule_settings',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/settings/schedule
	register_rest_route(
		$namespace,
		'/settings/schedule',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_save_schedule_settings',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/schedule/run-now
	register_rest_route(
		$namespace,
		'/schedule/run-now',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_run_schedule_now',
			'permission_callback' => $admin_permission,
		)
	);

	// GET /migrate-lift/v1/import/staging
	register_rest_route(
		$namespace,
		'/import/staging',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_get_import_staging',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/import/rescan
	register_rest_route(
		$namespace,
		'/import/rescan',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_rescan_import_staging',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/import/upload-status
	register_rest_route(
		$namespace,
		'/import/upload-status',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_upload_status',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'filename' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	// POST /migrate-lift/v1/import/upload-chunk
	register_rest_route(
		$namespace,
		'/import/upload-chunk',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_upload_chunk',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/import/upload-cancel
	register_rest_route(
		$namespace,
		'/import/upload-cancel',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_upload_cancel',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'filename' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	// POST /migrate-lift/v1/import/verify-staged
	register_rest_route(
		$namespace,
		'/import/verify-staged',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_verify_staged_import',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'filename' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	// POST /migrate-lift/v1/import/exposure-check
	register_rest_route(
		$namespace,
		'/import/exposure-check',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_check_exposure',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/restore/preflight
	register_rest_route(
		$namespace,
		'/restore/preflight',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_restore_preflight',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'file' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	// POST /migrate-lift/v1/restore/dry-run
	register_rest_route(
		$namespace,
		'/restore/dry-run',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_restore_dry_run',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'file' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	// POST /migrate-lift/v1/restore/checkpoint
	register_rest_route(
		$namespace,
		'/restore/checkpoint',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_restore_create_checkpoint',
			'permission_callback' => $admin_permission,
		)
	);

	// GET /migrate-lift/v1/restore/recovery-status
	register_rest_route(
		$namespace,
		'/restore/recovery-status',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_restore_get_recovery_status',
			'permission_callback' => $admin_permission,
		)
	);

	// POST /migrate-lift/v1/restore/diff-preview
	register_rest_route(
		$namespace,
		'/restore/diff-preview',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_restore_diff_preview',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'file' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
				'file_mode' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'overwrite_matching',
					'sanitize_callback' => 'sanitize_key',
				),
				'db_mode' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'replace_mapped',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	// POST /migrate-lift/v1/restore/execute
	register_rest_route(
		$namespace,
		'/restore/execute',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_restore_execute',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'file' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
				'confirm_host' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// Permission callback for signed cross-site HMAC requests.
	$signed_permission = function ( $request ) {
		if ( ! class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
			return new WP_Error( 'pairing_missing', __( 'Site pairing module is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
		}
		return Migrate_Lift_Site_Pairing::verify_request_signature( $request );
	};

	// --- Site Pairing Routes (Part 13) ---
	register_rest_route(
		$namespace,
		'/pair/token',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_generate_token',
			'permission_callback' => $admin_permission,
		)
	);

	register_rest_route(
		$namespace,
		'/pair/connect',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_connect',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'pairing_key' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/pair/status',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_pair_status',
			'permission_callback' => $admin_permission,
		)
	);

	register_rest_route(
		$namespace,
		'/pair/revoke',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_revoke',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'pair_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/pair/verify',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_verify',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'pair_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/pair/handshake',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_handshake',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$namespace,
		'/pair/ping',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_ping',
			'permission_callback' => $signed_permission,
		)
	);

	register_rest_route(
		$namespace,
		'/pair/revoke-remote',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_revoke_remote',
			'permission_callback' => $signed_permission,
		)
	);

	// Admin relay routes to paired destination (Part 15)
	register_rest_route(
		$namespace,
		'/pair/remote-preflight',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_remote_preflight',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'pair_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/pair/remote-diff',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_remote_diff',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'pair_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/pair/remote-apply',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_pair_remote_apply',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'pair_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'file' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
				'confirm_host' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// --- Direct Transfer Routes (Part 14) ---
	register_rest_route(
		$namespace,
		'/transfer/start',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_transfer_start',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'pair_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'archive' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/transfer/status',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'migrate_lift_rest_transfer_status',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'job_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/transfer/cancel',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_transfer_cancel',
			'permission_callback' => $admin_permission,
			'args'                => array(
				'job_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/transfer/init',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_transfer_init',
			'permission_callback' => $signed_permission,
		)
	);

	register_rest_route(
		$namespace,
		'/transfer/chunk',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_transfer_chunk',
			'permission_callback' => $signed_permission,
		)
	);

	register_rest_route(
		$namespace,
		'/transfer/finalize',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_transfer_finalize',
			'permission_callback' => $signed_permission,
		)
	);

	// --- Remote Preflight, Diff, Apply Routes (Part 15) ---
	register_rest_route(
		$namespace,
		'/remote/preflight',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_remote_preflight',
			'permission_callback' => $signed_permission,
		)
	);

	register_rest_route(
		$namespace,
		'/remote/diff-preview',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_remote_diff_preview',
			'permission_callback' => $signed_permission,
		)
	);

	register_rest_route(
		$namespace,
		'/remote/apply',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'migrate_lift_rest_remote_apply',
			'permission_callback' => $signed_permission,
		)
	);
}
add_action( 'rest_api_init', 'migrate_lift_register_rest_routes' );

/**
 * Returns read-only plugin and environment health information in non-sensitive aggregate form.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_health( $request ) {
	$store_dir = function_exists( 'migrate_lift_dir' ) ? migrate_lift_dir() : '';
	$is_writable = ! empty( $store_dir ) && is_dir( $store_dir ) && wp_is_writable( $store_dir );

	$health_data = array(
		'status'        => 'ok',
		'availability'  => 'ready',
		'pluginVersion' => MIGRATE_LIFT_VERSION,
		'php'           => array(
			'version'            => PHP_VERSION,
			'memory_limit'       => ini_get( 'memory_limit' ),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'upload_max_filesize'=> ini_get( 'upload_max_filesize' ),
			'post_max_size'      => ini_get( 'post_max_size' ),
			'extensions'         => array(
				'zlib'   => extension_loaded( 'zlib' ),
				'zip'    => extension_loaded( 'zip' ),
				'mysqli' => extension_loaded( 'mysqli' ),
				'curl'   => extension_loaded( 'curl' ),
			),
		),
		'environment'   => array(
			'wp_version'       => get_bloginfo( 'version' ),
			'is_multisite'     => is_multisite(),
			'storage_dir'      => defined( 'MIGRATE_LIFT_STORE' ) ? MIGRATE_LIFT_STORE : 'migrate-lift-files',
			'storage_writable' => $is_writable,
			'max_upload_size'  => wp_max_upload_size(),
		),
	);

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $health_data,
		)
	);
}

/**
 * Returns site and system status.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_status( $request ) {
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'site'        => migrate_lift_site_info(),
				'archives'    => migrate_lift_archives(),
				'isMultisite' => is_multisite(),
				'version'     => MIGRATE_LIFT_VERSION,
			),
		)
	);
}

/**
 * Lists available archives.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_archives( $request ) {
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => migrate_lift_archives(),
		)
	);
}

/**
 * Returns comprehensive dashboard summary data for the landing view.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_dashboard_summary( $request ) {
	$store_dir        = function_exists( 'migrate_lift_dir' ) ? migrate_lift_dir() : '';
	$storage_writable = ! empty( $store_dir ) && is_dir( $store_dir ) && wp_is_writable( $store_dir );

	$raw_archives = migrate_lift_archives();
	$archives     = array();
	$total_bytes  = 0;

	if ( is_array( $raw_archives ) ) {
		foreach ( $raw_archives as $arch ) {
			$size        = isset( $arch['size'] ) ? (int) $arch['size'] : 0;
			$total_bytes += $size;
			$archives[]  = $arch;
		}
	}

	// Strictly truthful KPI: Only select as last successful backup if verified is true!
	$last_backup = null;
	foreach ( $archives as $arch ) {
		if ( ! empty( $arch['verified'] ) ) {
			$last_backup = $arch;
			break;
		}
	}

	// Calculate 7-day activity bucketed by day ending today.
	$days          = array();
	$now           = current_time( 'timestamp' );
	$max_day_bytes = 0;
	$has_activity  = false;

	for ( $i = 6; $i >= 0; $i-- ) {
		$day_ts   = strtotime( "-{$i} days", $now );
		$day_date = wp_date( 'Y-m-d', $day_ts );
		$day_name = wp_date( 'D', $day_ts );

		$day_count = 0;
		$day_bytes = 0;

		foreach ( $archives as $arch ) {
			if ( ! empty( $arch['time'] ) && wp_date( 'Y-m-d', $arch['time'] ) === $day_date ) {
				$day_count++;
				$day_bytes += $arch['size'];
			}
		}

		if ( $day_bytes > $max_day_bytes ) {
			$max_day_bytes = $day_bytes;
		}
		if ( $day_count > 0 ) {
			$has_activity = true;
		}

		$days[] = array(
			'day'        => $day_name,
			'date'       => $day_date,
			'count'      => $day_count,
			'totalBytes' => $day_bytes,
			'percentage' => 0,
		);
	}

	foreach ( $days as &$day_data ) {
		if ( $max_day_bytes > 0 && $day_data['totalBytes'] > 0 ) {
			$day_data['percentage'] = max( 15, (int) round( ( $day_data['totalBytes'] / $max_day_bytes ) * 100 ) );
		} else {
			$day_data['percentage'] = 0;
		}
	}
	unset( $day_data );

	// Build recent activity entries: prefer real logged audit events from Migrate_Lift_Activity_Logger.
	$recent_activity = array();
	$logged_events   = class_exists( 'Migrate_Lift_Activity_Logger' ) ? Migrate_Lift_Activity_Logger::get_recent( 5 ) : array();

	if ( ! empty( $logged_events ) ) {
		foreach ( $logged_events as $entry ) {
			$recent_activity[] = array(
				'id'          => $entry['id'] ?? ( 'act_' . wp_generate_password( 8, false ) ),
				'timestamp'   => $entry['date'] ?? '',
				'time'        => $entry['timestamp'] ?? 0,
				'event'       => ucwords( str_replace( '_', ' ', $entry['action'] ?? 'activity' ) ),
				'description' => $entry['message'] ?? '',
				'level'       => in_array( $entry['level'] ?? '', array( 'info', 'warning', 'error', 'success' ), true ) ? $entry['level'] : 'info',
				'jobLink'     => ! empty( $entry['job_id'] ) ? '#activity?job=' . rawurlencode( $entry['job_id'] ) : null,
			);
		}
	} elseif ( ! empty( $archives ) ) {
		// Fallback to archives if activity log is empty.
		foreach ( array_slice( $archives, 0, 5 ) as $arch ) {
			$recent_activity[] = array(
				'id'          => 'act-' . $arch['time'] . '-' . substr( md5( $arch['name'] ), 0, 6 ),
				'timestamp'   => $arch['date'],
				'time'        => $arch['time'],
				/* translators: %s: Archive name. */
				'event'       => sprintf( __( 'Backup snapshot stored: %s', 'migrate-lift' ), $arch['name'] ),
				/* translators: %s: Formatted file size. */
				'description' => sprintf( __( 'Full site archive package stored. Size: %s', 'migrate-lift' ), size_format( $arch['size'] ) ),
				'level'       => 'success',
				'jobLink'     => null,
			);
		}
	}

	// Check if any job is currently active or recently running.
	$migration_job = null;
	if ( class_exists( 'Migrate_Lift_Job_Engine' ) ) {
		$active_jobs = Migrate_Lift_Job_Engine::list_jobs( array( 'state' => 'running', 'limit' => 1 ) );
		if ( empty( $active_jobs['jobs'] ) ) {
			$active_jobs = Migrate_Lift_Job_Engine::list_jobs( array( 'state' => 'queued', 'limit' => 1 ) );
		}
		if ( ! empty( $active_jobs['jobs'][0] ) ) {
			$j = $active_jobs['jobs'][0];
			$migration_job = array(
				'id'             => $j['id'],
				'status'         => ucfirst( $j['state'] ),
				'progress'       => $j['percentage'],
				'currentStep'    => ! empty( $j['diagnostic_detail'] ) ? $j['diagnostic_detail'] : $j['phase'],
				'startedAt'      => ! empty( $j['started_at'] ) ? wp_date( 'M d, Y H:i', $j['started_at'] ) : '',
				'destinationUrl' => $j['params']['destination_url'] ?? '',
			);
		}
	}

	$status_type  = $storage_writable ? 'ready' : 'action_needed';
	$status_label = $storage_writable ? __( 'Site ready', 'migrate-lift' ) : __( 'Storage unwritable', 'migrate-lift' );

	// Read real schedule settings and next run from Scheduler engine.
	$sched_settings = class_exists( 'Migrate_Lift_Scheduler' ) ? Migrate_Lift_Scheduler::get_settings() : array();
	$sched_active   = ! empty( $sched_settings['enabled'] ) && 'manual' !== ( $sched_settings['cadence'] ?? '' );
	$next_run_ts    = class_exists( 'Migrate_Lift_Scheduler' ) ? Migrate_Lift_Scheduler::get_next_run_timestamp() : null;
	$next_scheduled = null;

	if ( $sched_active && $next_run_ts ) {
		$next_scheduled = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run_ts );
	}

	$summary = array(
		'site'               => array(
			'url'         => untrailingslashit( home_url() ),
			'name'        => get_bloginfo( 'name' ),
			'wpVersion'   => get_bloginfo( 'version' ),
			'timezone'    => wp_timezone_string(),
			'isMultisite' => is_multisite(),
		),
		'systemStatus'       => array(
			'storageWritable'          => $storage_writable,
			'atomicTableSwapSupported' => true,
			'schedulerActive'          => $sched_active,
			'statusLabel'              => $status_label,
			'statusType'               => $status_type,
			'phpVersion'               => PHP_VERSION,
		),
		'kpis'               => array(
			'lastBackup'         => $last_backup,
			'nextScheduled'      => $next_scheduled,
			'storedBackupsCount' => count( $archives ),
			'totalStorageBytes'  => $total_bytes,
		),
		'backupHealth'       => array(
			'range'       => '7d',
			'days'        => $days,
			'hasActivity' => $has_activity,
		),
		'recentBackups'      => array_slice( $archives, 0, 5 ),
		'migrationJob'       => $migration_job,
		'protectionSettings' => array(
			'scheduleEnabled'      => $sched_active,
			'scheduleFrequency'    => $sched_active && class_exists( 'Migrate_Lift_Scheduler' ) ? Migrate_Lift_Scheduler::get_frequency_label( $sched_settings ) : null,
			'retentionCopies'      => $sched_settings['retention_copies'] ?? 7,
			'compressionMode'      => 'Standard gzip (.tgz)',
			'exclusionsConfigured' => true,
			'lastIntegrityCheck'   => ! empty( $archives ) ? $archives[0]['date'] : null,
		),
		'recentActivity'     => $recent_activity,
	);

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $summary,
		)
	);
}

/**
 * Returns full details and manifest component inventory for a single archive.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_get_archive_details( $request ) {
	$file = sanitize_file_name( (string) $request->get_param( 'file' ) );
	try {
		$details = migrate_lift_get_archive_details( $file );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $details,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'archive_not_found', $e->getMessage(), array( 'status' => 404 ) );
	}
}

/**
 * Deletes an archive with safe dependency and recovery checkpoint protection.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_delete_archive( $request ) {
	$file = sanitize_file_name( (string) $request->get_param( 'file' ) );

	try {
		$path = migrate_lift_archive_path( $file );
	} catch ( Exception $e ) {
		return new WP_Error( 'archive_not_found', $e->getMessage(), array( 'status' => 404 ) );
	}

	// 1. Safety check: Check if an active job is currently referencing or using this archive.
	if ( class_exists( 'Migrate_Lift_Job_Engine' ) ) {
		$active_jobs = Migrate_Lift_Job_Engine::list_jobs( array( 'limit' => 20 ) );
		foreach ( $active_jobs['jobs'] ?? array() as $job ) {
			if ( in_array( $job['state'], array( 'queued', 'running', 'paused' ), true ) ) {
				$params     = $job['params'] ?? array();
				$checkpoint = $job['checkpoint'] ?? array();

				$is_used = ( isset( $params['archive'] ) && $params['archive'] === $file )
					|| ( isset( $params['file'] ) && $params['file'] === $file )
					|| ( isset( $checkpoint['archive_name'] ) && $checkpoint['archive_name'] === $file )
					|| ( isset( $checkpoint['recovery_checkpoint'] ) && $checkpoint['recovery_checkpoint'] === $file );

				if ( $is_used ) {
					return new WP_Error(
						'archive_in_use',
						/* translators: 1: Archive filename, 2: Job ID, 3: Job kind. */
						sprintf( __( 'Cannot delete "%1$s" because an active background job (%2$s: %3$s) is currently using it.', 'migrate-lift' ), $file, $job['id'], $job['kind'] ),
						array( 'status' => 409 )
					);
				}
			}
		}
	}

	// 2. Safety check: Check if this archive is the designated recovery point for a current or pending restore.
	$recovery_point = get_option( 'migrate_lift_recovery_point' );
	if ( ! empty( $recovery_point ) && $recovery_point === $file ) {
		return new WP_Error(
			'archive_is_recovery_point',
			/* translators: %s: Archive filename. */
			sprintf( __( 'Cannot delete "%s" because it is the registered recovery checkpoint snapshot for the system.', 'migrate-lift' ), $file ),
			array( 'status' => 409 )
		);
	}

	// 3. Safety check: Check if this backup is a base for an active incremental backup chain.
	$chain_base = get_option( 'migrate_lift_incremental_chain_base' );
	if ( ! empty( $chain_base ) && $chain_base === $file ) {
		return new WP_Error(
			'archive_is_incremental_base',
			/* translators: %s: Archive filename. */
			sprintf( __( 'Cannot delete "%s" because it serves as the base backup for an active incremental backup chain.', 'migrate-lift' ), $file ),
			array( 'status' => 409 )
		);
	}

	// Safe to delete.
	if ( ! wp_delete_file( $path ) ) {
		return new WP_Error( 'delete_failed', __( 'Could not delete archive from filesystem. Check server write permissions.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	// Clean up metadata.
	$meta = get_option( 'migrate_lift_backup_metadata', array() );
	if ( is_array( $meta ) && isset( $meta[ $file ] ) ) {
		unset( $meta[ $file ] );
		update_option( 'migrate_lift_backup_metadata', $meta, false );
	}

	if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
		Migrate_Lift_Activity_Logger::warning(
			'backup_deleted',
			/* translators: %s: Archive filename. */
			sprintf( __( 'Archive deleted by administrator: %s', 'migrate-lift' ), $file ),
			array( 'file' => $file )
		);
	}

	return rest_ensure_response(
		array(
			'success' => true,
			/* translators: %s: Archive filename. */
			'message' => sprintf( __( 'Archive "%s" deleted successfully.', 'migrate-lift' ), $file ),
			'data'    => migrate_lift_archives(),
		)
	);
}

/**
 * Handles export step via REST.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_export( $request ) {
	$step = sanitize_text_field( (string) $request->get_param( 'step' ) );
	try {
		$result = migrate_lift_export_step( $step );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'export_failed', $e->getMessage(), array( 'status' => 500 ) );
	}
}

/**
 * Handles import step via REST.
 * Gated in Part 10: destructive replacement is locked until preflight passes,
 * recovery checkpoint exists, and replacement engines are implemented in Parts 11 & 12.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_import( $request ) {
	return new WP_Error(
		'destructive_restore_locked',
		__( 'Destructive site replacement is disabled in this phase. Use the Restore Wizard to perform preflight checks and create a verified recovery checkpoint snapshot before enabling replacement modules (Parts 11 & 12).', 'migrate-lift' ),
		array( 'status' => 403 )
	);
}

/**
 * Handles cleanup step via REST.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_cleanup( $request ) {
	$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
	try {
		$result = migrate_lift_cleanup_step( $token );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'cleanup_failed', $e->getMessage(), array( 'status' => 403 ) );
	}
}

/**
 * Handles clean temporary files via REST.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_clean( $request ) {
	try {
		$result = migrate_lift_clean_step();
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'clean_failed', $e->getMessage(), array( 'status' => 500 ) );
	}
}

/**
 * Returns a list of jobs with optional status/kind filter and pagination.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_get_jobs( $request ) {
	$state  = sanitize_key( (string) $request->get_param( 'state' ) );
	$kind   = sanitize_key( (string) $request->get_param( 'kind' ) );
	$limit  = $request->get_param( 'limit' ) ? absint( $request->get_param( 'limit' ) ) : 50;
	$offset = $request->get_param( 'offset' ) ? absint( $request->get_param( 'offset' ) ) : 0;

	$result = Migrate_Lift_Job_Engine::list_jobs(
		array(
			'state'  => $state,
			'kind'   => $kind,
			'limit'  => $limit,
			'offset' => $offset,
		)
	);

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $result,
		)
	);
}

/**
 * Creates and queues a new job.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_create_job( $request ) {
	$kind            = sanitize_key( (string) $request->get_param( 'kind' ) );
	$params          = $request->get_param( 'params' );
	$idempotency_key = sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) );

	if ( ! is_array( $params ) ) {
		$params = array();
	}

	// Supported job kinds in this version.
	$allowed_kinds = array( 'test_job', 'backup' );
	if ( ! in_array( $kind, $allowed_kinds, true ) ) {
		return new WP_Error(
			'job_kind_not_allowed',
			/* translators: %s: Job kind. */
			sprintf( __( 'Job kind "%s" is not enabled in this version. Test jobs and backup jobs are supported.', 'migrate-lift' ), $kind ),
			array( 'status' => 400 )
		);
	}

	// Disable duplicate/repeat requests while a matching active job exists.
	if ( 'backup' === $kind && class_exists( 'Migrate_Lift_Job_Engine' ) ) {
		$active = Migrate_Lift_Job_Engine::list_jobs( array( 'kind' => 'backup', 'limit' => 10 ) );
		foreach ( $active['jobs'] ?? array() as $j ) {
			if ( in_array( $j['state'], array( 'queued', 'running', 'paused' ), true ) ) {
				return new WP_Error(
					'backup_job_already_active',
					/* translators: 1: Job ID, 2: Percentage completed. */
					sprintf( __( 'A backup job (%1$s) is already in progress (%2$d%%). Repeat requests are disabled while one matching job exists.', 'migrate-lift' ), $j['id'], $j['percentage'] ),
					array(
						'status' => 409,
						'job'    => $j,
					)
				);
			}
		}
	}

	$job = Migrate_Lift_Job_Engine::create_job( $kind, $params, $idempotency_key );
	if ( is_wp_error( $job ) ) {
		return $job;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $job,
		)
	);
}

/**
 * Returns a specific job record and its associated activity log entries.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_get_job( $request ) {
	$job_id = sanitize_key( (string) $request->get_param( 'id' ) );
	$job    = Migrate_Lift_Job_Engine::get_job( $job_id );

	if ( ! $job ) {
		return new WP_Error( 'job_not_found', __( 'Job not found.', 'migrate-lift' ), array( 'status' => 404 ) );
	}

	$activity = Migrate_Lift_Activity_Logger::get_events(
		array(
			'job_id' => $job_id,
			'limit'  => 50,
		)
	);

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'job'      => $job,
				'activity' => $activity['items'],
			),
		)
	);
}

/**
 * Steps an active job forward by one bounded batch.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_step_job( $request ) {
	$job_id    = sanitize_key( (string) $request->get_param( 'id' ) );
	$worker_id = sanitize_text_field( (string) $request->get_param( 'worker_id' ) );

	$result = Migrate_Lift_Job_Engine::step_job( $job_id, $worker_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $result,
		)
	);
}

/**
 * Pauses an active or queued job.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pause_job( $request ) {
	$job_id = sanitize_key( (string) $request->get_param( 'id' ) );
	$result = Migrate_Lift_Job_Engine::pause_job( $job_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $result,
		)
	);
}

/**
 * Resumes a paused job.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_resume_job( $request ) {
	$job_id = sanitize_key( (string) $request->get_param( 'id' ) );
	$result = Migrate_Lift_Job_Engine::resume_job( $job_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $result,
		)
	);
}

/**
 * Cancels a job.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_cancel_job( $request ) {
	$job_id = sanitize_key( (string) $request->get_param( 'id' ) );
	$result = Migrate_Lift_Job_Engine::cancel_job( $job_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $result,
		)
	);
}

/**
 * Returns filtered activity log events.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_get_activity( $request ) {
	$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
	$level  = sanitize_key( (string) $request->get_param( 'level' ) );
	$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
	$limit  = $request->get_param( 'limit' ) ? absint( $request->get_param( 'limit' ) ) : 50;
	$offset = $request->get_param( 'offset' ) ? absint( $request->get_param( 'offset' ) ) : 0;

	$result = Migrate_Lift_Activity_Logger::get_events(
		array(
			'search' => $search,
			'level'  => $level,
			'job_id' => $job_id,
			'limit'  => $limit,
			'offset' => $offset,
		)
	);

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $result,
		)
	);
}

/**
 * Clears activity logs.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_clear_activity( $request ) {
	$cleared = Migrate_Lift_Activity_Logger::clear();
	return rest_ensure_response(
		array(
			'success' => $cleared,
			'message' => __( 'Activity log cleared.', 'migrate-lift' ),
		)
	);
}

/**
 * Starts a full site backup job.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_start_backup( $request ) {
	$params = $request->get_param( 'params' );
	$params = is_array( $params ) ? $params : array();
	$idemp  = sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) );

	// Disable repeat requests while one matching job exists.
	if ( class_exists( 'Migrate_Lift_Job_Engine' ) ) {
		$active = Migrate_Lift_Job_Engine::list_jobs( array( 'kind' => 'backup', 'limit' => 10 ) );
		foreach ( $active['jobs'] ?? array() as $j ) {
			if ( in_array( $j['state'], array( 'queued', 'running', 'paused' ), true ) ) {
				return new WP_Error(
					'backup_job_already_active',
					/* translators: 1: Job ID, 2: Percentage completed. */
					sprintf( __( 'A backup job (%1$s) is already in progress (%2$d%%). Repeat requests are disabled while one matching job exists.', 'migrate-lift' ), $j['id'], $j['percentage'] ),
					array(
						'status' => 409,
						'job'    => $j,
					)
				);
			}
		}
	}

	$job = Migrate_Lift_Job_Engine::create_job( 'backup', $params, $idemp );
	if ( is_wp_error( $job ) ) {
		return $job;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $job,
		)
	);
}

/**
 * Verifies an archive file's integrity and manifest.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_verify_backup( $request ) {
	$file = sanitize_file_name( (string) $request->get_param( 'file' ) );

	try {
		$path = migrate_lift_archive_path( $file );
	} catch ( Exception $e ) {
		return new WP_Error( 'archive_not_found', $e->getMessage(), array( 'status' => 404 ) );
	}

	if ( ! class_exists( 'Migrate_Lift_Backup_Engine' ) ) {
		return new WP_Error( 'engine_missing', 'Backup engine is not available.', array( 'status' => 500 ) );
	}

	$report = Migrate_Lift_Backup_Engine::verify_archive_file( $path );

	// Update metadata option
	$meta = get_option( 'migrate_lift_backup_metadata', array() );
	if ( ! is_array( $meta ) ) {
		$meta = array();
	}
	$item_meta = isset( $meta[ $file ] ) && is_array( $meta[ $file ] ) ? $meta[ $file ] : array();

	$item_meta['name']        = $file;
	$item_meta['time']        = filemtime( $path );
	$item_meta['size']        = filesize( $path );
	$item_meta['verified']    = ! empty( $report['valid'] );
	$item_meta['verified_at'] = time();
	$item_meta['status']      = ! empty( $report['valid'] ) ? 'ready' : 'damaged';
	$item_meta['errors']      = $report['errors'] ?? array();

	if ( ! empty( $report['manifest'] ) ) {
		$item_meta['manifest'] = $report['manifest'];
		if ( ! empty( $report['manifest']['database_sections']['sql_sha256'] ) ) {
			$item_meta['checksum'] = $report['manifest']['database_sections']['sql_sha256'];
		}
		if ( ! empty( $report['manifest']['bytes'] ) ) {
			$item_meta['raw_estimate'] = (int) $report['manifest']['bytes'];
		}
		if ( ! empty( $report['manifest']['compression']['reduction_percentage'] ) ) {
			$item_meta['reduction_percentage'] = (float) $report['manifest']['compression']['reduction_percentage'];
		}
	}

	$meta[ $file ] = $item_meta;
	update_option( 'migrate_lift_backup_metadata', $meta, false );

	if ( class_exists( 'Migrate_Lift_Activity_Logger' ) ) {
		if ( $item_meta['verified'] ) {
			Migrate_Lift_Activity_Logger::info(
				'backup_verified',
				/* translators: %s: Archive filename. */
				sprintf( __( 'Archive %s checksum and manifest verified successfully.', 'migrate-lift' ), $file ),
				array( 'file' => $file, 'format' => $report['format'] )
			);
		} else {
			Migrate_Lift_Activity_Logger::warning(
				'backup_verification_failed',
				/* translators: 1: Archive filename, 2: Semicolon-separated error list. */
				sprintf( __( 'Archive %1$s failed verification: %2$s', 'migrate-lift' ), $file, implode( '; ', $report['errors'] ) ),
				array( 'file' => $file, 'errors' => $report['errors'] )
			);
		}
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'report'   => $report,
				'archive'  => $item_meta,
				'archives' => migrate_lift_archives(),
			),
		)
	);
}

/**
 * Returns current compression settings, available modes, and host capabilities.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_get_compression_settings( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Compression' ) ) {
		return new WP_Error( 'compression_missing', __( 'Compression engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'settings'     => Migrate_Lift_Compression::get_settings(),
				'capabilities' => Migrate_Lift_Compression::get_capabilities(),
				'defaults'     => Migrate_Lift_Compression::get_defaults(),
			),
		)
	);
}

/**
 * Validates and updates compression and exclusion settings.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_save_compression_settings( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Compression' ) ) {
		return new WP_Error( 'compression_missing', __( 'Compression engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		$payload = $request->get_params();
	}

	$result = Migrate_Lift_Compression::save_settings( $payload );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	Migrate_Lift_Activity_Logger::info(
		'compression_settings_updated',
		sprintf( 'Compression settings updated: mode=%s, format=%s', $result['mode'], $result['format'] ),
		$result
	);

	return rest_ensure_response(
		array(
			'success' => true,
			'message' => __( 'Compression and exclusion settings saved successfully.', 'migrate-lift' ),
			'data'    => array(
				'settings'     => $result,
				'capabilities' => Migrate_Lift_Compression::get_capabilities(),
			),
		)
	);
}

/**
 * Returns automated backup schedule configuration, status, and system cron instructions.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_get_schedule_settings( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Scheduler' ) ) {
		return new WP_Error( 'scheduler_missing', __( 'Scheduler engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$settings    = Migrate_Lift_Scheduler::get_settings();
	$next_run_ts = Migrate_Lift_Scheduler::get_next_run_timestamp();
	$last_run    = get_option( Migrate_Lift_Scheduler::OPTION_LAST_RUN, null );
	$store_dir   = function_exists( 'migrate_lift_dir' ) ? migrate_lift_dir() : '';
	$free_space  = function_exists( 'disk_free_space' ) && ! empty( $store_dir ) ? @disk_free_space( $store_dir ) : null;
	$total_space = function_exists( 'disk_total_space' ) && ! empty( $store_dir ) ? @disk_total_space( $store_dir ) : null;

	$response = array(
		'settings'           => $settings,
		'next_run_timestamp' => $next_run_ts,
		'next_run_formatted' => $next_run_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run_ts ) : null,
		'frequency_label'    => Migrate_Lift_Scheduler::get_frequency_label( $settings ),
		'last_run'           => $last_run ? array_merge( $last_run, array(
			'date' => ! empty( $last_run['timestamp'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run['timestamp'] ) : '',
		) ) : null,
		'site_timezone'      => array(
			'string'       => wp_timezone_string(),
			'current_time' => wp_date( 'H:i' ),
			'current_date' => wp_date( get_option( 'date_format' ) ),
		),
		'system_cron'        => Migrate_Lift_Scheduler::get_system_cron_info(),
		'server_storage'     => array(
			'disk_free_bytes'  => false !== $free_space ? (int) $free_space : null,
			'disk_total_bytes' => false !== $total_space ? (int) $total_space : null,
			'writable'         => ! empty( $store_dir ) && wp_is_writable( $store_dir ),
		),
	);

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $response,
		)
	);
}

/**
 * Validates and updates backup schedule settings.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_save_schedule_settings( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Scheduler' ) ) {
		return new WP_Error( 'scheduler_missing', __( 'Scheduler engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		$payload = $request->get_params();
	}

	$result = Migrate_Lift_Scheduler::save_settings( $payload );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$next_run_ts = Migrate_Lift_Scheduler::get_next_run_timestamp();

	return rest_ensure_response(
		array(
			'success' => true,
			'message' => __( 'Backup schedule configuration saved successfully.', 'migrate-lift' ),
			'data'    => array(
				'settings'           => $result,
				'next_run_timestamp' => $next_run_ts,
				'next_run_formatted' => $next_run_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run_ts ) : null,
				'frequency_label'    => Migrate_Lift_Scheduler::get_frequency_label( $result ),
			),
		)
	);
}

/**
 * Immediately triggers a scheduled backup run.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_run_schedule_now( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Scheduler' ) ) {
		return new WP_Error( 'scheduler_missing', __( 'Scheduler engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$result = Migrate_Lift_Scheduler::execute_scheduled_backup( true );
	if ( false === $result ) {
		return new WP_Error( 'schedule_run_failed', __( 'Could not execute scheduled backup. Check activity logs for details.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'message' => __( 'Scheduled backup completed.', 'migrate-lift' ),
			'data'    => $result,
		)
	);
}

/**
 * Returns staging directory path, document root guidance, web exposure status,
 * and list of discovered eligible archives in the staging directory.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_get_import_staging( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Import_Engine' ) ) {
		return new WP_Error( 'import_engine_missing', __( 'Import engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$staging_info = Migrate_Lift_Import_Engine::get_staging_info();
	$archives     = Migrate_Lift_Import_Engine::scan_staged_archives();

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'staging'  => $staging_info,
				'archives' => $archives,
			),
		)
	);
}

/**
 * Rescans the dedicated staging directory for archives dropped via SFTP / cPanel.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_rescan_import_staging( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Import_Engine' ) ) {
		return new WP_Error( 'import_engine_missing', __( 'Import engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$archives = Migrate_Lift_Import_Engine::scan_staged_archives();

	return rest_ensure_response(
		array(
			'success' => true,
			/* translators: %d: Number of archives discovered. */
			'message' => sprintf( _n( 'Found %d archive in staging directory.', 'Found %d archives in staging directory.', count( $archives ), 'migrate-lift' ), count( $archives ) ),
			'data'    => array(
				'archives' => $archives,
			),
		)
	);
}

/**
 * Returns upload resumption status for an archive file in chunked upload.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_upload_status( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Import_Engine' ) ) {
		return new WP_Error( 'import_engine_missing', __( 'Import engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$filename = (string) $request->get_param( 'filename' );
	try {
		$status = Migrate_Lift_Import_Engine::get_upload_status( $filename );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $status,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'invalid_filename', $e->getMessage(), array( 'status' => 400 ) );
	}
}

/**
 * Receives and processes one uploaded slice/chunk for browser chunked upload.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_upload_chunk( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Import_Engine' ) ) {
		return new WP_Error( 'import_engine_missing', __( 'Import engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$filename = (string) ( $request->get_param( 'filename' ) ?: $request->get_param( 'name' ) );
	$offset   = (int) $request->get_param( 'offset' );
	$total    = (int) $request->get_param( 'total' );
	$checksum = (string) ( $request->get_param( 'checksum' ) ?: $request->get_param( 'chunk_md5' ) );

	$files = $request->get_file_params();
	$chunk = $files['chunk'] ?? null;

	if ( ! $chunk || empty( $chunk['tmp_name'] ) || UPLOAD_ERR_OK !== $chunk['error'] ) {
		return new WP_Error( 'upload_chunk_error', __( 'Chunk data missing or upload failed in PHP.', 'migrate-lift' ), array( 'status' => 400 ) );
	}

	try {
		$result = Migrate_Lift_Import_Engine::handle_upload_chunk(
			$filename,
			$offset,
			$total,
			$chunk['tmp_name'],
			(int) $chunk['size'],
			$checksum
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'chunk_upload_failed', $e->getMessage(), array( 'status' => 400 ) );
	}
}

/**
 * Cancels an in-progress chunked browser upload and purges staging chunks.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_upload_cancel( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Import_Engine' ) ) {
		return new WP_Error( 'import_engine_missing', __( 'Import engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$filename = (string) ( $request->get_param( 'filename' ) ?: $request->get_param( 'name' ) );
	$cancelled = Migrate_Lift_Import_Engine::cancel_upload( $filename );

	return rest_ensure_response(
		array(
			'success' => $cancelled,
			'message' => __( 'Upload cancelled and partial chunks removed.', 'migrate-lift' ),
		)
	);
}

/**
 * Validates and imports an archive from the dedicated staging directory into the backup library.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_verify_staged_import( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Import_Engine' ) ) {
		return new WP_Error( 'import_engine_missing', __( 'Import engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$filename = (string) ( $request->get_param( 'filename' ) ?: $request->get_param( 'file' ) );

	try {
		$result = Migrate_Lift_Import_Engine::import_staged_archive( $filename );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'staged_import_failed', $e->getMessage(), array( 'status' => 400 ) );
	}
}

/**
 * Forces a refresh of the web server direct exposure check for storage directory.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_check_exposure( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Import_Engine' ) ) {
		return new WP_Error( 'import_engine_missing', __( 'Import engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$exposure = Migrate_Lift_Import_Engine::check_web_exposure( true );

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $exposure,
		)
	);
}

/**
 * Runs preflight inspection on an archive against the destination WordPress environment.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_restore_preflight( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Restore_Preflight' ) ) {
		return new WP_Error( 'preflight_engine_missing', __( 'Restore Preflight engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$file = (string) $request->get_param( 'file' );

	try {
		$report = Migrate_Lift_Restore_Preflight::run_preflight( $file );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $report,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'preflight_failed', $e->getMessage(), array( 'status' => 400 ) );
	}
}

/**
 * Generates an accurate dry-run report of planned file and database modifications.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_restore_dry_run( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Restore_Preflight' ) ) {
		return new WP_Error( 'preflight_engine_missing', __( 'Restore Preflight engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$file = (string) $request->get_param( 'file' );

	try {
		$report = Migrate_Lift_Restore_Preflight::generate_dry_run_report( $file );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $report,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'dry_run_failed', $e->getMessage(), array( 'status' => 400 ) );
	}
}

/**
 * Creates and verifies a destination safety recovery checkpoint snapshot.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_restore_create_checkpoint( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Restore_Preflight' ) ) {
		return new WP_Error( 'preflight_engine_missing', __( 'Restore Preflight engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	try {
		$checkpoint = Migrate_Lift_Restore_Preflight::create_recovery_checkpoint();
		return rest_ensure_response(
			array(
				'success' => true,
				/* translators: %s: Checkpoint filename. */
				'message' => sprintf( __( 'Safety recovery checkpoint "%s" created and verified.', 'migrate-lift' ), $checkpoint['file'] ),
				'data'    => $checkpoint,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'checkpoint_creation_failed', $e->getMessage(), array( 'status' => 400 ) );
	}
}

/**
 * Returns current registered recovery checkpoint details and recent recovery audit logs.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_restore_get_recovery_status( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Restore_Preflight' ) ) {
		return new WP_Error( 'preflight_engine_missing', __( 'Restore Preflight engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$status = Migrate_Lift_Restore_Preflight::get_recovery_status();
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $status,
		)
	);
}

/**
 * Generates an accurate diff preview between archive and destination files/tables.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_restore_diff_preview( $request ) {
	$file      = (string) $request->get_param( 'file' );
	$file_mode = (string) ( $request->get_param( 'file_mode' ) ?: 'overwrite_matching' );
	$db_mode   = (string) ( $request->get_param( 'db_mode' ) ?: 'replace_mapped' );
	$groups    = (array) ( $request->get_param( 'selected_tables' ) ?: array() );

	try {
		$file_diff = class_exists( 'Migrate_Lift_File_Replacement_Engine' )
			? Migrate_Lift_File_Replacement_Engine::generate_diff_preview( $file, $file_mode )
			: array( 'counts' => array( 'create' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0 ) );

		$db_diff = class_exists( 'Migrate_Lift_Database_Replacement_Engine' )
			? Migrate_Lift_Database_Replacement_Engine::generate_diff_preview( $file, $db_mode, $groups )
			: array( 'tables_to_replace' => array(), 'tables_untouched' => array() );

		return rest_ensure_response(
			array(
				'success'   => true,
				'file_diff' => $file_diff,
				'db_diff'   => $db_diff,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'diff_preview_error', $e->getMessage(), array( 'status' => 400 ) );
	}
}

/**
 * Validates preflight, confirmation, and recovery checkpoint before restore.
 * In Part 10, destructive apply is safely locked until replacement engines in Parts 11 & 12.
 * When action is 'apply', executes safe staged file replacement and atomic database swap.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_restore_execute( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Restore_Preflight' ) ) {
		return new WP_Error( 'preflight_engine_missing', __( 'Restore Preflight engine is unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$file            = (string) $request->get_param( 'file' );
	$confirm_host    = (string) $request->get_param( 'confirm_host' );
	$action          = (string) ( $request->get_param( 'action' ) ?: 'preview' );
	$file_mode       = (string) ( $request->get_param( 'file_mode' ) ?: 'overwrite_matching' );
	$db_mode         = (string) ( $request->get_param( 'db_mode' ) ?: 'replace_mapped' );
	$selected_tables = (array) ( $request->get_param( 'selected_tables' ) ?: array() );
	$preserve_admin  = (bool) $request->get_param( 'preserve_admin_user' );
	$confirm_delete  = (bool) $request->get_param( 'confirm_delete' );

	// 1. Validate site hostname confirmation.
	try {
		Migrate_Lift_Restore_Preflight::validate_site_confirmation( $confirm_host );
	} catch ( Exception $e ) {
		return new WP_Error( 'confirmation_mismatch', $e->getMessage(), array( 'status' => 400 ) );
	}

	// 2. Validate preflight passes with zero blockers.
	try {
		$preflight = Migrate_Lift_Restore_Preflight::run_preflight( $file );
		if ( ! $preflight['passed'] ) {
			return new WP_Error(
				'preflight_blockers_exist',
				/* translators: %s: Semicolon-separated list of blockers. */
				sprintf( __( 'Restore blocked by preflight checks: %s', 'migrate-lift' ), implode( '; ', $preflight['blockers'] ) ),
				array( 'status' => 409, 'blockers' => $preflight['blockers'] )
			);
		}
	} catch ( Exception $e ) {
		return new WP_Error( 'preflight_error', $e->getMessage(), array( 'status' => 400 ) );
	}

	// 3. Validate that a verified recovery checkpoint exists.
	$recovery = Migrate_Lift_Restore_Preflight::get_recovery_status();
	if ( empty( $recovery['has_checkpoint'] ) ) {
		return new WP_Error(
			'recovery_checkpoint_missing',
			__( 'A verified recovery checkpoint must be created before restoration can be executed.', 'migrate-lift' ),
			array( 'status' => 409 )
		);
	}

	// 4. Default preview mode (or locked gate in Part 10 verification)
	if ( 'apply' !== $action ) {
		return rest_ensure_response(
			array(
				'success'    => true,
				'status'     => 'preflight_verified',
				'locked'     => true,
				'message'    => __( 'Preflight checks passed and safety recovery checkpoint is verified. Destructive file and database replacement will be executed when replacement modules (Parts 11 & 12) are activated.', 'migrate-lift' ),
				'data'       => array(
					'archive'    => $file,
					'checkpoint' => $recovery['checkpoint'],
					'preflight'  => $preflight,
				),
			)
		);
	}

	// 5. Action is 'apply': Execute File Replacement (Part 11) & Database Replacement (Part 12)
	try {
		$file_results = null;
		if ( class_exists( 'Migrate_Lift_File_Replacement_Engine' ) ) {
			$file_results = Migrate_Lift_File_Replacement_Engine::apply_file_replacement(
				$file,
				$file_mode,
				array( 'confirm_delete' => $confirm_delete )
			);
		}

		$db_results = null;
		if ( class_exists( 'Migrate_Lift_Database_Replacement_Engine' ) ) {
			$db_results = Migrate_Lift_Database_Replacement_Engine::apply_database_replacement(
				$file,
				$db_mode,
				array(
					'selected_groups'     => $selected_tables,
					'preserve_admin_user' => $preserve_admin,
				)
			);
		}

		return rest_ensure_response(
			array(
				'success'        => true,
				'status'         => 'completed',
				'locked'         => false,
				'message'        => __( 'Restoration successfully executed. Site files and database tables have been safely replaced.', 'migrate-lift' ),
				'file_results'   => $file_results,
				'db_results'     => $db_results,
				'recovery_point' => $recovery['checkpoint'],
				'session_info'   => array(
					'admin_preserved'  => ! empty( $db_results['admin_preserved'] ),
					'relogin_required' => empty( $db_results['admin_preserved'] ) && ( ! $db_results || 'keep_destination' !== $db_mode ),
				),
			)
		);
	} catch ( Throwable $e ) {
		return new WP_Error(
			'restore_execution_failed',
			/* translators: %s: Error message details. */
			sprintf( __( 'Restoration failed and was safely rolled back: %s', 'migrate-lift' ), $e->getMessage() ),
			array( 'status' => 500, 'error' => $e->getMessage() )
		);
	}
}

/**
 * Generates a short-lived single-use destination pairing token (Part 13).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pair_generate_token( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
		return new WP_Error( 'pairing_missing', __( 'Site pairing module is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}
	return rest_ensure_response( Migrate_Lift_Site_Pairing::generate_pairing_token() );
}

/**
 * Initiates handshake connection from source to destination (Part 13).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pair_connect( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
		return new WP_Error( 'pairing_missing', __( 'Site pairing module is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$pairing_key = (string) $request->get_param( 'pairing_key' );
	$result      = Migrate_Lift_Site_Pairing::initiate_source_connection( $pairing_key );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Returns list of paired sites with credentials masked (Part 13).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_pair_status( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
		return rest_ensure_response( array( 'paired_sites' => array() ) );
	}
	return rest_ensure_response(
		array(
			'success'      => true,
			'paired_sites' => Migrate_Lift_Site_Pairing::get_paired_sites_summary(),
		)
	);
}

/**
 * Revokes a paired site connection (Part 13).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pair_revoke( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
		return new WP_Error( 'pairing_missing', __( 'Site pairing module is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$pair_id = sanitize_key( (string) $request->get_param( 'pair_id' ) );
	$ok      = Migrate_Lift_Site_Pairing::revoke_pairing( $pair_id );

	return rest_ensure_response( array( 'success' => $ok, 'pair_id' => $pair_id ) );
}

/**
 * Verifies round-trip cross-site connectivity with paired site (Part 13).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pair_verify( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
		return new WP_Error( 'pairing_missing', __( 'Site pairing module is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$pair_id = sanitize_key( (string) $request->get_param( 'pair_id' ) );
	$result  = Migrate_Lift_Site_Pairing::verify_round_trip( $pair_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handles incoming handshake on destination site (Part 13).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pair_handshake( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
		return new WP_Error( 'pairing_missing', __( 'Site pairing module is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}
	return Migrate_Lift_Site_Pairing::handle_destination_handshake( $request );
}

/**
 * Responds to cross-site ping for round-trip latency verification (Part 13).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_pair_ping( $request ) {
	return rest_ensure_response(
		array(
			'pong'        => true,
			'server_time' => time(),
			'server_name' => get_bloginfo( 'name' ),
			'wp_version'  => get_bloginfo( 'version' ),
			'version'     => MIGRATE_LIFT_VERSION,
		)
	);
}

/**
 * Handles incoming revocation notification from paired remote site (Part 13).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function migrate_lift_rest_pair_revoke_remote( $request ) {
	$pair_id = sanitize_key( (string) $request->get_header( 'x-ml-pair-id' ) );
	if ( ! empty( $pair_id ) && class_exists( 'Migrate_Lift_Site_Pairing' ) ) {
		$paired = get_option( Migrate_Lift_Site_Pairing::OPTION_PAIRED_SITES, array() );
		if ( isset( $paired[ $pair_id ] ) ) {
			unset( $paired[ $pair_id ] );
			update_option( Migrate_Lift_Site_Pairing::OPTION_PAIRED_SITES, $paired, false );
		}
	}
	return rest_ensure_response( array( 'success' => true ) );
}

/**
 * Starts a direct server-to-server archive transfer background job (Part 14).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_transfer_start( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Direct_Transfer_Engine' ) ) {
		return new WP_Error( 'transfer_missing', __( 'Direct Transfer engine is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$pair_id = sanitize_key( (string) $request->get_param( 'pair_id' ) );
	$archive = sanitize_file_name( (string) $request->get_param( 'archive' ) );

	$job = Migrate_Lift_Direct_Transfer_Engine::start_transfer_job( $pair_id, $archive );
	if ( is_wp_error( $job ) ) {
		return $job;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'job_id'  => $job['id'],
			'status'  => $job['state'],
			'job'     => $job,
		)
	);
}

/**
 * Polls status of a direct transfer job (Part 14).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_transfer_status( $request ) {
	$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
	$job    = class_exists( 'Migrate_Lift_Job_Engine' ) ? Migrate_Lift_Job_Engine::get_job( $job_id ) : null;

	if ( ! $job ) {
		return new WP_Error( 'job_not_found', __( 'Transfer job not found.', 'migrate-lift' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'job'     => $job,
		)
	);
}

/**
 * Cancels an in-flight direct transfer job (Part 14).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_transfer_cancel( $request ) {
	$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
	if ( ! class_exists( 'Migrate_Lift_Job_Engine' ) ) {
		return new WP_Error( 'job_engine_missing', __( 'Job engine unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$res = Migrate_Lift_Job_Engine::request_cancellation( $job_id );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	return rest_ensure_response( array( 'success' => true, 'job_id' => $job_id ) );
}

/**
 * Destination handler: Initializes incoming transfer session (Part 14).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_transfer_init( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Direct_Transfer_Engine' ) ) {
		return new WP_Error( 'transfer_missing', __( 'Direct Transfer engine is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}
	return Migrate_Lift_Direct_Transfer_Engine::handle_destination_init( $request );
}

/**
 * Destination handler: Receives incoming bounded chunk (Part 14).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_transfer_chunk( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Direct_Transfer_Engine' ) ) {
		return new WP_Error( 'transfer_missing', __( 'Direct Transfer engine is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}
	return Migrate_Lift_Direct_Transfer_Engine::handle_destination_chunk( $request );
}

/**
 * Destination handler: Reassembles and verifies complete archive (Part 14).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_transfer_finalize( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Direct_Transfer_Engine' ) ) {
		return new WP_Error( 'transfer_missing', __( 'Direct Transfer engine is not available.', 'migrate-lift' ), array( 'status' => 500 ) );
	}
	return Migrate_Lift_Direct_Transfer_Engine::handle_destination_finalize( $request );
}

/**
 * Cross-site handler: Destination runs preflight checks on received archive (Part 15).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_remote_preflight( $request ) {
	$file = sanitize_file_name( (string) $request->get_param( 'file' ) );
	if ( ! class_exists( 'Migrate_Lift_Restore_Preflight' ) ) {
		return new WP_Error( 'preflight_missing', __( 'Preflight engine unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	try {
		$preflight = Migrate_Lift_Restore_Preflight::run_preflight( $file );
		return rest_ensure_response(
			array(
				'success'   => true,
				'preflight' => $preflight,
			)
		);
	} catch ( Exception $e ) {
		return new WP_Error( 'preflight_failed', $e->getMessage(), array( 'status' => 400 ) );
	}
}

/**
 * Cross-site handler: Destination runs diff preview on received archive (Part 15).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_remote_diff_preview( $request ) {
	return migrate_lift_rest_restore_diff_preview( $request );
}

/**
 * Cross-site handler: Destination executes restore apply (Part 15).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_remote_apply( $request ) {
	return migrate_lift_rest_restore_execute( $request );
}

/**
 * Admin relay handler: Source requests preflight from paired destination (Part 15).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pair_remote_preflight( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Direct_Transfer_Engine' ) ) {
		return new WP_Error( 'transfer_missing', __( 'Transfer engine unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$pair_id = sanitize_key( (string) $request->get_param( 'pair_id' ) );
	$file    = sanitize_file_name( (string) $request->get_param( 'file' ) );
	$payload = array( 'file' => $file, 'archive' => $file );

	$res = Migrate_Lift_Direct_Transfer_Engine::send_signed_remote_request( $pair_id, '/remote/preflight', $payload );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	return rest_ensure_response( $res );
}

/**
 * Admin relay handler: Source requests diff preview from paired destination (Part 15).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pair_remote_diff( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Direct_Transfer_Engine' ) ) {
		return new WP_Error( 'transfer_missing', __( 'Transfer engine unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$pair_id         = sanitize_key( (string) $request->get_param( 'pair_id' ) );
	$file            = sanitize_file_name( (string) $request->get_param( 'file' ) );
	$file_mode       = sanitize_key( (string) ( $request->get_param( 'file_mode' ) ?: 'overwrite_matching' ) );
	$db_mode         = sanitize_key( (string) ( $request->get_param( 'db_mode' ) ?: 'replace_mapped' ) );
	$selected_tables = (array) ( $request->get_param( 'selected_tables' ) ?: array() );

	$payload = array(
		'file'            => $file,
		'file_mode'       => $file_mode,
		'db_mode'         => $db_mode,
		'selected_tables' => $selected_tables,
	);

	$res = Migrate_Lift_Direct_Transfer_Engine::send_signed_remote_request( $pair_id, '/remote/diff-preview', $payload );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	return rest_ensure_response( $res );
}

/**
 * Admin relay handler: Source executes remote restore apply on paired destination (Part 15).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function migrate_lift_rest_pair_remote_apply( $request ) {
	if ( ! class_exists( 'Migrate_Lift_Direct_Transfer_Engine' ) ) {
		return new WP_Error( 'transfer_missing', __( 'Transfer engine unavailable.', 'migrate-lift' ), array( 'status' => 500 ) );
	}

	$pair_id         = sanitize_key( (string) $request->get_param( 'pair_id' ) );
	$file            = sanitize_file_name( (string) $request->get_param( 'file' ) );
	$confirm_host    = sanitize_text_field( (string) $request->get_param( 'confirm_host' ) );
	$file_mode       = sanitize_key( (string) ( $request->get_param( 'file_mode' ) ?: 'overwrite_matching' ) );
	$db_mode         = sanitize_key( (string) ( $request->get_param( 'db_mode' ) ?: 'replace_mapped' ) );
	$selected_tables = (array) ( $request->get_param( 'selected_tables' ) ?: array() );
	$preserve_admin  = (bool) $request->get_param( 'preserve_admin_user' );
	$confirm_delete  = (bool) $request->get_param( 'confirm_delete' );

	$payload = array(
		'file'                => $file,
		'confirm_host'        => $confirm_host,
		'action'              => 'apply',
		'file_mode'           => $file_mode,
		'db_mode'             => $db_mode,
		'selected_tables'     => $selected_tables,
		'preserve_admin_user' => $preserve_admin,
		'confirm_delete'      => $confirm_delete,
	);

	$res = Migrate_Lift_Direct_Transfer_Engine::send_signed_remote_request( $pair_id, '/remote/apply', $payload );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	return rest_ensure_response( $res );
}

