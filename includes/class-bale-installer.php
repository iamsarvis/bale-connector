<?php
/**
 * Installer and database schema manager for Bale Connector.
 *
 * Handles both fresh installs (dbDelta on activation) and upgrades of
 * already-installed sites via a stored `bale_connector_db_version` option
 * compared against the DB_VERSION constant. Index-only schema changes are
 * applied explicitly with ALTER TABLE, because dbDelta does not reliably
 * apply them.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_Installer {

	/**
	 * Current schema version. Bump whenever create_tables() changes.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.1.0';

	/**
	 * Option name holding the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'bale_connector_db_version';

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
		self::ensure_log_indexes();
		self::set_default_options();
		Bale_Security::ensure_encryption_key();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		// No-op for deactivation to preserve data between toggles.
	}

	/**
	 * Bring an already-installed site up to the current schema version.
	 *
	 * Compares the stored `bale_connector_db_version` option against the
	 * DB_VERSION constant. On mismatch (or missing option — e.g. installs
	 * that predate version tracking) the schema is re-applied: dbDelta runs
	 * the full CREATE TABLE set, then the logs-table indexes are ensured
	 * explicitly with ALTER TABLE (dbDelta does not reliably add indexes).
	 * Idempotent: a second run with matching versions is a no-op.
	 *
	 * Hooked to admin_init so the first admin page load after a plugin
	 * update performs the migration.
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( self::DB_VERSION_OPTION, '' );

		if ( self::DB_VERSION === $installed_version ) {
			return false; // Already current — nothing to do.
		}

		self::create_tables();
		self::ensure_log_indexes();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		return true;
	}

	/**
	 * Apply the schema with dbDelta.
	 *
	 * dbDelta() is defined in wp-admin/includes/upgrade.php, which is NOT
	 * loaded on every admin page — admin_init fires everywhere but that
	 * include is not guaranteed. Always require it explicitly right before
	 * use instead of relying on a function_exists() guard alone.
	 *
	 * @return array|false dbDelta() results, or false when unavailable.
	 */
	private static function run_db_delta( $sql ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( ! function_exists( 'dbDelta' ) ) {
			// Still missing after the require (unusual installs): fail soft.
			error_log( 'Bale Connector: dbDelta() is unavailable after requiring wp-admin/includes/upgrade.php.' );
			return false;
		}

		return dbDelta( $sql );
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
  PRIMARY KEY  (id),
  KEY source_type (source_type),
  KEY created_at (created_at),
  KEY status_created (status, created_at)
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

		self::run_db_delta( $sql );
	}

	/**
	 * Ensure the logs-table performance indexes exist.
	 *
	 * dbDelta cannot be relied upon for index-only changes on existing
	 * tables, so the indexes are checked with SHOW INDEX and added with
	 * ALTER TABLE when missing. Every statement is idempotent and wrapped
	 * so a broken migration never fatals an admin page load.
	 */
	public static function ensure_log_indexes() {
		global $wpdb;

		$logs_table = $wpdb->prefix . 'bale_connector_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name built from $wpdb->prefix only.
		$existing = $wpdb->get_results( "SHOW INDEX FROM {$logs_table}", ARRAY_A );

		$existing_names = array();
		if ( is_array( $existing ) ) {
			foreach ( $existing as $row ) {
				if ( isset( $row['Key_name'] ) ) {
					$existing_names[] = (string) $row['Key_name'];
				}
			}
		} else {
			// SHOW INDEX failed (permissions/table missing): nothing to do here.
			error_log( 'Bale Connector: SHOW INDEX failed for ' . $logs_table . ' — skipping index migration check.' );
			return;
		}

		$needed = array(
			'source_type'    => "ALTER TABLE {$logs_table} ADD INDEX source_type (source_type)",
			'created_at'     => "ALTER TABLE {$logs_table} ADD INDEX created_at (created_at)",
			'status_created' => "ALTER TABLE {$logs_table} ADD INDEX status_created (status, created_at)",
		);

		foreach ( $needed as $name => $statement ) {
			if ( ! in_array( $name, $existing_names, true ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/index names are code-defined, not user input.
				$wpdb->query( $statement );
			}
		}
	}

	/**
	 * Set default options if not already present.
	 */
	private static function set_default_options() {
		add_option( 'bale_connector_bot_token_enc', '', '', false );
		add_option( 'bale_connector_log_level', 'all' );
		add_option( 'bale_connector_log_retention_mb', 5 );

		if ( false === get_option( 'bale_connector_keep_data_on_uninstall' ) ) {
			add_option( 'bale_connector_keep_data_on_uninstall', '1' );
		}
	}
}
