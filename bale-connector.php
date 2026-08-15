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
