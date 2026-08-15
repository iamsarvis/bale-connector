<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$keep_data = get_option( 'bale_connector_keep_data_on_uninstall', '1' );

if ( '1' !== $keep_data ) {
	global $wpdb;

	// Drop custom tables
	$recipients_table    = $wpdb->prefix . 'bale_connector_recipients';
	$logs_table          = $wpdb->prefix . 'bale_connector_logs';
	$form_settings_table = $wpdb->prefix . 'bale_connector_form_settings';

	$wpdb->query( "DROP TABLE IF EXISTS {$recipients_table}" );
	$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
	$wpdb->query( "DROP TABLE IF EXISTS {$form_settings_table}" );

	// Delete options
	delete_option( 'bale_connector_bot_token_enc' );
	delete_option( 'bale_connector_encryption_key' );
	delete_option( 'bale_connector_keep_data_on_uninstall' );
	delete_option( 'bale_connector_db_version' );
}
