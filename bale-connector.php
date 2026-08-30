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
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-recipients.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-logger.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-template.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-cf7-form-settings.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-cf7-integration.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-cf7-admin-panel.php';
require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-admin.php';

define( 'BALE_CONNECTOR_LIB_DIR', BALE_CONNECTOR_PLUGIN_DIR . 'lib/' );

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

	require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-action-scheduler-loader.php';

	// Load the bundled Action Scheduler via the library's own hooked,
	// version-negotiating bootstrap (see class docblock). Safe alongside
	// WooCommerce or a standalone Action Scheduler plugin.
	Bale_Action_Scheduler_Loader::init();

	$cf7_integration = new Bale_CF7_Integration();
	$cf7_integration->register();

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
 * Get the saved recipients list.
 *
 * Extension point for bale-connector-pro (AGENTS.md §7).
 *
 * @param array $args Optional query arguments (orderby, order, limit, offset).
 * @return array Array of recipient data rows.
 */
function bale_connector_recipients( $args = array() ) {
	$recipients = Bale_Recipients::get_all( $args );

	/**
	 * Filter the recipients list.
	 *
	 * @param array $recipients Array of recipients.
	 * @param array $args       Query arguments.
	 */
	return apply_filters( 'bale_connector_recipients', $recipients, $args );
}

/**
 * Register a new trigger type (AGENTS.md §7 extension point).
 *
 * Lets bale-connector-pro add-ons register a new trigger type (order status,
 * OTP, etc.) into the shared admin UI and the shared log table, without
 * modifying this repo's code.
 *
 * @param string $slug Unique trigger slug (becomes the log source_type).
 * @param array  $args Trigger definition (label, callback, source_ref).
 * @return true|WP_Error True on success, WP_Error on invalid input.
 */
function bale_connector_register_trigger( $slug, $args ) {
	/**
	 * Filter a trigger registration before it is stored.
	 *
	 * @param true|WP_Error $result Registration result.
	 * @param string        $slug   Trigger slug.
	 * @param array         $args   Trigger arguments.
	 */
	$result = Bale_Logger::register_trigger( $slug, $args );

	return apply_filters( 'bale_connector_register_trigger_result', $result, $slug, $args );
}

/**
 * Write an entry into the shared logs table.
 *
 * Extension point for bale-connector-pro (AGENTS.md §7): the single write
 * path into the logs table, so Pro triggers reuse the exact same log/report
 * UI as CF7 does.
 *
 * @param array $entry {
 *     Log entry.
 *
 *     @type string $source_type       'cf7' or a registered trigger slug.
 *     @type string $source_ref        Optional. Form ID, order ID, etc.
 *     @type string $recipient_chat_id Target chat ID.
 *     @type mixed  $payload           JSON-encodable data that was sent.
 *     @type mixed  $response          Optional. JSON-encodable response data.
 *     @type string $status            'success' or 'failed'.
 * }
 * @return int|WP_Error Inserted log ID on success, WP_Error on failure.
 */
function bale_connector_log( $entry ) {
	/**
	 * Filter a log entry before it is written.
	 *
	 * @param array $entry Log entry (source_type, source_ref,
	 *                     recipient_chat_id, payload, response, status).
	 */
	$entry = apply_filters( 'bale_connector_log_entry', $entry );

	return Bale_Logger::log( $entry );
}

/**
 * Render a message template with submitted field values.
 *
 * Public so Pro form-builder add-ons reuse the exact Phase 4 template and
 * escaping engine. Submitted values are always escaped to literal plain
 * text; only admin-authored template text may use Bale formatting.
 *
 * @param string $template     Admin-authored template with [tags].
 * @param array  $field_values Submitted field values (tag => value).
 * @param array  $extra_tags   Optional. Trusted site-owner tag values.
 * @return string Rendered message text.
 */
function bale_connector_render_template( $template, $field_values, $extra_tags = array() ) {
	return Bale_Template::render( $template, $field_values, $extra_tags );
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
