<?php
/**
 * Plugin Name:       Bale Connector
 * Plugin URI:        https://wordpress.org/plugins/bale-connector/
 * Description:       Bridges WordPress and Contact Form 7 to Bale Messenger Bot API.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Sobhan
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bale-connector
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BALE_CONNECTOR_VERSION', '1.0.0' );
define( 'BALE_CONNECTOR_MIN_PHP_VERSION', '7.4' );
define( 'BALE_CONNECTOR_MIN_WP_VERSION', '6.0' );
define( 'BALE_CONNECTOR_PLUGIN_FILE', __FILE__ );
define( 'BALE_CONNECTOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BALE_CONNECTOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BALE_CONNECTOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-installer.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-security.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-api-client.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-admin.php';

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook( __FILE__, array( 'Bale_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Bale_Installer', 'deactivate' ) );

/**
 * Compatibility check and admin notice for unsupported environments.
 *
 * @return bool True if requirements are met, false otherwise.
 */
function bale_connector_check_requirements() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, BALE_CONNECTOR_MIN_PHP_VERSION, '<' ) ) {
		return false;
	}

	if ( version_compare( $wp_version, BALE_CONNECTOR_MIN_WP_VERSION, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Render admin notice when requirements are not met and self-deactivate.
 */
function bale_connector_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$message = sprintf(
		/* translators: 1: Minimum PHP version, 2: Current PHP version, 3: Minimum WP version, 4: Current WP version */
		esc_html__( 'Bale Connector requires PHP %1$s+ (current: %2$s) and WordPress %3$s+ (current: %4$s). The plugin has been deactivated.', 'bale-connector' ),
		BALE_CONNECTOR_MIN_PHP_VERSION,
		PHP_VERSION,
		BALE_CONNECTOR_MIN_WP_VERSION,
		$GLOBALS['wp_version']
	);

	echo '<div class="notice notice-error"><p>' . $message . '</p></div>';

	deactivate_plugins( BALE_CONNECTOR_PLUGIN_BASENAME );

	if ( isset( $_GET['activate'] ) ) {
		unset( $_GET['activate'] );
	}
}

/**
 * Initialize plugin.
 */
function bale_connector_init() {
	if ( ! bale_connector_check_requirements() ) {
		add_action( 'admin_notices', 'bale_connector_requirements_notice' );
		return;
	}

	load_plugin_textdomain( 'bale-connector', false, dirname( BALE_CONNECTOR_PLUGIN_BASENAME ) . '/languages/' );

	if ( is_admin() ) {
		$admin = new Bale_Admin();
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'bale_connector_init' );

/**
 * Get the configured, ready-to-use Bale API client.
 *
 * Extension point for bale-connector-pro (AGENTS.md §7). Decrypts the stored
 * bot token, instantiates Bale_Api_Client, and returns it. Returns WP_Error
 * if no token is configured or the token cannot be decrypted.
 *
 * @return Bale_Api_Client|WP_Error API client instance, or WP_Error on failure.
 */
function bale_connector_get_client() {
	$encrypted_token = get_option( 'bale_connector_bot_token_enc', '' );

	if ( empty( $encrypted_token ) ) {
		return new WP_Error(
			'bale_no_token',
			__( 'No Bale bot token is configured. Please set your token on the Settings page.', 'bale-connector' )
		);
	}

	$token = Bale_Security::decrypt( $encrypted_token );

	if ( null === $token ) {
		return new WP_Error(
			'bale_no_token',
			__( 'No Bale bot token is configured. Please set your token on the Settings page.', 'bale-connector' )
		);
	}

	if ( false === $token ) {
		return new WP_Error(
			'bale_token_decrypt_failed',
			__( 'Unable to decrypt the stored bot token. The encryption key may have changed or been corrupted.', 'bale-connector' )
		);
	}

	return new Bale_Api_Client( $token );
}

/**
 * Register WP-CLI commands for debugging and testing.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'bale', 'Bale_Connector_CLI' );
}

/**
 * WP-CLI commands for the Bale Connector plugin.
 *
 * Usage: wp bale test-connection
 * Acceptance test for Phase 2 — calls getMe() with the stored token.
 */
class Bale_Connector_CLI {

	/**
	 * Test the bot connection by calling getMe().
	 *
	 * ## EXAMPLES
	 *
	 *     wp bale test-connection
	 *
	 * @subcommand test-connection
	 */
	public function test_connection( $args, $assoc_args ) {
		$client = bale_connector_get_client();

		if ( is_wp_error( $client ) ) {
			WP_CLI::error( $client->get_error_message() );
			return;
		}

		$result = $client->getMe();

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$msg  = $result->get_error_message();
			if ( isset( $data['error_code'] ) ) {
				$msg .= ' (error_code: ' . $data['error_code'] . ')';
			}
			WP_CLI::error( $msg );
			return;
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: bot username, 2: bot id, 3: bot first name */
				__( 'Connected to bot @%1$s (id: %2$s, name: %3$s)', 'bale-connector' ),
				isset( $result['username'] ) ? $result['username'] : 'unknown',
				isset( $result['id'] ) ? $result['id'] : 'unknown',
				isset( $result['first_name'] ) ? $result['first_name'] : 'unknown'
			)
		);
	}
}
