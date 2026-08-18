<?php
/**
 * Test runner script to verify compatibility on PHP 7.4 through 8.4.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
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
function current_time( $type, $gmt = 0 ) { return date( 'Y-m-d H:i:s' ); }
function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$r = get_object_vars( $args );
	} elseif ( is_array( $args ) ) {
		$r =& $args;
	} else {
		parse_str( (string) $args, $r );
	}
	if ( is_array( $defaults ) && $defaults ) {
		return array_merge( $defaults, $r );
	}
	return $r;
}
function absint( $maybeint ) { return abs( (int) $maybeint ); }
function wp_unslash( $val ) { return is_string( $val ) ? stripslashes( $val ) : $val; }
function apply_filters( $hook_name, $value, ...$args ) { return $value; }

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

// 4b. Test Idempotency Guard (double sanitize / add_option fallback scenario)
$mock_settings_errors = array();
$double_sanitized = $admin->sanitize_bot_token( $sanitized );
expect_equals( $double_sanitized, $sanitized, 'Re-sanitizing an already-encrypted ciphertext must return it unchanged' );
expect_true( empty( $mock_settings_errors ), 'Idempotency guard should not produce settings errors' );
$decrypted_after_double = Bale_Security::decrypt( $double_sanitized );
expect_equals( $decrypted_after_double, $test_token, 'Decryption after double sanitize failed' );

// Invalid token format check
$mock_settings_errors = array();
$invalid_sanitized = $admin->sanitize_bot_token( 'invalid_token_format' );
expect_true( count( $mock_settings_errors ) > 0, 'Invalid token format was not rejected with error' );
echo "[PASS] Bale_Admin sanitization & idempotency guard verified.\n";

// 5. Test requirements check
expect_true( bale_connector_check_requirements() === true, 'Requirements check failed on valid environment' );
echo "[PASS] Requirements check verified.\n";

// 6. Test Lifecycle (Uninstall Simulation)
$mock_options['bale_connector_bot_token_enc'] = 'test';
$mock_options['bale_connector_encryption_key'] = 'key';
$mock_options['bale_connector_keep_data_on_uninstall'] = '0';
$mock_options['bale_connector_db_version'] = '1.0.0';

// Mock WPDB for Phase 3 CRUD tests
class MockFullWPDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = array();
    public $queries = array();

    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare( $query, ...$args ) {
        if ( isset( $args[0] ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        foreach ( $args as $arg ) {
            if ( is_int( $arg ) ) {
                $query = preg_replace( '/%d/', (string) $arg, $query, 1 );
            } else {
                $query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
            }
        }
        return $query;
    }

    public function get_results( $sql, $output = 'ARRAY_A' ) {
        $this->queries[] = $sql;
        $results = array_values( $this->rows );

        // Handle ORDER BY
        if ( preg_match( '/ORDER BY ([a-z_]+) (ASC|DESC)/i', $sql, $matches ) ) {
            $column = strtolower( $matches[1] );
            $desc   = 'DESC' === strtoupper( $matches[2] );
            usort( $results, function( $a, $b ) use ( $column, $desc ) {
                $val_a = isset( $a[ $column ] ) ? $a[ $column ] : null;
                $val_b = isset( $b[ $column ] ) ? $b[ $column ] : null;
                if ( $val_a == $val_b ) {
                    return 0;
                }
                $cmp = ( $val_a < $val_b ) ? -1 : 1;
                return $desc ? -$cmp : $cmp;
            } );
        }

        // Handle LIMIT and OFFSET
        if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/i', $sql, $matches ) ) {
            $limit  = (int) $matches[1];
            $offset = (int) $matches[2];
            $results = array_slice( $results, $offset, $limit );
        }

        return $results;
    }

    public function get_row( $sql, $output = 'ARRAY_A' ) {
        $this->queries[] = $sql;
        if ( preg_match( '/WHERE id = (\d+)/', $sql, $matches ) ) {
            $id = (int) $matches[1];
            return isset( $this->rows[ $id ] ) ? $this->rows[ $id ] : null;
        }
        return null;
    }

    public function insert( $table, $data, $format = null ) {
        $this->insert_id++;
        $row = array_merge( array( 'id' => $this->insert_id ), $data );
        $this->rows[ $this->insert_id ] = $row;
        $this->queries[] = "INSERT INTO $table ...";
        return 1;
    }

    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $id = isset( $where['id'] ) ? (int) $where['id'] : 0;
        if ( $id && isset( $this->rows[ $id ] ) ) {
            $this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
            $this->queries[] = "UPDATE $table ...";
            return 1;
        }
        return 0;
    }

    public function delete( $table, $where, $where_format = null ) {
        $id = isset( $where['id'] ) ? (int) $where['id'] : 0;
        if ( $id && isset( $this->rows[ $id ] ) ) {
            unset( $this->rows[ $id ] );
            $this->queries[] = "DELETE FROM $table ...";
            return 1;
        }
        return 0;
    }

    public function query( $sql ) {
        $this->queries[] = $sql;
        return true;
    }
}
$GLOBALS['wpdb'] = new MockFullWPDB();

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

// ==========================================
// Phase 3: Recipient Management Tests
// ==========================================
echo "\n--- Phase 3: Recipient Management Tests ---\n";

// Reset DB mock for recipient testing
$wpdb_mock = new MockFullWPDB();
$GLOBALS['wpdb'] = $wpdb_mock;

// Test 8a: Add Recipients (user, group, channel)
$user_rec_id = Bale_Recipients::add( array(
	'label'   => 'Admin User',
	'chat_id' => '1246343443',
	'type'    => 'user',
) );
expect_true( is_int( $user_rec_id ) && $user_rec_id > 0, 'Adding user recipient should return valid integer ID' );

$group_rec_id = Bale_Recipients::add( array(
	'label'   => 'Dev Group',
	'chat_id' => '987654321',
	'type'    => 'group',
) );
expect_true( is_int( $group_rec_id ) && $group_rec_id > 0, 'Adding group recipient should return valid integer ID' );

$channel_rec_id = Bale_Recipients::add( array(
	'label'   => 'News Channel',
	'chat_id' => '@bale_news_channel',
	'type'    => 'channel',
) );
expect_true( is_int( $channel_rec_id ) && $channel_rec_id > 0, 'Adding channel recipient should return valid integer ID' );
echo "[PASS] Recipient CRUD: user, group, and channel types added successfully.\n";

// Test 8b: Type Validation in add() and update()
$invalid_type_add = Bale_Recipients::add( array(
	'label'   => 'Invalid Type',
	'chat_id' => '123456',
	'type'    => 'supergroup', // not in allowed types
) );
expect_true( is_wp_error( $invalid_type_add ), 'add() should reject invalid recipient type' );
expect_equals( $invalid_type_add->get_error_code(), 'bale_recipient_invalid_type', 'invalid type error code mismatch' );

$invalid_type_update = Bale_Recipients::update( $user_rec_id, array(
	'type' => 'broadcast',
) );
expect_true( is_wp_error( $invalid_type_update ), 'update() should reject invalid recipient type' );
expect_equals( $invalid_type_update->get_error_code(), 'bale_recipient_invalid_type', 'invalid type update error code mismatch' );
echo "[PASS] Recipient CRUD: invalid types rejected by add() and update().\n";

// Test 8c: chat_id Format Validation in add() and update()
$invalid_chat_add = Bale_Recipients::add( array(
	'label'   => 'Invalid Chat ID',
	'chat_id' => 'not-a-valid-chat-id-string',
	'type'    => 'user',
) );
expect_true( is_wp_error( $invalid_chat_add ), 'add() should reject invalid chat_id format' );
expect_equals( $invalid_chat_add->get_error_code(), 'invalid_chat_id', 'invalid chat_id error code mismatch' );

$invalid_chat_update = Bale_Recipients::update( $user_rec_id, array(
	'chat_id' => 'bad_chat_id',
) );
expect_true( is_wp_error( $invalid_chat_update ), 'update() should reject invalid chat_id format' );
expect_equals( $invalid_chat_update->get_error_code(), 'invalid_chat_id', 'invalid chat_id update error code mismatch' );
echo "[PASS] Recipient CRUD: chat_id format strictly validated.\n";

// Test 8d: Preservation of save without requiring successful test_connection()
// (Notice: we saved user, group, channel recipients above without running test_connection() or even having a valid bot connection!)
$retrieved_channel = Bale_Recipients::get( $channel_rec_id );
expect_true( null !== $retrieved_channel, 'Channel recipient should be retrieved from DB' );
expect_equals( $retrieved_channel['label'], 'News Channel', 'Channel label mismatch' );
expect_equals( $retrieved_channel['chat_id'], '@bale_news_channel', 'Channel chat_id mismatch' );
expect_equals( $retrieved_channel['type'], 'channel', 'Channel type mismatch' );
expect_true( null === $retrieved_channel['last_test_status'], 'Untested recipient should have null last_test_status' );
echo "[PASS] Recipients saved directly without requiring test_connection().\n";

// Test 8e: Update and Delete Recipients
$update_result = Bale_Recipients::update( $user_rec_id, array(
	'label'   => 'Updated Admin User',
	'chat_id' => '1246343444',
	'type'    => 'user',
) );
expect_true( true === $update_result, 'Updating recipient should return true' );
$updated_user = Bale_Recipients::get( $user_rec_id );
expect_equals( $updated_user['label'], 'Updated Admin User', 'Updated label mismatch' );
expect_equals( $updated_user['chat_id'], '1246343444', 'Updated chat_id mismatch' );

$delete_result = Bale_Recipients::delete( $group_rec_id );
expect_true( true === $delete_result, 'Deleting recipient should return true' );
expect_true( null === Bale_Recipients::get( $group_rec_id ), 'Deleted recipient should no longer exist' );
echo "[PASS] Recipient update and delete verified.\n";

// Test 8f: bale_connector_recipients() global helper & filter
$all_recipients = bale_connector_recipients();
expect_true( is_array( $all_recipients ), 'bale_connector_recipients() should return array' );
expect_equals( count( $all_recipients ), 2, 'Should have 2 remaining recipients' );
echo "[PASS] bale_connector_recipients() helper verified.\n";

// Test 8g: test_connection() - Missing Bot Token returns distinct error
$mock_options['bale_connector_bot_token_enc'] = ''; // Clear token
$no_token_test = Bale_Recipients::test_connection( '1246343443', $user_rec_id );
expect_true( is_wp_error( $no_token_test ), 'test_connection without token should return WP_Error' );
expect_equals( $no_token_test->get_error_code(), 'bale_token_missing', 'missing token error code mismatch' );
expect_equals( $no_token_test->get_error_message(), 'Please configure your Bot Token first.', 'missing token message mismatch' );

$updated_after_token_fail = Bale_Recipients::get( $user_rec_id );
expect_equals( $updated_after_token_fail['last_test_status'], 'failed', 'failed test should record last_test_status as failed' );
echo "[PASS] test_connection(): missing bot token returns distinct 'Please configure your Bot Token first.' error.\n";

// Test 8h: test_connection() - Successful getChat call
// Set up valid encrypted token
$mock_options['bale_connector_bot_token_enc'] = Bale_Security::encrypt( '123456789:test_token_for_mocking' );
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":true,"result":{"id":1246343443,"type":"private","first_name":"Sobhan","username":"sobhan_dev"}}',
	'response' => array( 'code' => 200, 'message' => 'OK' ),
);
$GLOBALS['bale_mock_http_last_request'] = null;

$success_test = Bale_Recipients::test_connection( '1246343443', $user_rec_id );
expect_true( ! is_wp_error( $success_test ), 'test_connection should succeed on ok:true' );
expect_equals( $success_test['username'], 'sobhan_dev', 'test_connection username mismatch' );

$last_req = $GLOBALS['bale_mock_http_last_request'];
expect_equals( $last_req['url'], 'https://tapi.bale.ai/bot123456789:test_token_for_mocking/getChat', 'getChat URL mismatch' );
$decoded_body = json_decode( $last_req['args']['body'], true );
expect_equals( $decoded_body['chat_id'], '1246343443', 'getChat body chat_id mismatch' );

$updated_after_success = Bale_Recipients::get( $user_rec_id );
expect_equals( $updated_after_success['last_test_status'], 'success', 'success test should record last_test_status as success' );
echo "[PASS] test_connection(): getChat success verified & status recorded.\n";

// Test 8i: test_connection() - Chat not found / API failure
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}',
	'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
);
$fail_test = Bale_Recipients::test_connection( '@non_existent_channel', $channel_rec_id );
expect_true( is_wp_error( $fail_test ), 'test_connection should return WP_Error when chat not found' );
expect_equals( $fail_test->get_error_message(), 'Bad Request: chat not found', 'chat not found description mismatch' );
expect_true( $fail_test->get_error_code() !== 'bale_token_missing', 'chat not found error must not be conflated with missing token error' );

$updated_after_fail = Bale_Recipients::get( $channel_rec_id );
expect_equals( $updated_after_fail['last_test_status'], 'failed', 'failed chat lookup should record status as failed' );
echo "[PASS] test_connection(): Chat Not Found error handled separately from missing token.\n";

// Test 8j: Server-side recipient lookup in ajax_test_recipient_connection()
// Prepare $_POST payload (notice chat_id is not passed by client, only recipient_id)
$_POST['nonce']        = 'mock_nonce';
$_POST['recipient_id'] = $user_rec_id;
unset( $_POST['chat_id'] ); // Ensure client chat_id is ignored/not required

$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":true,"result":{"id":1246343444,"type":"private","first_name":"Sobhan","username":"sobhan_dev"}}',
	'response' => array( 'code' => 200, 'message' => 'OK' ),
);
$GLOBALS['bale_mock_http_last_request'] = null;

// Mock check_ajax_referer & json response capture
$GLOBALS['mock_ajax_response'] = null;
$GLOBALS['throw_on_json_response'] = false;
class AjaxDieException extends Exception {}

function check_ajax_referer( $action, $query_arg = false, $die = true ) { return 1; }
function wp_send_json_success( $data = null, $status_code = null ) {
	$GLOBALS['mock_ajax_response'] = array( 'success' => true, 'data' => $data );
	if ( ! empty( $GLOBALS['throw_on_json_response'] ) ) {
		throw new AjaxDieException( 'wp_send_json_success called' );
	}
	return;
}
function wp_send_json_error( $data = null, $status_code = null ) {
	$GLOBALS['mock_ajax_response'] = array( 'success' => false, 'data' => $data );
	if ( ! empty( $GLOBALS['throw_on_json_response'] ) ) {
		throw new AjaxDieException( 'wp_send_json_error called' );
	}
	return;
}

$admin->ajax_test_recipient_connection();
expect_true( ! empty( $GLOBALS['mock_ajax_response'] ) && $GLOBALS['mock_ajax_response']['success'] === true, 'ajax_test_recipient_connection should succeed' );
expect_equals( $GLOBALS['bale_mock_http_last_request']['args']['body'], '{"chat_id":"1246343444"}', 'ajax_test_recipient_connection must look up chat_id from DB' );
echo "[PASS] ajax_test_recipient_connection(): verified server-side chat_id lookup by recipient_id.\n";

// Test 8k: AJAX Error Paths & explicit return verification
// Enable throw_on_json_response so any unreturned code execution or fallthrough after wp_send_json_error would be caught
$GLOBALS['throw_on_json_response'] = true;

// 1. Invalid recipient_id (0)
$_POST['recipient_id'] = 0;
$caught = false;
try {
	$admin->ajax_test_recipient_connection();
} catch ( AjaxDieException $e ) {
	$caught = true;
}
expect_true( $caught, 'ajax_test_recipient_connection with id=0 should halt via wp_send_json_error' );
expect_equals( $GLOBALS['mock_ajax_response']['success'], false, 'ajax response should be failure' );
expect_equals( $GLOBALS['mock_ajax_response']['data']['message'], 'Invalid recipient ID.', 'error message mismatch for id=0' );

// 2. Non-existent recipient_id (99999)
$_POST['recipient_id'] = 99999;
$caught = false;
try {
	$admin->ajax_test_recipient_connection();
} catch ( AjaxDieException $e ) {
	$caught = true;
}
expect_true( $caught, 'ajax_test_recipient_connection with non-existent id should halt via wp_send_json_error' );
expect_equals( $GLOBALS['mock_ajax_response']['success'], false, 'ajax response should be failure' );
expect_equals( $GLOBALS['mock_ajax_response']['data']['message'], 'Recipient not found.', 'error message mismatch for non-existent id' );

// 3. ajax_delete_recipient with id=0
$_POST['id'] = 0;
$caught = false;
try {
	$admin->ajax_delete_recipient();
} catch ( AjaxDieException $e ) {
	$caught = true;
}
expect_true( $caught, 'ajax_delete_recipient with id=0 should halt via wp_send_json_error' );
expect_equals( $GLOBALS['mock_ajax_response']['data']['message'], 'Invalid recipient ID.', 'error message mismatch for delete id=0' );

// 4. ajax_save_recipient with invalid type
$_POST['id']      = 0;
$_POST['label']   = 'Invalid Type Test';
$_POST['chat_id'] = '123456';
$_POST['type']    = 'invalid_type';
$caught = false;
try {
	$admin->ajax_save_recipient();
} catch ( AjaxDieException $e ) {
	$caught = true;
}
expect_true( $caught, 'ajax_save_recipient with invalid type should halt via wp_send_json_error' );
expect_equals( $GLOBALS['mock_ajax_response']['data']['message'], 'Invalid recipient type. Must be user, group, or channel.', 'error message mismatch for save invalid type' );

$GLOBALS['throw_on_json_response'] = false;
echo "[PASS] AJAX error paths & explicit return guarantees verified.\n";

echo "ALL TESTS PASSED SUCCESSFULLY on PHP " . PHP_VERSION . "!\n";
