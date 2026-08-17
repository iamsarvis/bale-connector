<?php
/**
 * Test runner script to verify compatibility on PHP 7.4 through 8.4.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['wp_version'] = '6.4.2';

// Custom check-and-throw helper (reliable regardless of zend.assertions ini)
function expect_true( $condition, $message = 'Assertion failed' ) {
    if ( ! $condition ) {
        throw new RuntimeException( "FAIL: $message" );
    }
}

function expect_equals( $actual, $expected, $message = '' ) {
    if ( $actual !== $expected ) {
        $actual_str = var_export( $actual, true );
        $expected_str = var_export( $expected, true );
        throw new RuntimeException( "FAIL: $message (Expected: $expected_str, Got: $actual_str)" );
    }
}

// Mock WordPress functions
function plugin_dir_path( $file ) { return dirname( __DIR__ ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.com/wp-content/plugins/bale-connector/'; }
function plugin_basename( $file ) { return 'bale-connector/bale-connector.php'; }
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function add_action( $hook, $callback ) {}
function add_filter( $hook, $callback ) {}
function load_plugin_textdomain( $domain, $deprecated, $plugin_rel_path ) {}
function is_admin() { return true; }
function current_user_can( $cap ) { return true; }
function __ ( $text, $domain = 'default' ) { return $text; }
function _e( $text, $domain = 'default' ) { echo $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return $url; }
function wp_kses_post( $text ) { return $text; }
function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . $path; }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_attr_e( $text, $domain = 'default' ) { echo esc_attr( $text ); }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	if ( $special_chars ) {
		$chars .= '!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
	}
	$pwd = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$pwd .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $pwd;
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

// Mock WP_Error class
class WP_Error {
	public $errors = array();
	public $error_data = array();
	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' === $code ) { return; }
		$this->errors[ $code ] = array( $message );
		$this->error_data[ $code ] = $data;
	}
	public function get_error_code() { $codes = array_keys( $this->errors ); return empty( $codes ) ? '' : $codes[0]; }
	public function get_error_message( $code = '' ) {
		if ( '' === $code ) { $code = $this->get_error_code(); }
		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}
	public function get_error_data( $code = '' ) {
		if ( '' === $code ) { $code = $this->get_error_code(); }
		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : '';
	}
}

// Mock wp_remote_post and response helpers — captures the last request for assertions.
$GLOBALS['bale_mock_http_last_request'] = null;
$GLOBALS['bale_mock_http_next_response'] = null;

function wp_remote_post( $url, $args = array() ) {
	global $bale_mock_http_last_request, $bale_mock_http_next_response;
	$bale_mock_http_last_request = array( 'url' => $url, 'args' => $args );
	if ( null === $bale_mock_http_next_response ) {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => '{"ok":true,"result":{"id":1246343443,"first_name":"room_manager_bot","username":"room_manager_bot"}}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}
	return $bale_mock_http_next_response;
}
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? $response['body'] : ''; }
function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? $response['response']['code'] : 0; }
function checked( $checked, $current = true, $echo = true ) {
    $res = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
    if ( $echo ) echo $res;
    return $res;
}
$mock_settings_errors = array();
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
    global $mock_settings_errors;
    $mock_settings_errors[] = array( 'setting' => $setting, 'code' => $code, 'message' => $message, 'type' => $type );
}
function settings_errors() {}
function get_admin_page_title() { return 'Bale Connector'; }
function settings_fields( $group ) {}
function do_settings_sections( $page ) {}
function submit_button( $text ) {}
function deactivate_plugins( $plugins ) {}
function wp_die( $message = '', $title = '', $args = array() ) {}

$mock_options = array();
function get_option( $name, $default = false ) {
    global $mock_options;
    return isset( $mock_options[ $name ] ) ? $mock_options[ $name ] : $default;
}
function update_option( $name, $value ) {
    global $mock_options;
    $mock_options[ $name ] = $value;
    return true;
}
function add_option( $name, $value ) {
    global $mock_options;
    if ( ! isset( $mock_options[ $name ] ) ) {
        $mock_options[ $name ] = $value;
        return true;
    }
    return false;
}
function delete_option( $name ) {
    global $mock_options;
    unset( $mock_options[ $name ] );
    return true;
}

// Load plugin files
require_once dirname( __DIR__ ) . '/bale-connector.php';

echo "Running tests on PHP " . PHP_VERSION . "\n";

// 1. Test Key Generation & Storage
$generated_key = Bale_Security::ensure_encryption_key();
expect_true( false !== $generated_key && strlen( $generated_key ) === 32, 'Dedicated 32-byte key generation failed' );
expect_true( isset( $mock_options['bale_connector_encryption_key'] ), 'Key was not saved to options table' );
expect_true( Bale_Security::has_crypto_support(), 'Crypto engine support check failed' );
echo "[PASS] Dedicated encryption key generated & stored.\n";

// 2. Test Bale_Security encryption & decryption & masking
$test_token = '2078691878:9YqxS2lO5ZfomgKjKnvUqIprQl4kFfeF1kw';
$encrypted = Bale_Security::encrypt( $test_token );
expect_true( ! empty( $encrypted ), 'Encryption produced empty result' );
expect_true( $encrypted !== $test_token, 'Token stored in plaintext!' );
expect_true( 0 !== strpos( base64_decode( $encrypted ), 'plain:' ), 'Token stored using insecure plain fallback!' );

$decrypted = Bale_Security::decrypt( $encrypted );
expect_equals( $decrypted, $test_token, 'Decryption mismatch' );

$masked = Bale_Security::mask_token( $test_token );
expect_equals( $masked, str_repeat( '*', strlen( $test_token ) - 4 ) . 'F1kw', 'Masking mismatch' );

// 3. Test Decryption Failure Distinction (corrupted payload / wrong key)
$corrupted_payload = base64_encode( 'sodium:invalid_nonce_and_bad_ciphertext_data_here' );
$failed_decrypt = Bale_Security::decrypt( $corrupted_payload );
expect_equals( $failed_decrypt, false, 'Decryption failure must return false' );

// Empty payload distinction
$empty_decrypt = Bale_Security::decrypt( '' );
expect_equals( $empty_decrypt, null, 'Empty payload decrypt must return null' );
echo "[PASS] Bale_Security encrypt/decrypt/distinction verified.\n";

// 4. Test Bale_Admin token sanitization
$admin = new Bale_Admin();
$sanitized = $admin->sanitize_bot_token( $test_token );
expect_true( ! empty( $sanitized ), 'Sanitization failed for valid token' );
$decrypted_from_sanitized = Bale_Security::decrypt( $sanitized );
expect_equals( $decrypted_from_sanitized, $test_token, 'Sanitized token decryption mismatch' );

// Invalid token format check
$mock_settings_errors = array();
$invalid_sanitized = $admin->sanitize_bot_token( 'invalid_token_format' );
expect_true( count( $mock_settings_errors ) > 0, 'Invalid token format was not rejected with error' );
echo "[PASS] Bale_Admin sanitization verified.\n";

// 5. Test requirements check
expect_true( bale_connector_check_requirements() === true, 'Requirements check failed on valid environment' );
echo "[PASS] Requirements check verified.\n";

// 6. Test Lifecycle (Uninstall Simulation)
$mock_options['bale_connector_bot_token_enc'] = 'test';
$mock_options['bale_connector_encryption_key'] = 'key';
$mock_options['bale_connector_keep_data_on_uninstall'] = '0';
$mock_options['bale_connector_db_version'] = '1.0.0';

class MockWPDB {
    public $prefix = 'wp_';
    public $queries = array();
    public function query( $sql ) {
        $this->queries[] = $sql;
        return true;
    }
}
$GLOBALS['wpdb'] = new MockWPDB();

define( 'WP_UNINSTALL_PLUGIN', true );
require dirname( __DIR__ ) . '/uninstall.php';

expect_true( ! isset( $mock_options['bale_connector_bot_token_enc'] ), 'Token option not removed on uninstall' );
expect_true( ! isset( $mock_options['bale_connector_encryption_key'] ), 'Encryption key option not removed on uninstall' );
expect_true( ! isset( $mock_options['bale_connector_keep_data_on_uninstall'] ), 'Keep data option not removed on uninstall' );
expect_equals( count( $GLOBALS['wpdb']->queries ), 3, 'Not all 3 tables dropped on uninstall' );
echo "[PASS] Uninstall cleanup lifecycle verified.\n";

// 7. Test Bale_Api_Client — JSON sendMessage via mocked wp_remote_post
echo "\n--- Bale_Api_Client tests (mocked HTTP) ---\n";
$client = new Bale_Api_Client( '123456789:test_token_for_mocking' );

// Test 7a: sendMessage (JSON mode) — verify request shape.
$GLOBALS['bale_mock_http_next_response'] = null;
$result = $client->sendMessage( 1246343443, 'Hello from test' );
expect_true( ! is_wp_error( $result ), 'sendMessage should not return error on ok:true' );
expect_equals( $result['id'], 1246343443, 'sendMessage result.id mismatch' );

// Verify the captured request used JSON content-type and correct URL.
$last = $GLOBALS['bale_mock_http_last_request'];
expect_equals( $last['url'], 'https://tapi.bale.ai/bot123456789:test_token_for_mocking/sendMessage', 'sendMessage URL mismatch' );
expect_equals( $last['args']['headers']['Content-Type'], 'application/json', 'sendMessage should use JSON content-type' );
expect_true( 15 === $last['args']['timeout'], 'sendMessage timeout should be 15' );

$decoded_body = json_decode( $last['args']['body'], true );
expect_equals( $decoded_body['chat_id'], 1246343443, 'sendMessage body chat_id mismatch' );
expect_equals( $decoded_body['text'], 'Hello from test', 'sendMessage body text mismatch' );
echo "[PASS] sendMessage: JSON mode request shape verified.\n";

// Test 7b: sendPhoto with a real local file (multipart mode).
$test_file = sys_get_temp_dir() . '/bale_test_image.jpg';
$test_content = 'fake-jpeg-content-for-testing';
file_put_contents( $test_file, $test_content );

$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":true,"result":{"message_id":42,"chat":{"id":1246343443,"type":"private"}}}',
	'response' => array( 'code' => 200, 'message' => 'OK' ),
);

$result = $client->sendPhoto( 1246343443, $test_file, 'Test caption' );
expect_true( ! is_wp_error( $result ), 'sendPhoto (multipart) should not return error on ok:true' );
expect_equals( $result['message_id'], 42, 'sendPhoto result.message_id mismatch' );

// Verify the captured request used multipart content-type with a boundary.
$last = $GLOBALS['bale_mock_http_last_request'];
expect_equals( $last['url'], 'https://tapi.bale.ai/bot123456789:test_token_for_mocking/sendPhoto', 'sendPhoto multipart URL mismatch' );
$content_type = $last['args']['headers']['Content-Type'];
expect_true( 0 === strpos( $content_type, 'multipart/form-data; boundary=' ), 'sendPhoto multipart should have multipart Content-Type header, got: ' . $content_type );

// Extract the boundary and verify the body structure.
preg_match( '/boundary=(.+)$/', $content_type, $m );
$boundary = $m[1];
$body = $last['args']['body'];
expect_true( false !== strpos( $body, '--' . $boundary . "\r\n" ), 'multipart body must start with --boundary CRLF' );
expect_true( false !== strpos( $body, '--' . $boundary . "--\r\n" ), 'multipart body must end with closing --boundary-- CRLF' );
expect_true( false !== strpos( $body, 'Content-Disposition: form-data; name="chat_id"' ), 'multipart body must include chat_id field' );
expect_true( false !== strpos( $body, 'Content-Disposition: form-data; name="caption"' ), 'multipart body must include caption field' );
expect_true( false !== strpos( $body, 'Content-Disposition: form-data; name="photo"; filename="bale_test_image.jpg"' ), 'multipart body must include photo file field' );
expect_true( false !== strpos( $body, $test_content ), 'multipart body must contain file contents' );
echo "[PASS] sendPhoto: multipart body structure verified (boundary, CRLF, closing delimiter, file field).\n";

// Test 7c: sendPhoto with file_id (JSON mode, not multipart).
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":true,"result":{"message_id":43}}',
	'response' => array( 'code' => 200, 'message' => 'OK' ),
);
$result = $client->sendPhoto( 1246343443, 'AgACAgUAAACKZmEAAQ', 'file_id caption' );
expect_true( ! is_wp_error( $result ), 'sendPhoto (file_id) should not return error' );
$last = $GLOBALS['bale_mock_http_last_request'];
expect_equals( $last['args']['headers']['Content-Type'], 'application/json', 'sendPhoto with file_id should use JSON content-type' );
$decoded_body = json_decode( $last['args']['body'], true );
expect_equals( $decoded_body['photo'], 'AgACAgUAAACKZmEAAQ', 'sendPhoto file_id body photo mismatch' );
echo "[PASS] sendPhoto: file_id (JSON mode) verified.\n";

// Test 7d: ok:false response — WP_Error must carry error_code, description, retry_after.
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":false,"error_code":401,"description":"Unauthorized","parameters":{"retry_after":30}}',
	'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
);
$result = $client->getMe();
expect_true( is_wp_error( $result ), 'ok:false should return WP_Error' );
expect_equals( $result->get_error_code(), 'bale_api_error_401', 'error code string mismatch' );
expect_equals( $result->get_error_message(), 'Unauthorized', 'error message should be Bale description' );
$data = $result->get_error_data();
expect_equals( $data['error_code'], 401, 'error_data error_code mismatch' );
expect_equals( $data['description'], 'Unauthorized', 'error_data description mismatch' );
expect_equals( $data['retry_after'], 30, 'error_data retry_after mismatch' );
echo "[PASS] ok:false: WP_Error carries error_code, description, retry_after.\n";

// Test 7e: chat_id validation — invalid format returns WP_Error before HTTP call.
$GLOBALS['bale_mock_http_last_request'] = null;
$result = $client->sendMessage( 'not-a-valid-chat-id', 'test' );
expect_true( is_wp_error( $result ), 'invalid chat_id should return WP_Error' );
expect_equals( $result->get_error_code(), 'invalid_chat_id', 'invalid chat_id error code mismatch' );
expect_true( null === $GLOBALS['bale_mock_http_last_request'], 'invalid chat_id must not trigger HTTP call' );
echo "[PASS] chat_id validation rejects invalid format without HTTP call.\n";

// Test 7f: char limit guard — text exceeding 4096 returns WP_Error before HTTP call.
$GLOBALS['bale_mock_http_last_request'] = null;
$long_text = str_repeat( 'x', 4097 );
$result = $client->sendMessage( 123, $long_text );
expect_true( is_wp_error( $result ), 'text over 4096 should return WP_Error' );
expect_equals( $result->get_error_code(), 'bale_char_limit_exceeded', 'char limit error code mismatch' );
expect_true( null === $GLOBALS['bale_mock_http_last_request'], 'char limit violation must not trigger HTTP call' );
echo "[PASS] Character limit guard rejects over-limit text without HTTP call.\n";

// Test 7g: non-existent path is treated as file_id (JSON mode), not multipart.
// is_local_file() returns false for paths that don't exist on disk, so they
// fall through to the file_id/URL code path. This is correct: an opaque file_id
// string won't exist as a file. The bale_file_not_found guard in
// build_multipart_body() is a TOCTOU safety net for the race between
// is_local_file() and the actual read.
$GLOBALS['bale_mock_http_next_response'] = null;
$GLOBALS['bale_mock_http_last_request'] = null;
$missing_file = '/nonexistent/path/to/file.jpg';
$result = $client->sendPhoto( 123, $missing_file, 'test' );
$last = $GLOBALS['bale_mock_http_last_request'];
expect_equals( $last['args']['headers']['Content-Type'], 'application/json', 'non-existent path should be treated as file_id (JSON mode)' );
$decoded_body = json_decode( $last['args']['body'], true );
expect_equals( $decoded_body['photo'], $missing_file, 'non-existent path should be sent as photo field value (file_id mode)' );
echo "[PASS] Non-existent path correctly treated as file_id (JSON mode), not multipart.\n";

// Cleanup temp files.
@unlink( $test_file );

echo "ALL TESTS PASSED SUCCESSFULLY on PHP " . PHP_VERSION . "!\n";
