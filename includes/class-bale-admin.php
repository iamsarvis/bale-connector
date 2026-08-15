<?php
/**
 * Admin menus and settings handling for Bale Connector.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_Admin {

	/**
	 * Initialize admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'check_token_decryption_notice' ) );
	}

	/**
	 * Display admin notice if a stored token cannot be decrypted.
	 */
	public function check_token_decryption_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$encrypted_token = get_option( 'bale_connector_bot_token_enc', '' );
		if ( empty( $encrypted_token ) ) {
			return;
		}

		$decrypted = Bale_Security::decrypt( $encrypted_token );
		if ( false === $decrypted ) {
			$message = sprintf(
				/* translators: %s: URL to the plugin settings page */
				__( 'Bale Connector was unable to decrypt your Bot Token (the encryption key may have changed or corrupted). Please <a href="%s">re-enter your Bot Token</a>.', 'bale-connector' ),
				esc_url( admin_url( 'admin.php?page=bale-connector' ) )
			);
			echo '<div class="notice notice-error"><p>' . wp_kses_post( $message ) . '</p></div>';
		}
	}

	/**
	 * Register admin menu and submenus.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'Bale Connector', 'bale-connector' ),
			__( 'Bale Connector', 'bale-connector' ),
			'manage_options',
			'bale-connector',
			array( $this, 'render_settings_page' ),
			'dashicons-share-alt',
			56
		);

		add_submenu_page(
			'bale-connector',
			__( 'Settings', 'bale-connector' ),
			__( 'Settings', 'bale-connector' ),
			'manage_options',
			'bale-connector',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'bale-connector',
			__( 'Recipients', 'bale-connector' ),
			__( 'Recipients', 'bale-connector' ),
			'manage_options',
			'bale-connector-recipients',
			array( $this, 'render_recipients_page' )
		);

		add_submenu_page(
			'bale-connector',
			__( 'Logs', 'bale-connector' ),
			__( 'Logs', 'bale-connector' ),
			'manage_options',
			'bale-connector-logs',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Register settings using Settings API.
	 */
	public function register_settings() {
		register_setting(
			'bale_connector_settings_group',
			'bale_connector_bot_token_enc',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_bot_token' ),
				'default'           => '',
			)
		);

		register_setting(
			'bale_connector_settings_group',
			'bale_connector_keep_data_on_uninstall',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => '1',
			)
		);

		add_settings_section(
			'bale_connector_general_section',
			__( 'General Settings', 'bale-connector' ),
			array( $this, 'render_general_section_intro' ),
			'bale-connector'
		);

		add_settings_field(
			'bale_connector_bot_token',
			__( 'Bot Token', 'bale-connector' ),
			array( $this, 'render_bot_token_field' ),
			'bale-connector',
			'bale_connector_general_section'
		);

		add_settings_field(
			'bale_connector_keep_data_on_uninstall',
			__( 'Keep Data on Uninstall', 'bale-connector' ),
			array( $this, 'render_keep_data_field' ),
			'bale-connector',
			'bale_connector_general_section'
		);
	}

	/**
	 * Sanitize bot token and encrypt it at rest.
	 *
	 * @param string $input Input token from form.
	 * @return string Encrypted token or existing encrypted token if unchanged.
	 */
	public function sanitize_bot_token( $input ) {
		$raw_token = sanitize_text_field( trim( $input ) );

		// If input is empty, clear the token
		if ( '' === $raw_token ) {
			return '';
		}

		// If user didn't change the masked token (e.g. contains '****'), preserve existing
		if ( false !== strpos( $raw_token, '****' ) ) {
			return get_option( 'bale_connector_bot_token_enc', '' );
		}

		// Local format check: standard bot tokens have format <digits>:<alphanumeric/special>
		if ( ! preg_match( '/^[0-9]+:[A-Za-z0-9_-]+$/', $raw_token ) ) {
			add_settings_error(
				'bale_connector_bot_token_enc',
				'invalid_bot_token_format',
				__( 'Invalid Bot Token format. It typically looks like: 123456789:ABCDefGhIJKlmNoPQRsTUVwxyZ', 'bale-connector' ),
				'error'
			);
			return get_option( 'bale_connector_bot_token_enc', '' );
		}

		// Check crypto support
		if ( ! Bale_Security::has_crypto_support() ) {
			add_settings_error(
				'bale_connector_bot_token_enc',
				'no_crypto_support',
				__( 'No supported cryptographic extension (libsodium or OpenSSL) is available on this server. The token cannot be securely stored.', 'bale-connector' ),
				'error'
			);
			return get_option( 'bale_connector_bot_token_enc', '' );
		}

		$encrypted = Bale_Security::encrypt( $raw_token );
		if ( empty( $encrypted ) ) {
			add_settings_error(
				'bale_connector_bot_token_enc',
				'encryption_failed',
				__( 'Failed to securely encrypt the Bot Token. Please check server permissions or logs.', 'bale-connector' ),
				'error'
			);
			return get_option( 'bale_connector_bot_token_enc', '' );
		}

		return $encrypted;
	}

	/**
	 * Sanitize checkbox values.
	 *
	 * @param mixed $input Input value.
	 * @return string '1' or '0'.
	 */
	public function sanitize_checkbox( $input ) {
		return ! empty( $input ) ? '1' : '0';
	}

	/**
	 * Render general section description.
	 */
	public function render_general_section_intro() {
		echo '<p>' . esc_html__( 'Configure your Bale Bot API connection settings.', 'bale-connector' ) . '</p>';
	}

	/**
	 * Render bot token field.
	 */
	public function render_bot_token_field() {
		$encrypted_token = get_option( 'bale_connector_bot_token_enc', '' );
		$display_val = '';

		if ( ! empty( $encrypted_token ) ) {
			$decrypted = Bale_Security::decrypt( $encrypted_token );
			if ( false !== $decrypted && null !== $decrypted ) {
				$display_val = Bale_Security::mask_token( $decrypted );
			}
		}

		?>
		<input type="text"
			   name="bale_connector_bot_token_enc"
			   id="bale_connector_bot_token_enc"
			   value="<?php echo esc_attr( $display_val ); ?>"
			   placeholder="<?php esc_attr_e( 'Enter your bot token from @BotFather', 'bale-connector' ); ?>"
			   class="regular-text"
			   autocomplete="off" />
		<p class="description">
			<?php esc_html_e( 'The token is encrypted securely before being stored in the database.', 'bale-connector' ); ?>
		</p>
		<?php
	}

	/**
	 * Render keep data on uninstall field.
	 */
	public function render_keep_data_field() {
		$value = get_option( 'bale_connector_keep_data_on_uninstall', '1' );
		?>
		<label for="bale_connector_keep_data_on_uninstall">
			<input type="checkbox"
				   name="bale_connector_keep_data_on_uninstall"
				   id="bale_connector_keep_data_on_uninstall"
				   value="1"
				   <?php checked( '1', $value ); ?> />
			<?php esc_html_e( 'Preserve recipients and logs if the plugin is uninstalled.', 'bale-connector' ); ?>
		</label>
		<?php
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bale-connector' ) );
		}

		require_once BALE_CONNECTOR_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Render recipients page placeholder.
	 */
	public function render_recipients_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bale-connector' ) );
		}

		require_once BALE_CONNECTOR_PLUGIN_DIR . 'admin/views/recipients-page.php';
	}

	/**
	 * Render logs page placeholder.
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bale-connector' ) );
		}

		require_once BALE_CONNECTOR_PLUGIN_DIR . 'admin/views/logs-page.php';
	}
}
