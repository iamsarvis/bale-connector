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

echo "ALL TESTS PASSED SUCCESSFULLY on PHP " . PHP_VERSION . "!\n";
