<?php
/**
 * Action Scheduler loader.
 *
 * Bundles Action Scheduler (AGENTS.md §3) and loads it via the library's own
 * version-negotiation bootstrap. The bundled action-scheduler.php is itself
 * hooked to 'plugins_loaded' (priority 0) and registers its version with
 * ActionScheduler_Versions, so the NEWEST copy of Action Scheduler present on
 * the site wins — including the one bundled by another plugin (WooCommerce)
 * or installed as a standalone plugin. We never require() a concrete class
 * file directly and never define our own version-constant: that would fight
 * the negotiation layer and can fatal on duplicate class definitions.
 *
 * Safe to call from the top of plugins_loaded (before priority 1) and again
 * later: the register function is guarded inside action-scheduler.php.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_Action_Scheduler_Loader {

	/**
	 * Load the bundled Action Scheduler library.
	 *
	 * Called from plugins_loaded so that (a) WP core functions exist and (b)
	 * any standalone Action Scheduler plugin (which also registers on
	 * plugins_loaded) has had its chance to register a version — the library
	 * then initializes whichever registered version is newest.
	 *
	 * @return void
	 */
	public static function init() {
		if ( defined( 'BALE_CONNECTOR_LIB_DIR' ) ) {
			require_once BALE_CONNECTOR_LIB_DIR . 'action-scheduler/action-scheduler.php';
		}
	}
}
