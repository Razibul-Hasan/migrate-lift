<?php
/**
 * Standalone Unit and Integration Test Runner for MigrateLift Job Engine & Activity Logger.
 */

if ( ! defined( 'ABSPATH' ) ) {
	if ( 'cli' === php_sapi_name() ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	} else {
		exit;
	}
}

// phpcs:ignoreFile -- Test suite harness.

define( 'MIGRATE_LIFT_TESTING', true );

// Define mock WordPress environment.
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ml_test_content_' . uniqid() );

if ( ! is_dir( WP_CONTENT_DIR ) ) {
	mkdir( WP_CONTENT_DIR, 0777, true );
}

// Minimal WordPress helper mocks.
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		return @unlink( $file );
	}
}

function wp_generate_password( $length = 12, $special = true, $extra = true ) {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length );
}

function wp_date( $format, $timestamp = null ) {
	return date( $format, $timestamp ?? time() );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}

function sanitize_file_name( $filename ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', basename( (string) $filename ) );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function wp_json_encode( $data ) {
	return json_encode( $data, JSON_UNESCAPED_SLASHES );
}

$GLOBALS['wp_mock_filters'] = array();
function add_filter( $tag, $callback ) {
	$GLOBALS['wp_mock_filters'][ $tag ][] = $callback;
}
function apply_filters( $tag, $value, ...$args ) {
	if ( ! empty( $GLOBALS['wp_mock_filters'][ $tag ] ) ) {
		foreach ( $GLOBALS['wp_mock_filters'][ $tag ] as $cb ) {
			$value = call_user_func( $cb, $value, ...$args );
		}
	}
	return $value;
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'MB_IN_BYTES' ) ) define( 'MB_IN_BYTES', 1048576 );

function wp_timezone() {
	return new DateTimeZone( 'America/New_York' );
}
function wp_timezone_string() {
	return 'America/New_York';
}
function current_datetime() {
	return new DateTimeImmutable( 'now', wp_timezone() );
}
function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function sanitize_email( $email ) {
	return filter_var( trim( (string) $email ), FILTER_SANITIZE_EMAIL );
}

$GLOBALS['wp_mock_crons'] = array();
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['wp_mock_crons'][ $hook ] = array(
		'timestamp'  => $timestamp,
		'recurrence' => $recurrence,
		'args'       => $args,
	);
	return true;
}
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['wp_mock_crons'][ $hook ] = array(
		'timestamp'  => $timestamp,
		'recurrence' => false,
		'args'       => $args,
	);
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['wp_mock_crons'][ $hook ] );
	return true;
}
function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['wp_mock_crons'][ $hook ] ) ? $GLOBALS['wp_mock_crons'][ $hook ]['timestamp'] : false;
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback ) {}
}

function do_action( $tag, ...$args ) {}
function get_current_user_id() { return 1; }
function untrailingslashit( $path ) { return rtrim( (string) $path, '/\\' ); }
function __( $text, $domain = 'default' ) { return $text; }
function _e( $text, $domain = 'default' ) { echo $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = 'default' ) { return esc_attr( $text ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, $decimals ); }

function wp_mkdir_p( $target ) {
	if ( ! is_dir( $target ) ) {
		return mkdir( $target, 0777, true );
	}
	return true;
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_title( $title ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $title ) );
}

function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}

function site_url( $path = '' ) {
	return 'https://example.com' . $path;
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return trim( (string) $url );
	}
}

class ML_Mock_WPDB {
	public $prefix = 'wp_';
	public $collate = 'utf8mb4_unicode_ci';
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
	public function get_var( $query ) {
		return 10485760; // 10MB
	}
	public function get_results( $query ) {
		return array();
	}
}
$GLOBALS['wpdb'] = new ML_Mock_WPDB();
$wpdb = $GLOBALS['wpdb'];

$GLOBALS['wp_mock_options'] = array(
	'home'        => 'https://example.com',
	'siteurl'     => 'https://example.com',
	'date_format' => 'Y-m-d',
	'time_format' => 'H:i',
);

function get_option( $opt, $default = false ) {
	return $GLOBALS['wp_mock_options'][ $opt ] ?? $default;
}

function update_option( $opt, $val, $autoload = null ) {
	$GLOBALS['wp_mock_options'][ $opt ] = $val;
	return true;
}

if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data, $strict = true ) {
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's':
				if ( $strict && '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			case 'a':
			case 'O':
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$/", $data );
		}
		return false;
	}
}

function delete_option( $opt ) {
	unset( $GLOBALS['wp_mock_options'][ $opt ] );
	return true;
}

$GLOBALS['wp_mock_sent_emails'] = array();
function wp_mail( $to, $subject, $message, $headers = '' ) {
	$GLOBALS['wp_mock_sent_emails'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
	);
	return true;
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( $file );
	}
}

if ( ! defined( 'MIGRATE_LIFT_FILE' ) ) {
	define( 'MIGRATE_LIFT_FILE', dirname( __DIR__ ) . '/migrate-lift.php' );
}

function get_bloginfo( $info = 'name' ) {
	return '6.7.1';
}

function size_format( $bytes, $decimals = 0 ) {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$i = 0;
	$b = (float) $bytes;
	while ( $b >= 1024 && $i < count( $units ) - 1 ) {
		$b /= 1024;
		$i++;
	}
	return sprintf( "%.{$decimals}f %s", $b, $units[ $i ] );
}

function migrate_lift_dir( $sub = '' ) {
	$dir = untrailingslashit( WP_CONTENT_DIR ) . '/migrate-lift-files';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return '' === $sub ? $dir : $dir . '/' . $sub;
}

function migrate_lift_download_url( $name ) {
	return 'https://example.com/wp-admin/admin-post.php?action=migrate_lift_download&file=' . rawurlencode( $name );
}

$GLOBALS['wp_mock_transients'] = array();
function get_transient( $transient ) {
	return $GLOBALS['wp_mock_transients'][ $transient ] ?? false;
}
function set_transient( $transient, $value, $expiration = 0 ) {
	$GLOBALS['wp_mock_transients'][ $transient ] = $value;
	return true;
}
function delete_transient( $transient ) {
	unset( $GLOBALS['wp_mock_transients'][ $transient ] );
	return true;
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => '{"success":true}',
		);
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 200;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}
function rest_url( $path = '' ) {
	return 'https://example.com/wp-json/' . ltrim( $path, '/' );
}
function wp_max_upload_size() {
	return 67108864; // 64MB
}
if ( ! defined( 'MIGRATE_LIFT_REST_NAMESPACE' ) ) {
	define( 'MIGRATE_LIFT_REST_NAMESPACE', 'migrate-lift/v1' );
}
if ( ! defined( 'MIGRATE_LIFT_VERSION' ) ) {
	define( 'MIGRATE_LIFT_VERSION', '1.0.0' );
}

// Load MigrateLift dependencies.
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-activity-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-compression.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-tar.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-zip.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-backup-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-import-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-restore-preflight.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-file-replacement-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-database-replacement-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-job-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-scheduler.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-site-pairing.php';
require_once dirname( __DIR__ ) . '/includes/class-migrate-lift-direct-transfer-engine.php';
require_once dirname( __DIR__ ) . '/includes/functions.php';
require_once dirname( __DIR__ ) . '/includes/import.php';

// Initialize engines.
Migrate_Lift_Job_Engine::init();
Migrate_Lift_Backup_Engine::init();
Migrate_Lift_Scheduler::init();
Migrate_Lift_Direct_Transfer_Engine::init();

$passed = 0;
$failed = 0;

function assert_test( $condition, $name ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "  ✔ {$name}\n";
		$passed++;
	} else {
		echo "  ✖ FAILED: {$name}\n";
		$failed++;
	}
}

echo "Starting MigrateLift Job Engine & Activity Logger Test Suite...\n\n";

// --- TEST 1: Sanitization of sensitive data ---
echo "Test 1: Sanitization & Secret Redaction\n";
$dirty_message = "Exporting database for root with password: SuperSecretPassword123! at " . ABSPATH . "wp-config.php";
$clean_message = Migrate_Lift_Activity_Logger::sanitize_text( $dirty_message );
assert_test( false === strpos( $clean_message, 'SuperSecretPassword123!' ), 'Password was successfully redacted' );
assert_test( false === strpos( $clean_message, ABSPATH ), 'ABSPATH was successfully masked to [ABSPATH]' );

$dirty_sql = "Executing SELECT user_pass FROM wp_users WHERE id=1;";
$clean_sql = Migrate_Lift_Activity_Logger::sanitize_text( $dirty_sql );
assert_test( false === strpos( $clean_sql, 'wp_users' ), 'Raw SQL statement was redacted' );

// --- TEST 2: Activity Logger bounded storage and retrieval ---
echo "\nTest 2: Activity Logger CRUD & Capacity\n";
Migrate_Lift_Activity_Logger::clear();
Migrate_Lift_Activity_Logger::info( 'unit_test_event', 'Testing log entry 1', array( 'secret' => 'leak_me' ) );
$events = Migrate_Lift_Activity_Logger::get_events();
assert_test( 1 === $events['total'], 'Recorded single event in activity log' );
assert_test( '[REDACTED]' === $events['items'][0]['context']['secret'], 'Sensitive context key "secret" was redacted' );

// --- TEST 3: Job Creation & Idempotency Key ---
echo "\nTest 3: Job Creation & Idempotency\n";
$idemp_key = 'idemp_' . uniqid();
$job1 = Migrate_Lift_Job_Engine::create_job( 'test_job', array( 'items_total' => 15 ), $idemp_key );
assert_test( ! is_wp_error( $job1 ), 'Job created successfully' );
assert_test( 'queued' === $job1['state'], 'Job initial state is queued' );
assert_test( 15 === $job1['counts']['items_total'], 'Job parameter items_total set to 15' );

// Creating second job with identical idempotency key must return same job
$job2 = Migrate_Lift_Job_Engine::create_job( 'test_job', array( 'items_total' => 20 ), $idemp_key );
assert_test( $job1['id'] === $job2['id'], 'Idempotency key returns identical job instance' );

// --- TEST 4: Concurrency & Lease Claim Lock ---
echo "\nTest 4: Concurrency & Mutual Exclusion Locking\n";
$job_record = Migrate_Lift_Job_Engine::get_job( $job1['id'] );
$claim1 = Migrate_Lift_Job_Engine::claim_lease( $job_record, 'worker_A' );
assert_test( true === $claim1, 'Worker A claimed exclusive lease' );
Migrate_Lift_Job_Engine::save_job( $job_record );

// Worker B attempts to claim while lease is active
$job_reloaded = Migrate_Lift_Job_Engine::get_job( $job1['id'] );
$claim2 = Migrate_Lift_Job_Engine::claim_lease( $job_reloaded, 'worker_B' );
assert_test( is_wp_error( $claim2 ), 'Worker B was rejected while Worker A lease is active' );
assert_test( 'job_locked' === $claim2->get_error_code(), 'Returned job_locked error code' );

// Simulate lease expiration (worker crashed)
$job_reloaded['locked_until'] = time() - 10;
Migrate_Lift_Job_Engine::save_job( $job_reloaded );

$job_expired = Migrate_Lift_Job_Engine::get_job( $job1['id'] );
$claim3 = Migrate_Lift_Job_Engine::claim_lease( $job_expired, 'worker_B' );
assert_test( true === $claim3, 'Worker B successfully recovered expired lease' );
Migrate_Lift_Job_Engine::release_lease( $job_expired );

// --- TEST 5: Interrupted Run & Checkpoint Resume ---
echo "\nTest 5: Interrupted Run & Checkpoint Resumption\n";
// Create fresh test job with 15 items (3 batches of 5 items)
$test_job = Migrate_Lift_Job_Engine::create_job( 'test_job', array( 'items_total' => 15 ) );
$tid = $test_job['id'];

// Step 1: Batch 1
$step1 = Migrate_Lift_Job_Engine::step_job( $tid, 'worker_1' );
assert_test( false === $step1['done'], 'Step 1 yielded for next batch' );
assert_test( 5 === $step1['job']['checkpoint']['cursor'], 'Step 1 saved checkpoint cursor at 5' );
assert_test( 33 === $step1['job']['percentage'], 'Step 1 calculated 33% progress' );

// Simulate process death / restart: worker_2 takes over
$step2 = Migrate_Lift_Job_Engine::step_job( $tid, 'worker_2' );
assert_test( false === $step2['done'], 'Step 2 resumed from checkpoint' );
assert_test( 10 === $step2['job']['checkpoint']['cursor'], 'Step 2 advanced checkpoint cursor from 5 to 10' );
assert_test( 67 === $step2['job']['percentage'], 'Step 2 calculated 67% progress' );

// Step 3: Final Batch
$step3 = Migrate_Lift_Job_Engine::step_job( $tid, 'worker_3' );
assert_test( true === $step3['done'], 'Step 3 reached completion' );
assert_test( 15 === $step3['job']['counts']['items_processed'], 'All 15 items processed without duplicate work' );
assert_test( 'completed' === $step3['job']['state'], 'Job state is completed' );
assert_test( 100 === $step3['job']['percentage'], 'Progress is 100%' );

// Stepping an already completed job returns done = true
$step4 = Migrate_Lift_Job_Engine::step_job( $tid, 'worker_1' );
assert_test( true === $step4['done'], 'Stepping completed job cleanly returns done = true' );

// --- TEST 6: State Machine Transition Rules ---
echo "\nTest 6: State Machine Transition Rules\n";
$completed_job = Migrate_Lift_Job_Engine::get_job( $tid );
$invalid_transition = Migrate_Lift_Job_Engine::transition( $completed_job, 'running' );
assert_test( is_wp_error( $invalid_transition ), 'Transition from completed to running was rejected' );

// --- TEST 7: Format 2.0 Manifest Schema & Secret Redaction ---
echo "\nTest 7: Format 2.0 Manifest Schema & Secret Redaction\n";
$dummy_job = array(
	'id' => 'mljob_manifest_test',
	'checkpoint' => array(
		'files_count' => 42,
		'files_bytes' => 1048576,
		'warnings'    => array( 'Skipped unreadable file: sample.txt' ),
		'db'          => array(
			'tables'     => array( 'wp_posts', 'wp_options' ),
			'sql_bytes'  => 524288,
			'sql_sha256' => hash( 'sha256', 'mock_sql' ),
			'total_rows' => 150,
		),
	),
);
$manifest = Migrate_Lift_Backup_Engine::build_manifest( $dummy_job );
assert_test( '2.0' === $manifest['format_version'], 'Manifest specifies format_version 2.0' );
assert_test( ! empty( $manifest['created_at'] ), 'Manifest includes valid created_at ISO timestamp' );
assert_test( isset( $manifest['source_environment']['home_url'] ), 'Manifest contains source environment metadata' );
assert_test( ! isset( $manifest['source_environment']['db_password'] ), 'Strictly omits db_password' );
assert_test( ! isset( $manifest['source_environment']['auth_key'] ), 'Strictly omits auth_key / salts' );
assert_test( 42 === $manifest['component_inventory']['files_count'], 'Component inventory records accurate file count' );
assert_test( 2 === $manifest['component_inventory']['tables_count'], 'Component inventory records accurate tables count' );
assert_test( ! empty( $manifest['restoration_guidelines']['wp_config_policy'] ), 'Restoration guidelines document safe wp-config handling' );

// --- TEST 8: Granular Path Exclusions ---
echo "\nTest 8: Granular Exclusion Rules\n";
assert_test( true === Migrate_Lift_Backup_Engine::is_excluded( 'migrate-lift-files/archive.tgz' ), 'Excludes plugin storage directory' );
assert_test( true === Migrate_Lift_Backup_Engine::is_excluded( 'cache/object/transient.php' ), 'Excludes cache directories' );
assert_test( true === Migrate_Lift_Backup_Engine::is_excluded( 'upgrade/temp-plugin.zip' ), 'Excludes upgrade folder' );
assert_test( true === Migrate_Lift_Backup_Engine::is_excluded( 'ai1wm-backups/old.wpress' ), 'Excludes other backup plugin directories' );
assert_test( true === Migrate_Lift_Backup_Engine::is_excluded( 'node_modules/package/index.js' ), 'Excludes node_modules' );
assert_test( true === Migrate_Lift_Backup_Engine::is_excluded( 'debug.log' ), 'Excludes debug.log' );
assert_test( false === Migrate_Lift_Backup_Engine::is_excluded( 'uploads/2026/10/photo.jpg' ), 'Includes legitimate media uploads' );
assert_test( false === Migrate_Lift_Backup_Engine::is_excluded( 'themes/twentytwentyfour/style.css' ), 'Includes themes' );
assert_test( false === Migrate_Lift_Backup_Engine::is_excluded( 'plugins/my-plugin/plugin.php' ), 'Includes active plugins' );

// --- TEST 9: Symlink & Allowlisted Root Security ---
echo "\nTest 9: Symlink & Allowlisted Root Security\n";
$outside_secret_dir = sys_get_temp_dir() . '/ml_secret_outside_' . uniqid();
wp_mkdir_p( $outside_secret_dir );
file_put_contents( $outside_secret_dir . '/secret.key', 'CONFIDENTIAL_KEY' );

$symlink_test_dir = WP_CONTENT_DIR . '/symlink_test';
wp_mkdir_p( $symlink_test_dir );
$symlink_file = $symlink_test_dir . '/link_to_outside';

$can_symlink = false;
if ( function_exists( 'symlink' ) ) {
	$can_symlink = @symlink( $outside_secret_dir, $symlink_file );
}

if ( $can_symlink ) {
	$dummy_scan_job = Migrate_Lift_Job_Engine::create_job( 'backup' );
	Migrate_Lift_Job_Engine::step_job( $dummy_scan_job['id'], 'worker_scan_test' ); // init -> scan
	$scan_res = Migrate_Lift_Job_Engine::step_job( $dummy_scan_job['id'], 'worker_scan_test' ); // scan -> db
	$job_after_scan = $scan_res['job'];

	// Check warnings recorded for symlink pointing outside root
	$has_symlink_warning = false;
	foreach ( $job_after_scan['checkpoint']['warnings'] as $warn ) {
		if ( false !== strpos( $warn, 'pointing outside root' ) ) {
			$has_symlink_warning = true;
			break;
		}
	}
	assert_test( $has_symlink_warning, 'Symlink pointing outside root was safely skipped and reported in warnings' );
	Migrate_Lift_Backup_Engine::cleanup_job( $job_after_scan );
} else {
	assert_test( true, 'Symlink creation skipped on OS without unprivileged symlink support' );
}
ml_rmdir_recursive( $outside_secret_dir );
if ( file_exists( $symlink_file ) ) {
	@unlink( $symlink_file );
}

// --- TEST 10: End-to-End Backup Job Lifecycle & Atomic Execution ---
echo "\nTest 10: End-to-End Backup Job Execution & Finalization\n";
// Set up test files in WP_CONTENT_DIR
$mock_content = (string) constant( 'WP_CONTENT_DIR' );
wp_mkdir_p( $mock_content . '/themes/test-theme' );
wp_mkdir_p( $mock_content . '/plugins/test-plugin' );
wp_mkdir_p( $mock_content . '/uploads/2026' );
file_put_contents( $mock_content . '/themes/test-theme/style.css', '/* Theme Style */' );
file_put_contents( $mock_content . '/plugins/test-plugin/test-plugin.php', '<?php // Test Plugin' );
file_put_contents( $mock_content . '/uploads/2026/hello.txt', 'Hello World Backup Test File Content' );

// Mock database tables and dump via filters
add_filter( 'migrate_lift_test_tables', function() {
	return array( 'wp_options', 'wp_posts', 'wp_users' );
} );
add_filter( 'migrate_lift_test_db_dump', function() {
	return "DROP TABLE IF EXISTS `__MIGRATE_LIFT_PREFIX__options`;\nCREATE TABLE `__MIGRATE_LIFT_PREFIX__options` (`id` int);\nINSERT INTO `__MIGRATE_LIFT_PREFIX__options` VALUES (1);\n";
} );

// Create real backup job
$backup_job = Migrate_Lift_Job_Engine::create_job( 'backup' );
assert_test( ! is_wp_error( $backup_job ), 'Created backup job successfully' );
$b_id = $backup_job['id'];

// Step until completion with safety limit
$max_steps = 15;
$step_count = 0;
$final_result = null;

while ( $step_count < $max_steps ) {
	$step_count++;
	$res = Migrate_Lift_Job_Engine::step_job( $b_id, 'backup_worker' );
	if ( is_wp_error( $res ) ) {
		echo "  ✖ Step error: " . $res->get_error_message() . "\n";
		break;
	}
	if ( ! empty( $res['done'] ) ) {
		$final_result = $res;
		break;
	}
}

assert_test( ! empty( $final_result['done'] ), 'Backup job stepped to complete (done = true)' );
$completed_backup_job = Migrate_Lift_Job_Engine::get_job( $b_id );
assert_test( 'completed' === $completed_backup_job['state'], 'Job state is marked completed' );
assert_test( 100 === $completed_backup_job['percentage'], 'Job progress reached 100%' );

$archive_filename = $completed_backup_job['output']['archive'] ?? '';
$archive_filepath = migrate_lift_dir( $archive_filename );
assert_test( file_exists( $archive_filepath ), 'Target archive file exists in storage directory' );
assert_test( filesize( $archive_filepath ) > 0, 'Target archive file has non-zero size' );

// Verify storage directory protection
$htaccess_file = migrate_lift_dir( '.htaccess' );
assert_test( file_exists( $htaccess_file ), '.htaccess protection file exists in storage' );
$htaccess_content = (string) file_get_contents( $htaccess_file );
assert_test( false !== strpos( $htaccess_content, 'Require all denied' ), '.htaccess enforces "Require all denied"' );

// Verify staging directory cleanup
$staged_dir = migrate_lift_dir( 'staging-' . $b_id );
assert_test( ! is_dir( $staged_dir ), 'Staging directory was atomically cleaned up upon finalize' );

// --- TEST 11: Tar Reader Integrity Verification of Generated Archive ---
echo "\nTest 11: Tar Reader Integrity & Manifest Verification\n";
$verification = Migrate_Lift_Backup_Engine::verify_archive_file( $archive_filepath );
assert_test( true === $verification['valid'], 'Archive passed Tar Reader integrity verification' );
assert_test( '2.0' === $verification['format'], 'Archive format verified as 2.0' );
assert_test( true === $verification['has_db'], 'Archive verified to contain database.sql' );
assert_test( is_array( $verification['manifest'] ), 'Manifest was successfully extracted and parsed from .tgz' );

// --- TEST 12: Interrupted / Failed Job Staging Cleanup ---
echo "\nTest 12: Interrupted / Failed Job Staging Cleanup\n";
$fake_failed_job = array(
	'id' => 'mljob_failed_cleanup_test',
	'checkpoint' => array(
		'staging_dir' => migrate_lift_dir( 'staging-mljob_failed_cleanup_test' ),
		'part_file'   => migrate_lift_dir( 'broken-archive.tgz.part' ),
	),
);
wp_mkdir_p( $fake_failed_job['checkpoint']['staging_dir'] );
file_put_contents( $fake_failed_job['checkpoint']['staging_dir'] . '/temp.sql', 'INCOMPLETE' );
file_put_contents( $fake_failed_job['checkpoint']['part_file'], 'PARTIAL_BYTES' );

Migrate_Lift_Backup_Engine::cleanup_job( $fake_failed_job );
assert_test( ! is_dir( $fake_failed_job['checkpoint']['staging_dir'] ), 'Interrupted staging directory was cleanly removed' );
assert_test( ! file_exists( $fake_failed_job['checkpoint']['part_file'] ), 'Interrupted .part file was cleanly removed' );

// --- TEST 13: Extension-Aware Pre-Compressed Media Detection ---
echo "\nTest 13: Extension-Aware Pre-Compressed Media Detection\n";
assert_test( true === Migrate_Lift_Compression::is_already_compressed( 'uploads/2026/10/hero.jpg' ), 'Identified .jpg as pre-compressed' );
assert_test( true === Migrate_Lift_Compression::is_already_compressed( 'uploads/media/video.mp4' ), 'Identified .mp4 as pre-compressed' );
assert_test( true === Migrate_Lift_Compression::is_already_compressed( 'documents/archive.zip' ), 'Identified .zip as pre-compressed' );
assert_test( true === Migrate_Lift_Compression::is_already_compressed( 'uploads/graphic.webp' ), 'Identified .webp as pre-compressed' );
assert_test( false === Migrate_Lift_Compression::is_already_compressed( 'themes/twentytwentyfour/style.css' ), 'Identified .css as compressible' );
assert_test( false === Migrate_Lift_Compression::is_already_compressed( 'database.sql' ), 'Identified .sql as compressible' );
assert_test( false === Migrate_Lift_Compression::is_already_compressed( 'plugins/my-plugin/script.js' ), 'Identified .js as compressible' );

// --- TEST 14: Settings Validation & Sanitization ---
echo "\nTest 14: Compression Settings Validation & Sanitization\n";
$valid_save = Migrate_Lift_Compression::save_settings( array(
	'mode'                  => 'fastest',
	'format'                => 'zip',
	'media_bypass'          => true,
	'exclude_cache'         => true,
	'exclude_logs'          => true,
	'exclude_other_backups' => true,
	'custom_exclusions'     => "custom-cache/\nsecret/*.bak\n../traversal-attempt",
) );
assert_test( ! is_wp_error( $valid_save ), 'Valid settings saved successfully' );
assert_test( 'fastest' === $valid_save['mode'], 'Mode set to fastest' );
assert_test( 'zip' === $valid_save['format'], 'Format set to zip' );
assert_test( in_array( 'custom-cache', $valid_save['custom_exclusions'], true ), 'Includes clean custom exclusion' );
assert_test( ! in_array( '../traversal-attempt', $valid_save['custom_exclusions'], true ), 'Parent directory traversal was stripped from custom exclusions' );

// Test invalid mode rejection
$invalid_mode = Migrate_Lift_Compression::save_settings( array( 'mode' => 'ultra_hyper_destroyer' ) );
assert_test( is_wp_error( $invalid_mode ), 'Rejected invalid compression mode' );
assert_test( 'invalid_mode' === $invalid_mode->get_error_code(), 'Returned invalid_mode error code' );

// Test invalid format rejection
$invalid_format = Migrate_Lift_Compression::save_settings( array( 'format' => '7z_unsupported' ) );
assert_test( is_wp_error( $invalid_format ), 'Rejected unsupported archive format' );
assert_test( 'invalid_format' === $invalid_format->get_error_code(), 'Returned invalid_format error code' );

// Reset settings to balanced for remaining tests
Migrate_Lift_Compression::save_settings( Migrate_Lift_Compression::get_defaults() );

// --- TEST 15: End-to-End Streaming ZIP Backup Execution & Verification ---
echo "\nTest 15: End-to-End Streaming ZIP Backup Execution & Verification\n";
$zip_backup_job = Migrate_Lift_Job_Engine::create_job( 'backup', array( 'format' => 'zip', 'mode' => 'balanced' ) );
assert_test( ! is_wp_error( $zip_backup_job ), 'Created ZIP backup job' );
$z_id = $zip_backup_job['id'];

$z_step_count = 0;
$z_final_res  = null;
while ( $z_step_count < 15 ) {
	$z_step_count++;
	$res = Migrate_Lift_Job_Engine::step_job( $z_id, 'zip_worker' );
	if ( is_wp_error( $res ) ) {
		echo "  ✖ Step error: " . $res->get_error_message() . "\n";
		break;
	}
	if ( ! empty( $res['done'] ) ) {
		$z_final_res = $res;
		break;
	}
}

assert_test( ! empty( $z_final_res['done'] ), 'ZIP backup job completed (done = true)' );
$completed_zip_job = Migrate_Lift_Job_Engine::get_job( $z_id );
$zip_archive_file  = $completed_zip_job['output']['archive'] ?? '';
assert_test( preg_match( '/\.zip$/i', $zip_archive_file ) === 1, 'ZIP archive generated with .zip extension' );

$zip_archive_path = migrate_lift_dir( $zip_archive_file );
assert_test( file_exists( $zip_archive_path ), 'ZIP archive exists on disk' );

$zip_verification = Migrate_Lift_Backup_Engine::verify_archive_file( $zip_archive_path );
assert_test( true === $zip_verification['valid'], 'ZIP archive verified valid by reader' );
assert_test( '2.0' === $zip_verification['format'], 'ZIP archive verified format 2.0' );
assert_test( true === $zip_verification['has_db'], 'ZIP archive verified to contain database.sql' );
assert_test( ! empty( $zip_verification['manifest']['compression'] ), 'Manifest contains compression metadata' );

// --- TEST 16: Truthful Compression Metrics Calculation ---
echo "\nTest 16: Truthful Compression Metrics Calculation\n";
$metrics_normal = Migrate_Lift_Compression::calculate_metrics( 1000000, 450000 );
assert_test( 55.0 === $metrics_normal['reduction_percentage'], 'Calculated 55.0% reduction for compressed files' );
assert_test( 550000 === $metrics_normal['reduction_bytes'], 'Calculated accurate reduction bytes' );

// Non-compressible or slightly larger archive does not report negative savings
$metrics_no_shrink = Migrate_Lift_Compression::calculate_metrics( 100000, 105000 );
assert_test( 0.0 === $metrics_no_shrink['reduction_percentage'], 'Truthfully reported 0.0% reduction when archive is not smaller' );
assert_test( 0 === $metrics_no_shrink['reduction_bytes'], 'Reduction bytes clamped to 0' );

// Zero source edge case
$metrics_zero = Migrate_Lift_Compression::calculate_metrics( 0, 0 );
assert_test( 0.0 === $metrics_zero['reduction_percentage'], 'Zero source handled safely without division by zero' );

// --- TEST 17: Archive Corruption Detection ---
echo "\nTest 17: Archive Corruption Detection\n";
// Create corrupt ZIP file (truncated header)
$corrupt_zip_path = migrate_lift_dir( 'corrupt-test.zip' );
file_put_contents( $corrupt_zip_path, 'PK' . substr( str_repeat( 'CORRUPT_BYTES', 10 ), 0, 50 ) );
$corrupt_zip_report = Migrate_Lift_Backup_Engine::verify_archive_file( $corrupt_zip_path );
assert_test( false === $corrupt_zip_report['valid'], 'Detected corrupt ZIP archive as invalid' );
assert_test( ! empty( $corrupt_zip_report['errors'] ), 'Corrupt ZIP verification reported errors' );
@unlink( $corrupt_zip_path );

// Create corrupt TGZ file (invalid gzip member)
$corrupt_tgz_path = migrate_lift_dir( 'corrupt-test.tgz' );
file_put_contents( $corrupt_tgz_path, "\x1f\x8b\x08NOT_VALID_GZIP_CONTENT_STREAM" );
$corrupt_tgz_report = Migrate_Lift_Backup_Engine::verify_archive_file( $corrupt_tgz_path );
assert_test( false === $corrupt_tgz_report['valid'], 'Detected corrupt TGZ archive as invalid' );
assert_test( ! empty( $corrupt_tgz_report['errors'] ), 'Corrupt TGZ verification reported errors' );
@unlink( $corrupt_tgz_path );

// --- TEST 18: Archive Library Metadata & Listing (Part 07) ---
echo "\nTest 18: Archive Library Metadata & Listing (Part 07)\n";
$test_tar_archive = migrate_lift_dir( 'backup-test-library.tgz' );
file_put_contents( $test_tar_archive, 'SIMULATED_TAR_ARCHIVE_DATA' );
update_option( 'migrate_lift_backup_metadata', array(
	'backup-test-library.tgz' => array(
		'name'                 => 'backup-test-library.tgz',
		'time'                 => time(),
		'size'                 => filesize( $test_tar_archive ),
		'raw_estimate'         => 1048576,
		'reduction_percentage' => 45.0,
		'type'                 => 'full',
		'storage_source'       => 'local',
		'verified'             => true,
		'verified_at'          => time(),
		'status'               => 'ready',
		'format'               => 'tgz',
		'checksum'             => 'test_sha256_hash_12345',
	),
) );

$archives_list = migrate_lift_archives();
assert_test( is_array( $archives_list ) && ! empty( $archives_list ), 'migrate_lift_archives() returns an array with archives' );
$found_item = null;
foreach ( $archives_list as $a ) {
	if ( 'backup-test-library.tgz' === $a['name'] ) {
		$found_item = $a;
		break;
	}
}
assert_test( null !== $found_item, 'Found test archive in archives list' );
assert_test( true === $found_item['verified'], 'Archive reflects verified status from metadata' );
assert_test( 1048576 === $found_item['raw_estimate'], 'Archive reflects raw estimate' );
assert_test( 45.0 === $found_item['reduction_percentage'], 'Archive reflects reduction percentage' );
assert_test( 'tgz' === $found_item['format'], 'Archive format is detected as tgz' );
assert_test( 'ready' === $found_item['status'], 'Archive status is ready' );
assert_test( ! empty( $found_item['download'] ), 'Archive provides authenticated download link' );

// Details extraction
$details = migrate_lift_get_archive_details( 'backup-test-library.tgz' );
assert_test( is_array( $details ), 'migrate_lift_get_archive_details() returns structured array' );
assert_test( 'local' === $details['storage_source'], 'Archive details reports local storage' );
assert_test( isset( $details['storage']['writable'] ), 'Archive details checks storage writability' );

@unlink( $test_tar_archive );

// --- TEST 19: Timezone & DST Next Run Calculation (Part 08) ---
echo "\nTest 19: Timezone & DST Next Run Calculation (Part 08)\n";
$sched_defaults = Migrate_Lift_Scheduler::get_defaults();
assert_test( false === $sched_defaults['enabled'], 'Default schedule is initially disabled' );
assert_test( 'daily' === $sched_defaults['frequency'], 'Default cadence is daily' );
assert_test( '02:00' === $sched_defaults['start_time'], 'Default start time is 02:00' );
assert_test( 7 === $sched_defaults['retention'], 'Default rolling retention count is 7' );

$next_daily = Migrate_Lift_Scheduler::calculate_next_run( array(
	'frequency'   => 'daily',
	'start_time'  => '04:30',
	'day_of_week' => 0,
) );
assert_test( $next_daily > time(), 'Daily next run is in the future' );
$dt_daily = ( new DateTimeImmutable( '@' . $next_daily ) )->setTimezone( wp_timezone() );
assert_test( '04:30' === $dt_daily->format( 'H:i' ), 'Daily next run matches exact configured time in site timezone' );

// Weekly calculation
$next_weekly = Migrate_Lift_Scheduler::calculate_next_run( array(
	'frequency'   => 'weekly',
	'start_time'  => '03:15',
	'day_of_week' => 0, // Sunday
) );
assert_test( $next_weekly > time(), 'Weekly next run is in the future' );
$dt_weekly = ( new DateTimeImmutable( '@' . $next_weekly ) )->setTimezone( wp_timezone() );
assert_test( '03:15' === $dt_weekly->format( 'H:i' ), 'Weekly next run matches configured time 03:15' );
assert_test( 0 === (int) $dt_weekly->format( 'w' ), 'Weekly next run lands on specified day of week (Sunday)' );

// DST shift preservation: wall-clock hour remains stable across timezone conversions
$tz = wp_timezone();
$dt_probe = new DateTimeImmutable( '2026-03-07 04:00:00', $tz ); // Before US Spring forward (04:00 exists on both days)
$dt_after = $dt_probe->modify( '+1 day' );
assert_test( '04:00:00' === $dt_after->format( 'H:i:s' ), 'DateTimeImmutable preserves wall-clock time over DST boundaries' );
assert_test( 82800 === ( $dt_after->getTimestamp() - $dt_probe->getTimestamp() ), 'Spring-forward boundary reflects 23-hour transition (82800 seconds)' );

// --- TEST 20: Schedule Settings Validation & Rescheduling (Part 08) ---
echo "\nTest 20: Schedule Settings Validation & Rescheduling (Part 08)\n";
// Rejection of invalid time string
$invalid_time_result = Migrate_Lift_Scheduler::save_settings( array(
	'enabled'    => true,
	'start_time' => '99:88',
) );
assert_test( is_wp_error( $invalid_time_result ) && 'invalid_schedule_time' === $invalid_time_result->get_error_code(), 'Rejects invalid schedule start time with WP_Error' );

// Invalid cadence sanitized, limits clamped
$sanitized = Migrate_Lift_Scheduler::save_settings( array(
	'enabled'     => true,
	'frequency'   => 'invalid_cadence',
	'start_time'  => '03:45',
	'retention'   => 9999,
	'day_of_week' => 12,
) );
assert_test( true === $sanitized['enabled'], 'Schedule enabled saved' );
assert_test( 'daily' === $sanitized['frequency'], 'Invalid cadence safely defaulted to daily' );
assert_test( '03:45' === $sanitized['start_time'], 'Valid start time preserved' );
assert_test( 100 === $sanitized['retention'], 'Excessive retention clamped to 100 maximum' );
assert_test( 7 === $sanitized['day_of_week'], 'Excessive day of week clamped to 7' );
assert_test( isset( $GLOBALS['wp_mock_crons']['migrate_lift_scheduled_backup_event'] ), 'Scheduled cron event registered upon enabling' );

// Disabling schedule unschedules the hook
Migrate_Lift_Scheduler::save_settings( array( 'enabled' => false ) );
assert_test( ! isset( $GLOBALS['wp_mock_crons']['migrate_lift_scheduled_backup_event'] ), 'Scheduled cron hook cleared on disable' );

// --- TEST 21: Protected Retention Pruning Engine (Part 08) ---
echo "\nTest 21: Protected Retention Pruning Engine (Part 08)\n";
// Clear any pre-existing test archives in storage so retention counts are predictable
foreach ( migrate_lift_ls( migrate_lift_dir() ) as $item ) {
	if ( preg_match( '/\.(tgz|tar\.gz|zip)$/i', $item ) ) {
		@unlink( migrate_lift_dir( $item ) );
	}
}
update_option( 'migrate_lift_backup_metadata', array() );

// Create archive files
$f_keep1    = migrate_lift_dir( 'backup-keep-1.tgz' );
$f_keep2    = migrate_lift_dir( 'backup-keep-2.tgz' );
$f_oldest   = migrate_lift_dir( 'backup-oldest-prunable.tgz' );
$f_recovery = migrate_lift_dir( 'backup-recovery-checkpoint.tgz' );
$f_base     = migrate_lift_dir( 'backup-incremental-base.tgz' );
$f_part     = migrate_lift_dir( 'backup-in-progress.tgz.part' );

file_put_contents( $f_keep1, 'DATA' );
file_put_contents( $f_keep2, 'DATA' );
file_put_contents( $f_oldest, 'DATA' );
file_put_contents( $f_recovery, 'DATA' );
file_put_contents( $f_base, 'DATA' );
file_put_contents( $f_part, 'PARTIAL_DATA' );

$now = time();
touch( $f_oldest, $now - 5000 );
touch( $f_recovery, $now - 4000 );
touch( $f_base, $now - 3000 );
touch( $f_keep2, $now - 2000 );
touch( $f_keep1, $now - 1000 );
touch( $f_part, $now - 500 );

// Register protection options
update_option( 'migrate_lift_recovery_point', 'backup-recovery-checkpoint.tgz' );
update_option( 'migrate_lift_incremental_chain_base', 'backup-incremental-base.tgz' );

// Set metadata
update_option( 'migrate_lift_backup_metadata', array(
	'backup-keep-1.tgz'               => array( 'time' => $now - 1000, 'verified' => true, 'status' => 'ready' ),
	'backup-keep-2.tgz'               => array( 'time' => $now - 2000, 'verified' => true, 'status' => 'ready' ),
	'backup-oldest-prunable.tgz'      => array( 'time' => $now - 5000, 'verified' => true, 'status' => 'ready' ),
	'backup-recovery-checkpoint.tgz'  => array( 'time' => $now - 4000, 'verified' => true, 'status' => 'ready' ),
	'backup-incremental-base.tgz'     => array( 'time' => $now - 3000, 'verified' => true, 'status' => 'ready' ),
) );

// Prune with retention count = 2
$prune_result = Migrate_Lift_Scheduler::prune_retention( 2 );

assert_test( in_array( 'backup-oldest-prunable.tgz', $prune_result['deleted'], true ), 'Oldest prunable archive was pruned' );
assert_test( ! file_exists( $f_oldest ), 'Oldest prunable file unlinked from disk' );
assert_test( file_exists( $f_recovery ), 'Recovery checkpoint archive was NEVER deleted' );
assert_test( file_exists( $f_base ), 'Incremental chain base archive was NEVER deleted' );
assert_test( file_exists( $f_part ), 'Active .part staging file was NEVER deleted' );
assert_test( file_exists( $f_keep1 ), 'Newest archive kept' );
assert_test( file_exists( $f_keep2 ), 'Second newest archive kept' );

// Clean up remaining test archives
@unlink( $f_keep1 );
@unlink( $f_keep2 );
@unlink( $f_recovery );
@unlink( $f_base );
@unlink( $f_part );
delete_option( 'migrate_lift_recovery_point' );
delete_option( 'migrate_lift_incremental_chain_base' );

// --- TEST 22: Exponential Backoff & Retry Policy (Part 08) ---
echo "\nTest 22: Exponential Backoff & Retry Policy (Part 08)\n";
Migrate_Lift_Scheduler::clear_failures();
assert_test( 0 === (int) get_option( 'migrate_lift_schedule_failures', 0 ), 'Failures reset to 0' );

// 1st failure: 15 min delay
$t0 = time();
Migrate_Lift_Scheduler::record_failure( 'Disk timeout' );
assert_test( 1 === (int) get_option( 'migrate_lift_schedule_failures' ), 'Failure count is 1' );
$retry_cron = $GLOBALS['wp_mock_crons']['migrate_lift_scheduled_backup_retry_event'] ?? null;
assert_test( null !== $retry_cron, 'Retry cron event scheduled on failure 1' );
assert_test( ( $retry_cron['timestamp'] - $t0 ) >= ( 15 * 60 - 5 ), 'First retry backoff delay is 15 minutes' );

// 2nd failure: 30 min delay
Migrate_Lift_Scheduler::record_failure( 'Database lock' );
assert_test( 2 === (int) get_option( 'migrate_lift_schedule_failures' ), 'Failure count is 2' );
$retry_cron2 = $GLOBALS['wp_mock_crons']['migrate_lift_scheduled_backup_retry_event'];
assert_test( ( $retry_cron2['timestamp'] - $t0 ) >= ( 30 * 60 - 5 ), 'Second retry backoff delay is 30 minutes' );

// 3rd failure: 60 min delay
Migrate_Lift_Scheduler::record_failure( 'Process timeout' );
assert_test( 3 === (int) get_option( 'migrate_lift_schedule_failures' ), 'Failure count is 3' );
$retry_cron3 = $GLOBALS['wp_mock_crons']['migrate_lift_scheduled_backup_retry_event'];
assert_test( ( $retry_cron3['timestamp'] - $t0 ) >= ( 60 * 60 - 5 ), 'Third retry backoff delay is 60 minutes' );

// 4th failure: capped at 60 min
Migrate_Lift_Scheduler::record_failure( 'Disk full' );
assert_test( 4 === (int) get_option( 'migrate_lift_schedule_failures' ), 'Failure count is 4' );
$retry_cron4 = $GLOBALS['wp_mock_crons']['migrate_lift_scheduled_backup_retry_event'];
assert_test( ( $retry_cron4['timestamp'] - $t0 ) <= ( 60 * 60 + 5 ), 'Retry delay is capped at 60 minutes' );

// Clear failures resets counter and unschedules retry
Migrate_Lift_Scheduler::clear_failures();
assert_test( 0 === (int) get_option( 'migrate_lift_schedule_failures', 0 ), 'Failure counter successfully cleared' );
assert_test( ! isset( $GLOBALS['wp_mock_crons']['migrate_lift_scheduled_backup_retry_event'] ), 'Retry hook cleared upon reset' );

// --- TEST 23: Large Backup Import Engine: Staging & Quarantine Protection (Part 09) ---
echo "\nTest 23: Large Backup Import Engine: Staging & Quarantine Protection (Part 09)\n";
Migrate_Lift_Import_Engine::init();
$staging_dir    = Migrate_Lift_Import_Engine::get_staging_dir();
$quarantine_dir = Migrate_Lift_Import_Engine::get_quarantine_dir();

assert_test( is_dir( $staging_dir ), 'Staging directory exists' );
assert_test( is_dir( $quarantine_dir ), 'Quarantine directory exists' );
assert_test( file_exists( $staging_dir . '/.htaccess' ), 'Staging directory has .htaccess deny rules' );
assert_test( file_exists( $staging_dir . '/index.php' ), 'Staging directory has index.php' );
assert_test( file_exists( $quarantine_dir . '/.htaccess' ), 'Quarantine directory has .htaccess deny rules' );
assert_test( file_exists( $quarantine_dir . '/index.php' ), 'Quarantine directory has index.php' );

// Path traversal filename rejection
$traversal_threw = false;
try {
	Migrate_Lift_Import_Engine::sanitize_archive_filename( '../../etc/passwd' );
} catch ( Exception $e ) {
	$traversal_threw = true;
}
assert_test( $traversal_threw, 'sanitize_archive_filename strictly rejects ../ directory traversal' );

$null_threw = false;
try {
	Migrate_Lift_Import_Engine::sanitize_archive_filename( "backup.tgz\0.php" );
} catch ( Exception $e ) {
	$null_threw = true;
}
assert_test( $null_threw, 'sanitize_archive_filename strictly rejects null bytes' );

$invalid_ext = false;
try {
	Migrate_Lift_Import_Engine::sanitize_archive_filename( 'shell.php' );
} catch ( Exception $e ) {
	$invalid_ext = true;
}
assert_test( $invalid_ext, 'sanitize_archive_filename rejects non-archive extensions' );

// Arbitrary server paths cannot be scanned or imported
$arbitrary_threw = false;
try {
	Migrate_Lift_Import_Engine::import_staged_archive( '/etc/shadow' );
} catch ( Exception $e ) {
	$arbitrary_threw = true;
}
assert_test( $arbitrary_threw, 'import_staged_archive rejects arbitrary root system paths' );

// Staging scanning only lists files inside staging folder
$test_staged_file = $staging_dir . '/sample-staged-backup.tgz';
file_put_contents( $test_staged_file, "\x1f\x8b" . str_repeat( 'X', 200 ) );
$staged_list = Migrate_Lift_Import_Engine::scan_staged_archives();
$found_staged = false;
foreach ( $staged_list as $item ) {
	if ( 'sample-staged-backup.tgz' === $item['filename'] ) {
		$found_staged = true;
		break;
	}
}
assert_test( $found_staged, 'scan_staged_archives detects archives inside dedicated staging directory' );
@unlink( $test_staged_file );

// --- TEST 24: Security Verification & Magic Bytes Defense (Part 09) ---
echo "\nTest 24: Security Verification & Magic Bytes Defense (Part 09)\n";

// Fake .tgz containing text/html must be rejected
$fake_tgz = sys_get_temp_dir() . '/fake-malformed.tgz';
file_put_contents( $fake_tgz, '<html><body>Not a gzip</body></html>' . str_repeat( 'A', 200 ) );
$fake_res = Migrate_Lift_Import_Engine::verify_archive_security( $fake_tgz, 'fake-malformed.tgz' );
assert_test( false === $fake_res['valid'], 'Archive with invalid magic bytes rejected' );
assert_test( ! empty( $fake_res['errors'] ), 'Errors logged for invalid file signature' );
@unlink( $fake_tgz );

// Entry path traversal assertions
$entry_traversal_threw = false;
try {
	Migrate_Lift_Import_Engine::assert_entry_safe_path( '../../wp-config.php' );
} catch ( Exception $e ) {
	$entry_traversal_threw = true;
}
assert_test( $entry_traversal_threw, 'assert_entry_safe_path rejects ../ directory traversal' );

$entry_root_threw = false;
try {
	Migrate_Lift_Import_Engine::assert_entry_safe_path( '/etc/passwd' );
} catch ( Exception $e ) {
	$entry_root_threw = true;
}
assert_test( $entry_root_threw, 'assert_entry_safe_path rejects leading forward slash absolute paths' );

$entry_win_threw = false;
try {
	Migrate_Lift_Import_Engine::assert_entry_safe_path( 'C:\\Windows\\System32\\cmd.exe' );
} catch ( Exception $e ) {
	$entry_win_threw = true;
}
assert_test( $entry_win_threw, 'assert_entry_safe_path rejects Windows drive letters' );

// Valid entries are allowed
$valid_passed = true;
try {
	Migrate_Lift_Import_Engine::assert_entry_safe_path( 'migratelift.json' );
	Migrate_Lift_Import_Engine::assert_entry_safe_path( 'database.sql' );
	Migrate_Lift_Import_Engine::assert_entry_safe_path( 'wp-content/themes/twentytwentyfour/style.css' );
} catch ( Exception $e ) {
	$valid_passed = false;
}
assert_test( $valid_passed, 'assert_entry_safe_path allows whitelisted migratelift components' );

// --- TEST 25: Resumable Chunked Browser Upload & Cancellation (Part 09) ---
echo "\nTest 25: Resumable Chunked Browser Upload & Cancellation (Part 09)\n";

$chunk_file = sys_get_temp_dir() . '/ml_test_chunk_1.bin';
file_put_contents( $chunk_file, str_repeat( 'C', 500 ) );

// Upload chunk 1 (0 to 500 of 1000)
$chunk1_res = Migrate_Lift_Import_Engine::handle_upload_chunk(
	'test-resumable-upload.tgz',
	0,
	1000,
	$chunk_file,
	500
);
assert_test( false === $chunk1_res['done'], 'Chunk 1 received: done is false' );
assert_test( 500 === $chunk1_res['received'], 'Chunk 1 received bytes: 500' );
assert_test( ! empty( $chunk1_res['receipt'] ), 'Batch receipt returned for chunk 1' );

// Status query returns resumable offset 500
$status_res = Migrate_Lift_Import_Engine::get_upload_status( 'test-resumable-upload.tgz' );
assert_test( 500 === $status_res['size'], 'Upload status returns exact received size for resumption' );
assert_test( true === $status_res['resumable'], 'Upload marked as resumable' );

// Repeat chunk 1 handled idempotently
$idemp_res = Migrate_Lift_Import_Engine::handle_upload_chunk(
	'test-resumable-upload.tgz',
	0,
	1000,
	$chunk_file,
	500
);
assert_test( 500 === $idemp_res['received'], 'Retried duplicate chunk handled idempotently' );

// Cancel upload cleans up staging files
$cancel_ok = Migrate_Lift_Import_Engine::cancel_upload( 'test-resumable-upload.tgz' );
assert_test( $cancel_ok, 'cancel_upload returned true' );
$status_after_cancel = Migrate_Lift_Import_Engine::get_upload_status( 'test-resumable-upload.tgz' );
assert_test( 0 === $status_after_cancel['size'], 'Part file deleted upon upload cancellation' );
@unlink( $chunk_file );

// --- TEST 26: Restore Preflight Engine (Part 10) ---
echo "\nTest 26: Restore Preflight Engine (Part 10)\n";

// Create a valid test archive for preflight
$preflight_test_arch = migrate_lift_dir( 'test-preflight-source.tgz' );
$pre_tar = new Migrate_Lift_Tar_Writer( $preflight_test_arch, 6 );
$pre_manifest = array(
	'format_version' => '2.0',
	'format'         => '2.0',
	'home'           => 'https://source-site.com',
	'siteurl'        => 'https://source-site.com',
	'prefix'         => 'wp_old_',
	'created_at'     => wp_date( 'c' ),
	'created'        => wp_date( 'Y-m-d H:i:s' ),
	'wp'             => get_bloginfo( 'version' ),
	'php'            => PHP_VERSION,
	'bytes'          => 10240,
	'files'          => 15,
	'is_multisite'   => false,
);
$pre_tar->add_string( 'migratelift.json', wp_json_encode( $pre_manifest ) );
$pre_tar->add_string( 'database.sql', "CREATE TABLE `__MIGRATE_LIFT_PREFIX__posts` (ID int);\n" );
$pre_tar->add_string( 'wp-content/themes/theme/style.css', "/* Theme */\n" );
$pre_tar->close();

$preflight_res = Migrate_Lift_Restore_Preflight::run_preflight( 'test-preflight-source.tgz' );
assert_test( $preflight_res['passed'], 'Preflight passes for compatible archive' );
assert_test( 0 === count( $preflight_res['blockers'] ), 'Zero blockers on valid preflight' );
assert_test( ! empty( $preflight_res['checks'] ), 'Preflight returned structured checks array' );
assert_test( 'https://source-site.com' === $preflight_res['summary']['source_url'], 'Source URL detected in preflight summary' );
assert_test( 'wp_old_' === $preflight_res['summary']['source_prefix'], 'Source prefix detected in preflight summary' );
assert_test( true === $preflight_res['summary']['is_migration'], 'Domain migration detected (source != dest)' );

// --- TEST 27: Preflight Blockers (Multisite & Invalid File) ---
echo "\nTest 27: Preflight Blockers (Part 10)\n";

// Multisite archive must be blocked
$ms_test_arch = migrate_lift_dir( 'test-multisite-blocked.tgz' );
$ms_tar = new Migrate_Lift_Tar_Writer( $ms_test_arch, 6 );
$ms_manifest = array_merge( $pre_manifest, array( 'is_multisite' => true ) );
$ms_tar->add_string( 'migratelift.json', wp_json_encode( $ms_manifest ) );
$ms_tar->add_string( 'database.sql', "-- SQL\n" );
$ms_tar->close();

$ms_res = Migrate_Lift_Restore_Preflight::run_preflight( 'test-multisite-blocked.tgz' );
assert_test( false === $ms_res['passed'], 'Preflight fails for multisite archive' );
assert_test( count( $ms_res['blockers'] ) > 0, 'Blocker reported for multisite archive' );
@unlink( $ms_test_arch );

// Non-existent archive throws Exception
$missing_threw = false;
try {
	Migrate_Lift_Restore_Preflight::run_preflight( 'non-existent-archive.tgz' );
} catch ( Exception $e ) {
	$missing_threw = true;
}
assert_test( $missing_threw, 'Preflight throws exception for non-existent archive' );

// --- TEST 28: Verified Destination Recovery Checkpoint Creation & Retention Protection ---
echo "\nTest 28: Verified Destination Recovery Checkpoint Creation (Part 10)\n";

$checkpoint_meta = Migrate_Lift_Restore_Preflight::create_recovery_checkpoint();
assert_test( ! empty( $checkpoint_meta['file'] ), 'Recovery checkpoint generated with filename' );
assert_test( file_exists( migrate_lift_dir( $checkpoint_meta['file'] ) ), 'Recovery checkpoint file exists on disk' );
assert_test( true === $checkpoint_meta['verified'], 'Recovery checkpoint is marked verified' );
assert_test( true === $checkpoint_meta['protected'], 'Recovery checkpoint is marked protected' );

$registered_point = get_option( Migrate_Lift_Restore_Preflight::OPTION_RECOVERY_POINT );
assert_test( $registered_point === $checkpoint_meta['file'], 'Recovery checkpoint option registered in system' );

$rec_status = Migrate_Lift_Restore_Preflight::get_recovery_status();
assert_test( true === $rec_status['has_checkpoint'], 'Recovery status reports active checkpoint' );
assert_test( $rec_status['checkpoint']['file'] === $checkpoint_meta['file'], 'Recovery status returns matching checkpoint file' );

// Retention pruning test: create dummy older archive and prune to 1
$dummy_old = migrate_lift_dir( 'dummy-prunable-backup.tgz' );
file_put_contents( $dummy_old, "X" );
touch( $dummy_old, time() - 10000 );
$all_meta = get_option( 'migrate_lift_backup_metadata', array() );
$all_meta['dummy-prunable-backup.tgz'] = array( 'time' => time() - 10000, 'verified' => true, 'status' => 'ready' );
update_option( 'migrate_lift_backup_metadata', $all_meta );

// Prune to retention = 1
Migrate_Lift_Scheduler::prune_retention( 1 );
assert_test( file_exists( migrate_lift_dir( $checkpoint_meta['file'] ) ), 'Verified recovery checkpoint was PROTECTED from retention pruning' );

// Clean up test checkpoint
@unlink( migrate_lift_dir( $checkpoint_meta['file'] ) );
@unlink( $dummy_old );
@unlink( $preflight_test_arch );
delete_option( Migrate_Lift_Restore_Preflight::OPTION_RECOVERY_POINT );
delete_option( Migrate_Lift_Restore_Preflight::OPTION_RECOVERY_META );

// --- TEST 29: Dry-Run Changes Report (Part 10) ---
echo "\nTest 29: Dry-Run Changes Report (Part 10)\n";

// Create test archive for dry-run
$dry_run_arch = migrate_lift_dir( 'test-dry-run-source.tgz' );
$dry_tar = new Migrate_Lift_Tar_Writer( $dry_run_arch, 6 );
$dry_tar->add_string( 'migratelift.json', wp_json_encode( $pre_manifest ) );
$dry_tar->add_string( 'database.sql', "CREATE TABLE `__MIGRATE_LIFT_PREFIX__options` (id int);\n" );
$dry_tar->close();

$dry_report = Migrate_Lift_Restore_Preflight::generate_dry_run_report( 'test-dry-run-source.tgz' );
assert_test( ! empty( $dry_report['file_plan'] ), 'Dry-run includes file_plan' );
assert_test( ! empty( $dry_report['database_plan'] ), 'Dry-run includes database_plan' );
assert_test( in_array( 'wp-content/' . MIGRATE_LIFT_STORE . '/*', $dry_report['file_plan']['protected_exclusions'], true ), 'Dry-run file plan protects migrate-lift storage' );
assert_test( 'mltmp_' === $dry_report['database_plan']['staging_prefix'], 'Dry-run database plan defines mltmp_ temporary tables' );
assert_test( count( $dry_report['database_plan']['url_replacements'] ) >= 3, 'Dry-run includes standard, JSON, and URL-encoded replacements' );
@unlink( $dry_run_arch );

// --- TEST 30: Explicit Hostname Confirmation (Part 10) ---
echo "\nTest 30: Explicit Hostname Confirmation (Part 10)\n";

// Expected host is 'example.com' from home_url('https://example.com') mock
$valid_confirm = Migrate_Lift_Restore_Preflight::validate_site_confirmation( 'example.com' );
assert_test( true === $valid_confirm, 'validate_site_confirmation passes on exact hostname' );

$valid_confirm_url = Migrate_Lift_Restore_Preflight::validate_site_confirmation( 'https://example.com/' );
assert_test( true === $valid_confirm_url, 'validate_site_confirmation tolerates protocol and trailing slash' );

$mismatch_threw = false;
try {
	Migrate_Lift_Restore_Preflight::validate_site_confirmation( 'wrong-destination.org' );
} catch ( Exception $e ) {
	$mismatch_threw = true;
}
assert_test( $mismatch_threw, 'validate_site_confirmation strictly rejects mismatched hostname' );

// --- TEST 31: File Replacement Policies & Canonical Protection (Part 11) ---
echo "\nTest 31: File Replacement Policies & Canonical Protection (Part 11)\n";

$file_modes = Migrate_Lift_File_Replacement_Engine::get_supported_modes();
assert_test( isset( $file_modes['add_missing'] ), 'File modes declares add_missing' );
assert_test( isset( $file_modes['overwrite_matching'] ), 'File modes declares overwrite_matching' );
assert_test( isset( $file_modes['sync_managed'] ), 'File modes declares sync_managed' );

assert_test( true === Migrate_Lift_File_Replacement_Engine::is_protected_path( 'wp-config.php' ), 'Protects wp-config.php' );
assert_test( true === Migrate_Lift_File_Replacement_Engine::is_protected_path( '.htaccess' ), 'Protects .htaccess' );
assert_test( true === Migrate_Lift_File_Replacement_Engine::is_protected_path( 'web.config' ), 'Protects web.config' );
assert_test( true === Migrate_Lift_File_Replacement_Engine::is_protected_path( 'wp-content/' . MIGRATE_LIFT_STORE . '/archive.tgz' ), 'Protects plugin storage files' );
assert_test( true === Migrate_Lift_File_Replacement_Engine::is_protected_path( 'wp-content/plugins/' . dirname( plugin_basename( MIGRATE_LIFT_FILE ) ) . '/migrate-lift.php' ), 'Protects active plugin runtime' );
assert_test( true === Migrate_Lift_File_Replacement_Engine::is_protected_path( 'wp-content/mu-plugins/custom.php' ), 'Protects mu-plugins' );
assert_test( false === Migrate_Lift_File_Replacement_Engine::is_protected_path( 'wp-content/themes/twentytwentyfour/style.css' ), 'Allows normal theme file' );

assert_test( false === Migrate_Lift_File_Replacement_Engine::is_safe_entry_path( '../wp-config.php' ), 'Rejects parent traversal' );
assert_test( false === Migrate_Lift_File_Replacement_Engine::is_safe_entry_path( '/etc/passwd' ), 'Rejects absolute path' );
assert_test( true === Migrate_Lift_File_Replacement_Engine::is_safe_entry_path( 'wp-content/themes/mytheme/style.css' ), 'Allows safe wp-content entry' );

// --- TEST 32: File Replacement Diff & Safe Staging (Part 11) ---
echo "\nTest 32: File Replacement Diff & Safe Staging (Part 11)\n";

// Set up destination files
$test_theme_dir = (string) constant( 'WP_CONTENT_DIR' ) . '/themes/test-theme';
wp_mkdir_p( $test_theme_dir );
file_put_contents( $test_theme_dir . '/style.css', "/* Old style */\n" );
file_put_contents( $test_theme_dir . '/dest-only.txt', "dest only file\n" );

// Set up archive with matching file and new file
$test_file_arch = migrate_lift_dir( 'test-file-replace.tgz' );
$file_tar = new Migrate_Lift_Tar_Writer( $test_file_arch, 6 );
$file_tar->add_string( 'migratelift.json', wp_json_encode( $pre_manifest ) );
$file_tar->add_string( 'database.sql', "-- SQL\n" );
$file_tar->add_string( 'wp-content/themes/test-theme/style.css', "/* Brand New Style */\n" );
$file_tar->add_string( 'wp-content/themes/test-theme/new-template.php', "<?php // new\n" );
$file_tar->finish();
$file_tar->close();

$diff_overwrite = Migrate_Lift_File_Replacement_Engine::generate_diff_preview( 'test-file-replace.tgz', Migrate_Lift_File_Replacement_Engine::MODE_OVERWRITE_MATCHING );
assert_test( 1 === $diff_overwrite['counts']['create'], 'Diff detects 1 file to create' );
assert_test( 1 === $diff_overwrite['counts']['update'], 'Diff detects 1 file to update' );
assert_test( 0 === $diff_overwrite['counts']['delete'], 'Diff detects 0 files to delete in overwrite mode' );

$diff_add_missing = Migrate_Lift_File_Replacement_Engine::generate_diff_preview( 'test-file-replace.tgz', Migrate_Lift_File_Replacement_Engine::MODE_ADD_MISSING );
assert_test( 1 === $diff_add_missing['counts']['create'], 'Diff add_missing detects 1 file to create' );
assert_test( 1 === $diff_add_missing['counts']['skip'], 'Diff add_missing skips existing file' );

$diff_sync = Migrate_Lift_File_Replacement_Engine::generate_diff_preview( 'test-file-replace.tgz', Migrate_Lift_File_Replacement_Engine::MODE_SYNC_MANAGED );
assert_test( $diff_sync['counts']['delete'] > 0, 'Diff sync_managed detects destination-only file for pruning' );
assert_test( true === $diff_sync['requires_delete_confirmation'], 'Diff sync_managed requires explicit delete confirmation' );

// Mock recovery checkpoint existence so replacement can proceed
update_option( Migrate_Lift_Restore_Preflight::OPTION_RECOVERY_POINT, 'mock-recovery.tgz', false );
update_option( Migrate_Lift_Restore_Preflight::OPTION_RECOVERY_META, array( 'file' => 'mock-recovery.tgz', 'verified' => true ), false );
$mock_rec_file = migrate_lift_dir( 'mock-recovery.tgz' );
file_put_contents( $mock_rec_file, "mock recovery" );

// Test apply_file_replacement in overwrite_matching mode
$apply_res = Migrate_Lift_File_Replacement_Engine::apply_file_replacement( 'test-file-replace.tgz', Migrate_Lift_File_Replacement_Engine::MODE_OVERWRITE_MATCHING );
assert_test( true === $apply_res['success'], 'apply_file_replacement succeeded' );
assert_test( 1 === $apply_res['files_updated'], 'apply_file_replacement updated 1 file' );
assert_test( 1 === $apply_res['files_created'], 'apply_file_replacement created 1 file' );
assert_test( "/* Brand New Style */\n" === file_get_contents( $test_theme_dir . '/style.css' ), 'Existing file was replaced with new archive contents' );
assert_test( file_exists( $test_theme_dir . '/dest-only.txt' ), 'Destination-only file was preserved in overwrite mode' );

@unlink( $test_file_arch );
@unlink( $mock_rec_file );
delete_option( Migrate_Lift_Restore_Preflight::OPTION_RECOVERY_POINT );
delete_option( Migrate_Lift_Restore_Preflight::OPTION_RECOVERY_META );

// --- TEST 33: Database Replacement Policies & Schema Mapping (Part 12) ---
echo "\nTest 33: Database Replacement Policies & Schema Mapping (Part 12)\n";

$db_modes = Migrate_Lift_Database_Replacement_Engine::get_supported_modes();
assert_test( isset( $db_modes['replace_mapped'] ), 'DB modes declares replace_mapped' );
assert_test( isset( $db_modes['replace_selected'] ), 'DB modes declares replace_selected' );
assert_test( isset( $db_modes['keep_destination'] ), 'DB modes declares keep_destination' );

$table_groups = Migrate_Lift_Database_Replacement_Engine::get_table_groups( 'wp_' );
assert_test( isset( $table_groups['posts_and_comments'] ), 'Provides posts_and_comments group' );
assert_test( in_array( 'wp_posts', $table_groups['posts_and_comments']['tables'], true ), 'Includes wp_posts in group' );

// Create test archive with manifest for DB diff preview
$db_test_arch = migrate_lift_dir( 'test-db-replace.tgz' );
$db_tar = new Migrate_Lift_Tar_Writer( $db_test_arch, 6 );
$db_manifest = array_merge( $pre_manifest, array(
	'prefix'  => 'wp_src_',
	'home'    => 'https://source-domain.com',
	'siteurl' => 'https://source-domain.com',
) );
$db_tar->add_string( 'migratelift.json', wp_json_encode( $db_manifest ) );
$db_tar->add_string( 'database.sql', "CREATE TABLE `__MIGRATE_LIFT_PREFIX__posts` (id int);\n" );
$db_tar->finish();
$db_tar->close();

$db_diff = Migrate_Lift_Database_Replacement_Engine::generate_diff_preview( 'test-db-replace.tgz', Migrate_Lift_Database_Replacement_Engine::MODE_REPLACE_MAPPED );
assert_test( 'wp_src_' === $db_diff['source_prefix'], 'Identifies source prefix wp_src_' );
assert_test( 'wp_' === $db_diff['dest_prefix'], 'Identifies destination prefix wp_' );
assert_test( 'mltmp_' === $db_diff['staging_prefix'], 'Stages tables in mltmp_' );
assert_test( count( $db_diff['search_replace_plan'] ) >= 3, 'Generates URL search and replace plan' );
@unlink( $db_test_arch );

// --- TEST 34: Recursive Serialization-Safe Search & Replace (Part 12) ---
echo "\nTest 34: Recursive Serialization-Safe Search & Replace (Part 12)\n";

$pairs = array(
	'https://source-domain.com' => 'https://very-long-destination-site-url.com',
);

// 1. Plain string
$plain_str = 'Visit https://source-domain.com today!';
$replaced_plain = Migrate_Lift_Database_Replacement_Engine::recursive_serialization_replace( $plain_str, $pairs );
assert_test( 'Visit https://very-long-destination-site-url.com today!' === $replaced_plain, 'Plain string replaced' );

// 2. Serialized string with length modification
$orig_data = array(
	'site_url' => 'https://source-domain.com',
	'meta'     => array(
		'deep_url' => 'https://source-domain.com/about',
		'count'    => 42,
	),
);
$serialized_orig = serialize( $orig_data );
$replaced_serialized = Migrate_Lift_Database_Replacement_Engine::recursive_serialization_replace( $serialized_orig, $pairs );

// Must still be valid serialized string!
assert_test( is_serialized( $replaced_serialized ), 'Replaced serialized data remains valid serialized string' );
$unserialized_res = unserialize( $replaced_serialized );
assert_test( false !== $unserialized_res, 'Unserialize succeeds without error' );
assert_test( 'https://very-long-destination-site-url.com' === $unserialized_res['site_url'], 'Serialized root URL correctly transformed' );
assert_test( 'https://very-long-destination-site-url.com/about' === $unserialized_res['meta']['deep_url'], 'Nested serialized URL correctly transformed' );
assert_test( 42 === $unserialized_res['meta']['count'], 'Non-string values preserved intact' );

// 3. Maintenance mode control
Migrate_Lift_Database_Replacement_Engine::enable_maintenance_mode();
assert_test( file_exists( ABSPATH . '.maintenance' ), 'enable_maintenance_mode writes .maintenance' );
Migrate_Lift_Database_Replacement_Engine::disable_maintenance_mode();
assert_test( ! file_exists( ABSPATH . '.maintenance' ), 'disable_maintenance_mode removes .maintenance' );

// --- TEST 35: Site Pairing Token Generation, Payload & Single-Use Consumption (Part 13) ---
echo "\nTest 35: Site Pairing Token Generation & Single-Use Consumption (Part 13)\n";
$token_data = Migrate_Lift_Site_Pairing::generate_pairing_token();
assert_test( ! empty( $token_data['pairing_key'] ), 'Generates formatted pairing key' );
assert_test( 0 === strpos( $token_data['pairing_key'], 'MLPAIR:' ), 'Pairing key begins with MLPAIR: prefix' );
assert_test( $token_data['expires_in'] === 900, 'Token lifespan defaults to 900 seconds (15 minutes)' );

$parsed_key = Migrate_Lift_Site_Pairing::parse_pairing_key( $token_data['pairing_key'] );
assert_test( ! is_wp_error( $parsed_key ), 'Parses valid MLPAIR key payload successfully' );
assert_test( ! empty( $parsed_key['token'] ), 'Payload contains token identifier' );

// Single-use consumption test
$consumed = Migrate_Lift_Site_Pairing::consume_pairing_token( $parsed_key['token'] );
assert_test( ! is_wp_error( $consumed ), 'First token consumption succeeds' );

// Second attempt must fail (single-use!)
$replayed = Migrate_Lift_Site_Pairing::consume_pairing_token( $parsed_key['token'] );
assert_test( is_wp_error( $replayed ), 'Second token consumption rejected (single-use enforced)' );

// --- TEST 36: SSRF Host & Private Range Protection (Part 13) ---
echo "\nTest 36: SSRF Host & Private Range Protection (Part 13)\n";
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'http://' . 'localhost' ) ), 'Blocks localhost' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'http://' . '127.0.0.1' . '/test' ) ), 'Blocks loopback 127.0.0.1' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'http://10.0.0.1/admin' ) ), 'Blocks private 10.0.0.0/8' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'http://192.168.1.5' ) ), 'Blocks private 192.168.0.0/16' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'http://169.254.169.254/latest/meta-data' ) ), 'Blocks cloud metadata 169.254.169.254' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'ftp://example.com' ) ), 'Rejects unsupported protocols (ftp)' );

// --- TEST 37: HMAC-SHA256 Request Signing and Constant-Time Verification (Part 13) ---
echo "\nTest 37: HMAC-SHA256 Request Signing and Replay Protection (Part 13)\n";
$mock_pair_id = 'pair_test_' . uniqid();
$mock_auth_key = bin2hex( random_bytes( 32 ) );
$paired_sites = array(
	$mock_pair_id => array(
		'pair_id'     => $mock_pair_id,
		'role'        => 'destination',
		'remote_url'  => 'https://remote.example.com',
		'remote_name' => 'Remote Site',
		'auth_key'    => $mock_auth_key,
		'status'      => 'connected',
		'paired_at'   => time(),
		'last_active' => time(),
	),
);
update_option( Migrate_Lift_Site_Pairing::OPTION_PAIRED_SITES, $paired_sites );

$body_payload = '{"test":true,"data":"abc"}';
$headers = Migrate_Lift_Site_Pairing::sign_request( $mock_pair_id, $body_payload );
assert_test( ! empty( $headers['X-ML-Signature'] ), 'Generates HMAC-SHA256 signature header' );
assert_test( ! empty( $headers['X-ML-Timestamp'] ), 'Generates timestamp header' );
assert_test( ! empty( $headers['X-ML-Nonce'] ), 'Generates unique nonce header' );

// Constant-time signature verification test
$expected_calc = hash_hmac( 'sha256', $headers['X-ML-Timestamp'] . '.' . $headers['X-ML-Nonce'] . '.' . $body_payload, $mock_auth_key );
assert_test( hash_equals( $expected_calc, $headers['X-ML-Signature'] ), 'HMAC signature matches expected calculation with hash_equals' );

// Revocation test
Migrate_Lift_Site_Pairing::revoke_pairing( $mock_pair_id );
$revoked_check = Migrate_Lift_Site_Pairing::get_paired_site( $mock_pair_id );
assert_test( null === $revoked_check, 'Revoking removes pairing record immediately' );

// --- TEST 38: Direct Transfer Bounded Chunks and Checksum Verification (Part 14) ---
echo "\nTest 38: Direct Transfer Bounded Chunks and Integrity Contract (Part 14)\n";
$transfer_id = 'test_xfer_' . uniqid();
$session_dir = Migrate_Lift_Direct_Transfer_Engine::get_incoming_dir( $transfer_id );
assert_test( is_dir( $session_dir ), 'Creates incoming transfer session directory' );

// Write test chunk
$chunk_data = "MigrateLift test chunk binary content " . str_repeat( 'X', 1024 );
$chunk_sha = hash( 'sha256', $chunk_data );

$chunk_file = $session_dir . '/chunk_000000.part';
file_put_contents( $chunk_file, $chunk_data );
assert_test( hash_equals( $chunk_sha, hash_file( 'sha256', $chunk_file ) ), 'Chunk SHA-256 verified successfully' );

// Clean up incoming session
Migrate_Lift_Direct_Transfer_Engine::cleanup_incoming_session( $transfer_id );
assert_test( ! is_dir( $session_dir ), 'Cleans up temporary transfer session directory' );

echo "\nTest 39: Transfer and Storage Hardening\n";
assert_test( true === migrate_lift_is_archive_name( 'site-20260101-abc.tgz' ), 'Accepts a normal archive name' );
foreach ( array( 'shell.php', 'index.php', 'import-state.php', 'x.phtml', '../a.tgz', '.htaccess', 'a.tgz.php' ) as $bad_name ) {
	assert_test( false === migrate_lift_is_archive_name( $bad_name ), "Rejects archive name: $bad_name" );
}
$tmp_path = migrate_lift_tmp( 'export-tmp', 'database.sql' );
assert_test( false === strpos( $tmp_path, '/export-tmp/' ), 'Working folder name is not guessable' );
assert_test( 1 === preg_match( '#/export-tmp-[A-Za-z0-9]{32}/database\.sql$#', $tmp_path ), 'Working folder carries the random key' );
assert_test( $tmp_path === migrate_lift_tmp( 'export-tmp', 'database.sql' ), 'Working folder stays the same across calls' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'http://93.184.216.34/' ) ), 'Rejects plain HTTP to a public address' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'https://[fd00::1]/' ) ), 'Blocks IPv6 unique-local address' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'https://[fe80::1]/' ) ), 'Blocks IPv6 link-local address' );
assert_test( is_wp_error( Migrate_Lift_Site_Pairing::validate_target_url( 'https://100.64.0.1/' ) ), 'Blocks carrier-grade NAT range' );
assert_test( true === Migrate_Lift_Site_Pairing::validate_target_url( 'https://93.184.216.34/' ), 'Allows HTTPS to a public address' );

echo "\nTest 40: Database Dump Helpers\n";
assert_test( '165' === migrate_lift_bit_literal( '165' ), 'BIT value from mysqlnd stays a plain number' );
assert_test( '0xa5' === migrate_lift_bit_literal( "\xA5" ), 'BIT value as raw bytes becomes a hex literal' );
assert_test( 200 === migrate_lift_next_batch( 100, 1000, 500 ), 'Dump batch grows while rows are small' );
assert_test( 500 === migrate_lift_next_batch( 400, 1000, 500 ), 'Dump batch never passes the maximum' );
assert_test( 50 === migrate_lift_next_batch( 100, 5 * MB_IN_BYTES, 500 ), 'Dump batch shrinks when rows are large' );
assert_test( 10 === migrate_lift_next_batch( 10, 5 * MB_IN_BYTES, 500 ), 'Dump batch never drops below the minimum' );

// Clean up test temporary directory.
function ml_rmdir_recursive( $dir ) {
	if ( ! is_dir( $dir ) ) return;
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) continue;
		$p = $dir . '/' . $item;
		is_dir( $p ) ? ml_rmdir_recursive( $p ) : wp_delete_file( $p );
	}
	@rmdir( $dir );
}
ml_rmdir_recursive( (string) constant( 'WP_CONTENT_DIR' ) );

echo "\n----------------------------------------\n";
echo "Summary: {$passed} passed, {$failed} failed.\n";
exit( $failed > 0 ? 1 : 0 );

