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
$GLOBALS['bale_mock_filters'] = array();
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['bale_mock_filters'][ $hook ][] = array( 'cb' => $callback, 'args' => $accepted_args );
	return true;
}
function do_action( $hook, ...$args ) {}
function apply_filters( $hook_name, $value, ...$args ) {
	if ( empty( $GLOBALS['bale_mock_filters'][ $hook_name ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['bale_mock_filters'][ $hook_name ] as $entry ) {
		$cb_args = array_merge( array( $value ), array_slice( $args, 0, $entry['args'] - 1 ) );
		$value   = call_user_func_array( $entry['cb'], $cb_args );
	}
	return $value;
}
function load_plugin_textdomain( $domain, $deprecated, $plugin_rel_path ) {}
function is_admin() { return true; }
function current_user_can( $cap ) { return true; }
function __ ( $text, $domain = 'default' ) { return $text; }
function _e( $text, $domain = 'default' ) { echo $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return $url; }
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
function sanitize_textarea_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}
function sanitize_key( $key, $mode = 'lower' ) {
	$raw = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	return $raw;
}
function get_post_type( $post ) { return 'wpcf7_contact_form'; }
function get_the_title( $post ) { return 'Mock Form Title'; }
function wp_kses_post( $text ) { return $text; }
function absint( $maybeint ) { return abs( (int) $maybeint ); }
function wp_unslash( $val ) { return is_string( $val ) ? stripslashes( $val ) : $val; }

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
// bale_mock_http_next_response: single response used for the next call (null = default ok:true).
// bale_mock_http_queue: optional FIFO of responses consumed one per call (takes priority).
// bale_mock_http_calls: log of every call (url + args) for call-order assertions.
$GLOBALS['bale_mock_http_last_request'] = null;
$GLOBALS['bale_mock_http_next_response'] = null;
$GLOBALS['bale_mock_http_queue'] = null;
$GLOBALS['bale_mock_http_calls'] = array();

function wp_list_pluck( $list, $field ) {
	$values = array();
	foreach ( $list as $item ) {
		$values[] = is_object( $item ) ? $item->$field : $item[ $field ];
	}
	return $values;
}

function wp_remote_post( $url, $args = array() ) {
	global $bale_mock_http_last_request, $bale_mock_http_next_response, $bale_mock_http_queue, $bale_mock_http_calls;
	$bale_mock_http_calls[] = array( 'url' => $url, 'args' => $args );
	$bale_mock_http_last_request = array( 'url' => $url, 'args' => $args );
	if ( is_array( $bale_mock_http_queue ) && count( $bale_mock_http_queue ) > 0 ) {
		return array_shift( $bale_mock_http_queue );
	}
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
    public $row_tables = array();
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

        // Filter rows by the table the query targets (rows of all mocked
        // tables live in one store; real SQL targets one table at a time).
        if ( preg_match( '/FROM\s+([a-zA-Z0-9_\.]+)/', $sql, $table_matches ) ) {
            $target = $table_matches[1];
            $results = array();
            foreach ( $this->rows as $rid => $row ) {
                if ( isset( $this->row_tables[ $rid ] ) && $this->row_tables[ $rid ] === $table_matches[1] ) {
                    $results[ $rid ] = $row;
                }
            }
            $results = array_values( $results );
        } else {
            $results = array_values( $this->rows );
        }

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
        if ( preg_match( '/FROM\s+(\S+)/s', $sql, $matches ) && preg_match( "/form_id = '?(\\d+)/", $sql, $form_id_matches ) ) {
            // Emulate the UNIQUE KEY (form_type, form_id) lookup.
            $table = $matches[1];
            $form_id = (string) $form_id_matches[1];
            foreach ( $this->rows as $rid => $row ) {
                if ( isset( $this->row_tables[ $rid ], $row['form_type'], $row['form_id'] )
                    && $this->row_tables[ $rid ] === $table
                    && 'cf7' === $row['form_type']
                    && $form_id === (string) $row['form_id'] ) {
                    return $row;
                }
            }
            return null;
        }
        return null;
    }

    public function insert( $table, $data, $format = null ) {
        $this->insert_id++;
        $row = array_merge( array( 'id' => $this->insert_id ), $data );
        $this->rows[ $this->insert_id ] = $row;
        $this->row_tables[ $this->insert_id ] = $table;
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
        if ( isset( $where['form_type'], $where['form_id'] ) ) {
            // Emulate the UNIQUE KEY (form_type, form_id) targeted update.
            foreach ( $this->rows as $rid => $row ) {
                if ( isset( $this->row_tables[ $rid ], $row['form_type'], $row['form_id'] )
                    && $this->row_tables[ $rid ] === $table
                    && (string) $row['form_type'] === (string) $where['form_type']
                    && (string) $row['form_id'] === (string) $where['form_id'] ) {
                    $this->rows[ $rid ] = array_merge( $this->rows[ $rid ], $data );
                    $this->queries[] = "UPDATE $table ...";
                    return 1;
                }
            }
            return 0;
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

// Test 8i-2: getChatMember() client method — request shape + error normalization
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":true,"result":{"user":{"id":1246343443,"first_name":"room_manager_bot","username":"room_manager_bot"},"status":"member"}}',
	'response' => array( 'code' => 200, 'message' => 'OK' ),
);
$GLOBALS['bale_mock_http_last_request'] = null;

$member_result = $client->getChatMember( '-100987654321', 1246343443 );
expect_true( ! is_wp_error( $member_result ), 'getChatMember should not return error on ok:true' );
expect_equals( $member_result['status'], 'member', 'getChatMember result.status mismatch' );

$last_req = $GLOBALS['bale_mock_http_last_request'];
expect_equals( $last_req['url'], 'https://tapi.bale.ai/bot123456789:test_token_for_mocking/getChatMember', 'getChatMember URL mismatch' );
expect_equals( $last_req['args']['headers']['Content-Type'], 'application/json', 'getChatMember should use JSON content-type' );
$decoded_body = json_decode( $last_req['args']['body'], true );
expect_equals( $decoded_body['chat_id'], '-100987654321', 'getChatMember body chat_id mismatch' );
expect_equals( $decoded_body['user_id'], 1246343443, 'getChatMember body user_id mismatch' );

// Non-numeric user_id must be rejected before any HTTP call.
$GLOBALS['bale_mock_http_last_request'] = null;
$bad_member_result = $client->getChatMember( '-100987654321', 'not-a-user-id' );
expect_true( is_wp_error( $bad_member_result ), 'getChatMember should reject non-numeric user_id' );
expect_equals( $bad_member_result->get_error_code(), 'invalid_user_id', 'getChatMember invalid user_id code mismatch' );
expect_true( null === $GLOBALS['bale_mock_http_last_request'], 'getChatMember must not hit HTTP for invalid user_id' );

// API failure (user not found) must normalize to WP_Error via build_wp_error().
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":false,"error_code":400,"description":"Bad Request: user not found"}',
	'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
);
$member_fail = $client->getChatMember( '-100987654321', 1246343443 );
expect_true( is_wp_error( $member_fail ), 'getChatMember should return WP_Error when user not found' );
expect_equals( $member_fail->get_error_message(), 'Bad Request: user not found', 'getChatMember error description mismatch' );
echo "[PASS] getChatMember(): request shape, user_id validation & error normalization verified.\n";

// Test 8i-3: group recipient where bot IS a member (getChat + getChatMember both ok)
// NOTE: test_connection() signature gains $recipient_type; existing user-type calls remain unchanged.
$group_rec_id2 = Bale_Recipients::add( array(
	'label'   => 'Dev Group Readded',
	'chat_id' => '987654321',
	'type'    => 'group',
) );
expect_true( is_int( $group_rec_id2 ) && $group_rec_id2 > 0, 'Re-adding group recipient should return valid integer ID' );

$GLOBALS['bale_mock_http_next_response'] = null;
// Queue: 1) getChat  2) getMe (bot identity, cached afterwards)  3) getChatMember.
$GLOBALS['bale_mock_http_queue'] = array(
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":true,"result":{"id":987654321,"type":"group","title":"Dev Group"}}',
		'response' => array( 'code' => 200, 'message' => 'OK' ),
	),
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":true,"result":{"id":1246343443,"first_name":"room_manager_bot","username":"room_manager_bot"}}',
		'response' => array( 'code' => 200, 'message' => 'OK' ),
	),
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":true,"result":{"user":{"id":1246343443,"first_name":"room_manager_bot","username":"room_manager_bot"},"status":"member"}}',
		'response' => array( 'code' => 200, 'message' => 'OK' ),
	),
);
$GLOBALS['bale_mock_http_last_request'] = null;

// Exactly 3 HTTP calls expected for this test: getChat, getMe, getChatMember.
$calls_before_group_test = count( $GLOBALS['bale_mock_http_calls'] );
$group_success = Bale_Recipients::test_connection( '987654321', $group_rec_id2, 'group' );
expect_true( ! is_wp_error( $group_success ), 'group test_connection should succeed when bot is a member' );
expect_equals( $group_success['title'], 'Dev Group', 'group test_connection chat title mismatch' );

$group_flow_calls = array_slice( $GLOBALS['bale_mock_http_calls'], $calls_before_group_test );
expect_equals( count( $group_flow_calls ), 3, 'group success flow should make exactly 3 HTTP calls (getChat, getMe, getChatMember)' );
$first_call_url  = $group_flow_calls[0]['url'];
$second_call_url = $group_flow_calls[1]['url'];
$third_call_url  = $group_flow_calls[2]['url'];
expect_true( false !== strpos( $first_call_url, '/getChat' ), 'first call must be getChat' );
expect_true( false !== strpos( $second_call_url, '/getMe' ), 'second call must be getMe' );
expect_true( false !== strpos( $third_call_url, '/getChatMember' ), 'third call must be getChatMember' );
$third_call_body = json_decode( $group_flow_calls[2]['args']['body'], true );
expect_equals( $third_call_body['user_id'], 1246343443, 'getChatMember must be called with bot own user_id from getMe' );

$updated_group_success = Bale_Recipients::get( $group_rec_id2 );
expect_equals( $updated_group_success['last_test_status'], 'success', 'group success should record last_test_status as success' );
echo "[PASS] test_connection(): group/channel with bot as member — getChat + getMe (cached) + getChatMember all pass.\n";

// Test 8i-4: getMe() is cached per request — a second group test in the same request
// must NOT call getMe again (2 HTTP calls: getChat + getChatMember).
$GLOBALS['bale_mock_http_queue'] = array(
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":true,"result":{"id":987654321,"type":"group","title":"Dev Group"}}',
		'response' => array( 'code' => 200, 'message' => 'OK' ),
	),
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":true,"result":{"user":{"id":1246343443,"first_name":"room_manager_bot","username":"room_manager_bot"},"status":"member"}}',
		'response' => array( 'code' => 200, 'message' => 'OK' ),
	),
);
$GLOBALS['bale_mock_http_last_request'] = null;

$group_success_cached = Bale_Recipients::test_connection( '987654321', $group_rec_id2, 'group' );
expect_true( ! is_wp_error( $group_success_cached ), 'second group test_connection should succeed with cached bot id' );
$cached_flow_calls = array_slice( $GLOBALS['bale_mock_http_calls'], $calls_before_group_test + 3 );
expect_equals( count( $cached_flow_calls ), 2, 'cached flow should make exactly 2 HTTP calls (no second getMe)' );
expect_true( false === strpos( $cached_flow_calls[0]['url'], '/getMe' ) && false === strpos( $cached_flow_calls[1]['url'], '/getMe' ), 'no getMe call should occur when bot id is cached' );
echo "[PASS] test_connection(): bot user_id from getMe() cached for the request duration (no duplicate getMe).\n";

// Test 8i-5: group recipient where bot is NOT a member (getChat ok, getChatMember fails)
$GLOBALS['bale_mock_http_queue'] = array(
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":true,"result":{"id":987654321,"type":"group","title":"Dev Group"}}',
		'response' => array( 'code' => 200, 'message' => 'OK' ),
	),
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":false,"error_code":400,"description":"Bad Request: participant user is not found"}',
		'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
	),
);
$GLOBALS['bale_mock_http_last_request'] = null;

$group_not_member = Bale_Recipients::test_connection( '987654321', $group_rec_id2, 'group' );
expect_true( is_wp_error( $group_not_member ), 'group test_connection should fail when bot is not a member' );
expect_equals( $group_not_member->get_error_code(), 'bale_bot_not_member', 'bot-not-member error code mismatch' );
expect_equals(
	$group_not_member->get_error_message(),
	'This chat_id is valid, but the bot is not currently a member of this group/channel — please add it first.',
	'bot-not-member error message mismatch'
);

$updated_group_fail = Bale_Recipients::get( $group_rec_id2 );
expect_equals( $updated_group_fail['last_test_status'], 'failed', 'bot-not-member should record last_test_status as failed' );
echo "[PASS] test_connection(): chat valid but bot not a member returns distinct actionable error.\n";

// Test 8i-6: channel recipient behaves the same as group (membership check enforced).
$GLOBALS['bale_mock_http_queue'] = array(
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":true,"result":{"id":"@bale_news_channel","type":"channel","title":"News Channel","username":"bale_news_channel"}}',
		'response' => array( 'code' => 200, 'message' => 'OK' ),
	),
	array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => '{"ok":false,"error_code":400,"description":"Bad Request: participant user is not found"}',
		'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
	),
);

$channel_not_member = Bale_Recipients::test_connection( '@bale_news_channel', $channel_rec_id, 'channel' );
expect_true( is_wp_error( $channel_not_member ), 'channel test_connection should enforce bot membership too' );
expect_equals( $channel_not_member->get_error_code(), 'bale_bot_not_member', 'channel bot-not-member error code mismatch' );
echo "[PASS] test_connection(): channel type enforces bot membership identically to group.\n";

// Test 8i-7: user-type behavior is unchanged — getChat-only, no getMe/getChatMember calls.
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":true,"result":{"id":1246343444,"type":"private","first_name":"Sobhan","username":"sobhan_dev"}}',
	'response' => array( 'code' => 200, 'message' => 'OK' ),
);
$calls_before_user_test = count( $GLOBALS['bale_mock_http_calls'] );
$GLOBALS['bale_mock_http_last_request'] = null;

$user_success = Bale_Recipients::test_connection( '1246343444', $user_rec_id, 'user' );
expect_true( ! is_wp_error( $user_success ), 'user test_connection should succeed on ok:true' );
expect_equals( $user_success['username'], 'sobhan_dev', 'user test_connection username mismatch' );

$calls_after_user_test = array_slice( $GLOBALS['bale_mock_http_calls'], $calls_before_user_test );
expect_equals( count( $calls_after_user_test ), 1, 'user type must make exactly one HTTP call (getChat only)' );
expect_true( false !== strpos( $calls_after_user_test[0]['url'], '/getChat' ), 'user type call must be getChat' );
$updated_user_unchanged = Bale_Recipients::get( $user_rec_id );
expect_equals( $updated_user_unchanged['last_test_status'], 'success', 'user type success should record last_test_status as success' );
echo "[PASS] test_connection(): user-type behavior unchanged — getChat-only, no membership check.\n";

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
$GLOBALS['throw_on_json_response'] = false;
echo "[PASS] AJAX error paths & explicit return guarantees verified.\n";

// ==========================================
// Phase 4: CF7 Integration Tests
// ==========================================
echo "\n--- Phase 4: CF7 Integration Tests ---\n";

// Action Scheduler stub: capture as_schedule_single_action calls without
// loading the full library. Tests assert scheduling, delays and retries.
$GLOBALS['bale_mock_as_actions'] = array();
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	$GLOBALS['bale_mock_as_actions'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
		'group'     => $group,
	);
	return 12345 + count( $GLOBALS['bale_mock_as_actions'] );
}
function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	return false;
}

// Reset DB mock for Phase 4 tests
$wpdb_mock_p4 = new MockFullWPDB();
$GLOBALS['wpdb'] = $wpdb_mock;

// Load Phase 4 classes that are not loaded through bale-connector.php
// during the test run (CF7 is absent, so register_hooks() returns early).
require_once dirname( __DIR__ ) . '/includes/class-bale-cf7-form-settings.php';

// Test 9a: Bale_Template escaping — submitted values must render as literal
// plain text, never as Bale formatting. THE core security requirement.
$injection = '[Free iPhone](https://evil.example)';
$rendered  = Bale_Template::render( 'Contact: [your-name]', array( 'your-name' => $injection ) );
expect_true(
	false === strpos( $rendered, '](https://evil.example)' ),
	'escape must break the [text](url) construct'
);
expect_true(
	false !== strpos( $rendered, "[\xE2\x80\x8BFree iPhone]\xE2\x80\x8B(\xE2\x80\x8Bhttps://evil.example)" ),
	'every formatting char must be followed by a zero-width space'
);
echo "[PASS] Template: [text](url) injection in field value renders as literal text.\n";

// Test 9b: every Bale formatting character is escaped in submitted values.
foreach ( array( '*', '_', '[', ']', '(', ')' ) as $char ) {
	$escaped = Bale_Template::escape_bale_markup( 'a' . $char . 'b' );
	expect_equals(
		$escaped,
		'a' . $char . "\xE2\x80\x8B" . 'b',
		"character $char must be escaped with trailing ZWSP"
	);
}
echo "[PASS] Template: all six Bale formatting characters escaped in submitted values.\n";

// Test 9c: admin template formatting is PRESERVED (not escaped) while field
// values inside the same message are escaped.
$rendered = Bale_Template::render(
	'*' . "Important" . '* [your-name] _note_',
	array( 'your-name' => 'Reza *Star*' ),
	array()
);
expect_true( 0 === strpos( $rendered, '*Important*' ), 'admin-authored bold must survive rendering' );
expect_true( false !== strpos( $rendered, '_note_' ), 'admin-authored italic must survive rendering' );
expect_true( false !== strpos( $rendered, 'Reza *' . "\xE2\x80\x8B" . 'Star*' . "\xE2\x80\x8B" ), 'submitted value inside same message must stay escaped' );
echo "[PASS] Template: admin formatting preserved, submitted values escaped in the same message.\n";

// Test 9d: array field values are joined, unknown tags render as empty.
$rendered = Bale_Template::render(
	'A=[checkbox-1] B=[unknown-tag] C=[your-name]',
	array( 'your-name' => 'X', 'checkbox-1' => array( 'one', 'two' ) ),
	array()
);
expect_equals( $rendered, 'A=one, two B= C=X', 'array values joined; unknown tags empty' );
echo "[PASS] Template: array values joined with ', ' and unknown tags render empty.\n";

// Test 9e: form-title / form-id come from the trusted extra_tags map and are NOT escaped.
$rendered = Bale_Template::render(
	'*[form-title]* ([form-id]) — [your-name]',
	array( 'your-name' => 'Sara' ),
	array( 'form-title' => 'Contact Us', 'form-id' => '42' )
);
expect_equals( $rendered, '*Contact Us* (42) — Sara', 'trusted extra tags must substitute unescaped' );
echo "[PASS] Template: trusted [form-title]/[form-id] tags render unescaped.\n";

// Test 9f: [tag:limit] truncation.
$rendered = Bale_Template::render(
	'[your-message:5]',
	array( 'your-message' => 'abcdefghijk' ),
	array()
);
expect_equals( $rendered, 'abcde', 'length-limited tag must truncate the value' );
echo "[PASS] Template: [tag:limit] truncation verified.\n";

// Test 9g: Bale_CF7_Form_Settings::sanitize_template preserves newlines and
// Bale formatting chars (they belong to the admin template).
$sanitized = Bale_CF7_Form_Settings::sanitize_template( "*bold* _it_ [link](https://x.ir)\nline two <script>alert(1)</script>" );
expect_true( false !== strpos( $sanitized, '*bold* _it_ [link](https://x.ir)' ), 'admin formatting must survive sanitize_template' );
expect_true( false !== strpos( $sanitized, "\n" ), 'newlines must survive template sanitization' );
expect_true( false === strpos( $sanitized, '<script>' ), 'HTML tags must be stripped from template' );
echo "[PASS] Form settings: template sanitization keeps Bale formatting, strips HTML.\n";

// Test 9h: save + read back form settings (upsert semantics).
expect_true( true === Bale_CF7_Integration::save_form_settings( 42, array(
	'enabled'          => true,
	'recipient_ids'    => array( 7, 8 ),
	'message_template' => "*New lead*\nName: [your-name]",
) ), 'save_form_settings insert should succeed' );

$row = $wpdb_mock->get_row( "SELECT enabled, recipient_ids, message_template FROM wp_bale_connector_form_settings WHERE form_type = 'cf7' AND form_id = 42", 'ARRAY_A' );
expect_true( is_array( $row ), 'form settings row should exist after save' );
expect_equals( $row['recipient_ids'], '[7,8]', 'recipient_ids must be stored as normalized JSON' );

$settings = Bale_CF7_Integration::get_form_settings( 42 );
expect_true( is_array( $settings ) && 1 === (int) $settings['enabled'], 'get_form_settings should return enabled=1' );
expect_equals( $settings['recipient_ids'], array( 7, 8 ), 'recipient_ids roundtrip' );

// Upsert: saving again must not create a duplicate row.
expect_true( true === Bale_CF7_Integration::save_form_settings( 42, array(
	'enabled'          => 0,
	'recipient_ids'    => array( 7 ),
	'message_template' => 'plain',
) ), 'save_form_settings update should succeed' );
$updated = Bale_CF7_Integration::get_form_settings( 42 );
expect_equals( $updated['enabled'], 0, 'second save must update the same row' );
echo "[PASS] Form settings: upsert round-trip via wp_bale_connector_form_settings verified.\n";

// Test 9i: bale_connector_log() writes rows into the logs table.
$log_id = bale_connector_log( array(
	'source_type'       => 'cf7',
	'source_ref'        => '42',
	'recipient_chat_id' => '1246343443',
	'payload'           => array( 'text' => 'hello' ),
	'response'          => array( 'message_id' => 7 ),
	'status'            => 'success',
) );
expect_true( is_int( $log_id ) && $log_id > 0, 'bale_connector_log should return inserted ID' );

$failed_log = bale_connector_log( array(
	'source_type'       => 'cf7',
	'source_ref'        => '42',
	'recipient_chat_id' => '1246343443',
	'payload'           => array( 'text' => 'x' ),
	'status'            => 'failed',
) );
expect_true( is_int( $failed_log ) && $failed_log > 0, 'failed log entry should also insert' );
echo "[PASS] bale_connector_log(): success and failed entries written to logs table.\n";

// Test 9j: unknown source_type rejected; registered trigger accepted.
$unknown = bale_connector_log( array(
	'source_type'       => 'woocommerce_order',
	'recipient_chat_id' => '123',
	'payload'           => 'x',
	'status'            => 'failed',
) );
expect_true( is_wp_error( $unknown ), 'unregistered source_type must be rejected' );
expect_equals( $unknown->get_error_code(), 'bale_log_unknown_source_type', 'unknown source_type error code mismatch' );

$reg = bale_connector_register_trigger( 'woocommerce_order', array( 'label' => 'WooCommerce Orders' ) );
expect_true( true === $reg, 'register_trigger should return true' );
$pro_log_id = bale_connector_log( array(
	'source_type'       => 'woocommerce_order',
	'source_ref'        => '555',
	'recipient_chat_id' => '1246343443',
	'payload'           => array( 'order' => 555 ),
	'status'            => 'success',
) );
expect_true( is_int( $pro_log_id ) && $pro_log_id > 0, 'registered trigger should be able to log' );

$dup = bale_connector_register_trigger( 'woocommerce_order', array( 'label' => 'Dup' ) );
expect_true( is_wp_error( $dup ) && 'bale_trigger_already_registered' === $dup->get_error_code(), 'duplicate trigger registration must fail' );
echo "[PASS] Extension points: bale_connector_register_trigger() + bale_connector_log() verified.\n";

// Test 9k: Bale_CF7_Integration::action_send — full mocked flow.
$GLOBALS['bale_mock_as_actions'] = array();
$wpdb_mock->queries = array();

$cf7 = new Bale_CF7_Integration();

// Save settings for form 77 and add a matching recipient.
// (Recipient row IDs 1 and 2 already exist from Phase 3 tests: 1 = 'Updated
// Admin User' with chat_id 1246343444, 2 = 'News Channel'.)
Bale_Recipients::add( array( 'label' => 'Owner DM', 'chat_id' => '1246343443', 'type' => 'user' ) );
$owner_recipient_id = $GLOBALS['wpdb']->insert_id;
Bale_CF7_Integration::save_form_settings( 77, array(
	'enabled'          => 1,
	'recipient_ids'    => array( $owner_recipient_id ),
	'message_template' => '*Form: [form-id]*' . "\n" . 'Name: [your-name]' . "\n" . 'Message: [your-message]',
) );

$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":true,"result":{"message_id":77,"chat":{"id":1246343443,"type":"private"}}}',
	'response' => array( 'code' => 200, 'message' => 'OK' ),
);
$GLOBALS['bale_mock_http_last_request'] = null;

$cf7->action_send( array(
	'form_id'       => 77,
	'posted_data'   => array(
		'your-name'    => 'Malicious [User](https://ph.example)',
		'your-message' => 'hello *world* _under_ (paren) [bracket]',
	),
	'recipient_ids' => array( $owner_recipient_id ),
	'retries'       => 0,
) );

$last_send = $GLOBALS['bale_mock_http_last_request'];
expect_true( false !== strpos( $last_send['url'], '/sendMessage' ), 'action_send must call sendMessage' );
$sent_body = json_decode( $last_send['args']['body'], true );
expect_equals( $sent_body['chat_id'], '1246343443', 'sendMessage chat_id mismatch' );
expect_true(
	false === strpos( $sent_body['text'], '[Malicious User](https://ph.example)' ),
	'phishing link construct must NOT appear unescaped in the sent text'
);
expect_true(
	false !== strpos( $sent_body['text'], "[\xE2\x80\x8BUser]\xE2\x80\x8B(\xE2\x80\x8Bhttps://ph.example)" ),
	'sent text must contain ZWSP-escaped field value'
);
expect_true(
	false !== strpos( $sent_body['text'], '*Form: 77*' ),
	'admin template formatting must render with trusted form-id tag'
);

// One log row per recipient send (filter by the source table, not global count).
$log_rows = $wpdb_mock->get_results( "SELECT * FROM wp_bale_connector_logs", 'ARRAY_A' );
expect_true( count( $log_rows ) >= 1, 'at least one log row must be written for a successful send' );
$success_rows = array_filter( $log_rows, function ( $r ) { return 'success' === $r['status']; } );
expect_true( count( $success_rows ) >= 1, 'log row for the successful send should have status=success' );
expect_true( false !== strpos( $log_rows[0]['recipient_chat_id'], '1246343443' ) || false !== strpos( end( $log_rows )['recipient_chat_id'], '1246343443' ), 'log row should record the recipient chat_id' );
echo "[PASS] action_send(): message delivered with escaped field values and logged.\n";

// Test 9i-2: handle_form_submission schedules via Action Scheduler.
// handle_form_submission needs WPCF7 classes — emulate a minimal form object.
class MockWPCF7Form {
	public $form_id;
	public function __construct( $id ) { $this->form_id = $id; }
	public function id() { return $this->form_id; }
}
class MockWPCF7Submission {
	public static $instance = null;
	public static function get_instance() { return self::$instance ? self::$instance : null; }
	public function get_posted_data() { return array( 'your-name' => 'T' ); }
}

// Without CF7 constants, handle_form_submission() returns before any
// scheduling (register_hooks() early-returns when CF7 is absent), so we
// verify schedule_send indirectly through action_send's retry path instead.

// Test 9j-2: retry backoff — transient API failure reschedules with delay.
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":false,"error_code":429,"description":"Too Many Requests","parameters":{"retry_after":90}}',
	'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ),
);
$actions_before = count( $GLOBALS['bale_mock_as_actions'] );

$cf7->action_send( array(
	'form_id'       => 77,
	'posted_data'   => array( 'your-name' => 'T' ),
	'recipient_ids' => array( $owner_recipient_id ),
	'retries'       => 0,
) );

$pending_actions = array_slice( $GLOBALS['bale_mock_as_actions'], $actions_before );
expect_true( count( $pending_actions ) >= 1, 'failed send must schedule a retry action' );
$retry_args = $pending_actions[0]['args'][0];
expect_equals( $retry_args['retries'], 1, 'retry must increment retries counter' );
expect_true(
	$pending_actions[0]['timestamp'] > time() && $pending_actions[0]['timestamp'] <= time() + 91,
	'retry delay must honor retry_after (90s)'
);
echo "[PASS] schedule_retry(): transient failure reschedules with retry_after honored.\n";

// Test 9k-2: permanent failure — non-transient API errors still retry with
// backoff, but only up to MAX_RETRIES.
$GLOBALS['bale_mock_http_next_response'] = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}',
	'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
);
$actions_before = count( $GLOBALS['bale_mock_as_actions'] );
$cf7->action_send( array( 'form_id' => 77, 'posted_data' => array(), 'recipient_ids' => array( $owner_recipient_id ), 'retries' => 3 ) );
$after = array_slice( $GLOBALS['bale_mock_as_actions'], $actions_before );
expect_equals( count( $after ), 0, 'no retry may be scheduled after MAX_RETRIES is reached' );
echo "[PASS] schedule_retry(): gives up after MAX_RETRIES retries.\n";

// Test 9l: extension-point render helper stays consistent with the engine.
$via_helper = bale_connector_render_template( 'Hi [your-name]', array( 'your-name' => 'Ali *_[]() Sadeghi' ) );
$direct     = Bale_Template::render( 'Hi [your-name]', array( 'your-name' => 'Ali *_[]() Sadeghi' ) );
expect_equals( $via_helper, $direct, 'bale_connector_render_template() must match Bale_Template::render()' );
echo "[PASS] bale_connector_render_template() extension point verified.\n";

echo "ALL TESTS PASSED SUCCESSFULLY on PHP " . PHP_VERSION . "!\n";
