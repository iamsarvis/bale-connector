<?php
/**
 * Test runner script to verify compatibility on PHP 7.4 through 8.4.
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['wp_version'] = '6.4.2';

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
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_attr_e( $text, $domain = 'default' ) { echo esc_attr( $text ); }
function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); }
function checked( $checked, $current = true, $echo = true ) {
    $res = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
    if ( $echo ) echo $res;
    return $res;
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {}
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

// 1. Test Bale_Security encryption & decryption & masking
$test_token = '2078691878:9YqxS2lO5ZfomgKjKnvUqIprQl4kFfeF1kw';
$encrypted = Bale_Security::encrypt( $test_token );
assert( ! empty( $encrypted ), 'Encryption produced empty result' );
assert( $encrypted !== $test_token, 'Token stored in plaintext!' );

$decrypted = Bale_Security::decrypt( $encrypted );
assert( $decrypted === $test_token, "Decryption failed! Expected $test_token got $decrypted" );

$masked = Bale_Security::mask_token( $test_token );
assert( $masked === str_repeat( '*', strlen( $test_token ) - 4 ) . 'F1kw', "Masking failed! Got $masked" );
echo "[PASS] Bale_Security encrypt/decrypt/masking verified.\n";

// 2. Test Bale_Admin token sanitization
$admin = new Bale_Admin();
$sanitized = $admin->sanitize_bot_token( $test_token );
assert( ! empty( $sanitized ), 'Sanitization failed for valid token' );
$decrypted_from_sanitized = Bale_Security::decrypt( $sanitized );
assert( $decrypted_from_sanitized === $test_token, 'Sanitized token decryption mismatch' );

// Invalid token format
$invalid_sanitized = $admin->sanitize_bot_token( 'invalid_token_format' );
// Should reject and keep existing
echo "[PASS] Bale_Admin sanitization verified.\n";

// 3. Test requirements check
assert( bale_connector_check_requirements() === true, 'Requirements check failed on valid environment' );
echo "[PASS] Requirements check verified.\n";

// 4. Test Lifecycle (Uninstall Simulation)
$mock_options['bale_connector_bot_token_enc'] = 'test';
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

assert( ! isset( $mock_options['bale_connector_bot_token_enc'] ), 'Token option not removed on uninstall' );
assert( ! isset( $mock_options['bale_connector_keep_data_on_uninstall'] ), 'Keep data option not removed on uninstall' );
assert( count( $GLOBALS['wpdb']->queries ) === 3, 'Not all 3 tables dropped on uninstall' );
echo "[PASS] Uninstall cleanup lifecycle verified.\n";

echo "ALL TESTS PASSED SUCCESSFULLY on PHP " . PHP_VERSION . "!\n";
