<?php
/**
 * Installer and database schema manager for Bale Connector.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_Installer {

	const DB_VERSION = '1.0.0';

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		global $wp_version;

		if ( version_compare( PHP_VERSION, BALE_CONNECTOR_MIN_PHP_VERSION, '<' ) || version_compare( $wp_version, BALE_CONNECTOR_MIN_WP_VERSION, '<' ) ) {
			deactivate_plugins( BALE_CONNECTOR_PLUGIN_BASENAME );
			$message = sprintf(
				/* translators: 1: Minimum PHP version, 2: Current PHP version, 3: Minimum WP version, 4: Current WP version */
				__( 'Bale Connector requires PHP %1$s+ (current: %2$s) and WordPress %3$s+ (current: %4$s).', 'bale-connector' ),
				BALE_CONNECTOR_MIN_PHP_VERSION,
				PHP_VERSION,
				BALE_CONNECTOR_MIN_WP_VERSION,
				$wp_version
			);
			wp_die( esc_html( $message ), esc_html__( 'Plugin Activation Error', 'bale-connector' ), array( 'back_link' => true ) );
		}

		self::create_tables();
		self::set_default_options();
		Bale_Security::ensure_encryption_key();
		update_option( 'bale_connector_db_version', self::DB_VERSION );
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		// No-op for deactivation to preserve data between toggles.
	}

	/**
	 * Create or update custom database tables using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$recipients_table    = $wpdb->prefix . 'bale_connector_recipients';
		$logs_table          = $wpdb->prefix . 'bale_connector_logs';
		$form_settings_table = $wpdb->prefix . 'bale_connector_form_settings';

		$sql = "CREATE TABLE {$recipients_table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(255) NOT NULL,
  chat_id VARCHAR(255) NOT NULL,
  type ENUM('user','group','channel') NOT NULL,
  last_tested_at DATETIME NULL,
  last_test_status ENUM('success','failed') NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id)
) {$charset_collate};

CREATE TABLE {$logs_table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_type VARCHAR(50) NOT NULL,
  source_ref VARCHAR(255) NULL,
  recipient_chat_id VARCHAR(255) NOT NULL,
  payload TEXT NOT NULL,
  response TEXT NULL,
  status ENUM('success','failed') NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id)
) {$charset_collate};

CREATE TABLE {$form_settings_table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_type VARCHAR(50) NOT NULL,
  form_id VARCHAR(255) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  recipient_ids TEXT NOT NULL,
  message_template TEXT NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY form_lookup (form_type, form_id)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Set default options if not already present.
	 */
	private static function set_default_options() {
		if ( false === get_option( 'bale_connector_keep_data_on_uninstall' ) ) {
			add_option( 'bale_connector_keep_data_on_uninstall', '1' );
		}
	}
}
