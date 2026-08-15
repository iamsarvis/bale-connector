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
 * Initialize plugin.
 */
function bale_connector_init() {
	load_plugin_textdomain( 'bale-connector', false, dirname( BALE_CONNECTOR_PLUGIN_BASENAME ) . '/languages/' );

	if ( is_admin() ) {
		$admin = new Bale_Admin();
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'bale_connector_init' );
