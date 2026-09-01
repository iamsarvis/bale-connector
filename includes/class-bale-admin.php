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
		add_action( 'admin_init', array( $this, 'handle_log_actions' ) );
		add_action( 'admin_notices', array( $this, 'check_token_decryption_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX handlers
		add_action( 'wp_ajax_bale_save_recipient', array( $this, 'ajax_save_recipient' ) );
		add_action( 'wp_ajax_bale_delete_recipient', array( $this, 'ajax_delete_recipient' ) );
		add_action( 'wp_ajax_bale_test_recipient_connection', array( $this, 'ajax_test_recipient_connection' ) );
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

		register_setting(
			'bale_connector_settings_group',
			'bale_connector_log_level',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_log_level' ),
				'default'           => 'all',
			)
		);

		register_setting(
			'bale_connector_settings_group',
			'bale_connector_log_retention_mb',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_log_retention_mb' ),
				'default'           => 5,
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

		add_settings_field(
			'bale_connector_log_level',
			__( 'Log Level', 'bale-connector' ),
			array( $this, 'render_log_level_field' ),
			'bale-connector',
			'bale_connector_general_section'
		);

		add_settings_field(
			'bale_connector_log_retention_mb',
			__( 'Log Storage Cap (MB)', 'bale-connector' ),
			array( $this, 'render_log_retention_field' ),
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

		// Idempotency guard: if $raw_token is already encrypted ciphertext payload, preserve as-is
		$decoded = base64_decode( $raw_token, true );
		if ( false !== $decoded && ( 0 === strpos( $decoded, 'sodium:' ) || 0 === strpos( $decoded, 'openssl:' ) ) ) {
			return $raw_token;
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
	 * Sanitize the log level option.
	 *
	 * @param mixed $input Input value.
	 * @return string 'all' or 'failed_only'.
	 */
	public function sanitize_log_level( $input ) {
		return ( 'failed_only' === $input ) ? 'failed_only' : 'all';
	}

	/**
	 * Sanitize the log retention cap (MB). 0–1024; 0 disables cleanup.
	 *
	 * @param mixed $input Input value.
	 * @return int Cap in MB.
	 */
	public function sanitize_log_retention_mb( $input ) {
		return max( 0, min( 1024, absint( $input ) ) );
	}

	/**
	 * Render the log level select field.
	 */
	public function render_log_level_field() {
		$value = Bale_Logger::get_log_level();
		?>
		<select name="bale_connector_log_level" id="bale_connector_log_level">
			<option value="all" <?php selected( 'all', $value ); ?>><?php esc_html_e( 'Log all sends (success and failed)', 'bale-connector' ); ?></option>
			<option value="failed_only" <?php selected( 'failed_only', $value ); ?>><?php esc_html_e( 'Log failed sends only', 'bale-connector' ); ?></option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Choose which send attempts are stored in the logs table.', 'bale-connector' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the log retention cap field.
	 */
	public function render_log_retention_field() {
		$value = (int) get_option( 'bale_connector_log_retention_mb', 5 );
		?>
		<input type="number"
			   name="bale_connector_log_retention_mb"
			   id="bale_connector_log_retention_mb"
			   value="<?php echo esc_attr( $value ); ?>"
			   min="0"
			   max="1024"
			   step="1"
			   class="small-text" />
		<?php esc_html_e( 'MB', 'bale-connector' ); ?>
		<p class="description">
			<?php esc_html_e( 'Oldest logs are pruned automatically once storage exceeds this size. Set 0 to keep logs indefinitely.', 'bale-connector' ); ?>
		</p>
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
	 * Render logs page.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bale-connector' ) );
		}

		require_once BALE_CONNECTOR_PLUGIN_DIR . 'admin/views/logs-page.php';
	}

	/**
	 * Handle log deletion POSTs (single, bulk, delete-all).
	 *
	 * SECURITY: deletion is never performed via GET links — every path is a
	 * POST request verified with check_admin_referer() (CSRF-safe, immune to
	 * link prefetchers) and gated by current_user_can( 'manage_options' ).
	 * Ends with POST-Redirect-GET so a refresh cannot replay a delete.
	 */
	public function handle_log_actions() {
		$is_custom_post = isset( $_POST['bale_log_action'] );
		$bulk_action    = '';

		if ( ! $is_custom_post && isset( $_POST['action'], $_POST['action2'] ) ) {
			// Standard WP_List_Table bulk dropdown (top + bottom selects).
			$top    = sanitize_key( wp_unslash( $_POST['action'] ) );
			$bottom = sanitize_key( wp_unslash( $_POST['action2'] ) );

			if ( '-1' !== $top && '' !== $top ) {
				$bulk_action = $top;
			} elseif ( '-1' !== $bottom && '' !== $bottom ) {
				$bulk_action = $bottom;
			}
		}

		if ( ! $is_custom_post && '' === $bulk_action ) {
			return; // Nothing to do — normal admin page load.
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bale-connector' ) );
		}

		$logs_url = admin_url( 'admin.php?page=bale-connector-logs' );

		// Standard bulk dropdown path.
		if ( '' !== $bulk_action ) {
			check_admin_referer( 'bale_bulk_logs', 'bale_log_nonce' );

			if ( 'bale_delete_logs' === $bulk_action ) {
				$ids     = isset( $_POST['log_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['log_ids'] ) ) : array();
				$deleted = Bale_Logger::delete_by_ids( $ids );

				wp_safe_redirect( add_query_arg( array(
					'bale_deleted' => $deleted,
					'bale_total'   => count( $ids ),
				), $logs_url ) );
				exit;
			}

			if ( 'bale_delete_all_logs' === $bulk_action ) {
				$deleted = Bale_Logger::delete_all();

				wp_safe_redirect( add_query_arg( array(
					'bale_deleted' => $deleted,
					'bale_total'   => $deleted,
				), $logs_url ) );
				exit;
			}

			return; // Unknown bulk action: ignore.
		}

		// Custom POST path (single-row form and the Delete All button).
		$action = sanitize_key( wp_unslash( $_POST['bale_log_action'] ) );

		switch ( $action ) {
			case 'delete':
				check_admin_referer( 'bale_delete_log', 'bale_log_nonce' );

				$id      = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
				$deleted = $id ? Bale_Logger::delete_by_ids( $id ) : 0;

				wp_safe_redirect( add_query_arg( array(
					'bale_deleted' => $deleted,
					'bale_total'   => 1,
				), $logs_url ) );
				exit;

			case 'delete_all':
				check_admin_referer( 'bale_delete_all_logs', 'bale_log_nonce' );

				$deleted = Bale_Logger::delete_all();

				wp_safe_redirect( add_query_arg( array(
					'bale_deleted' => $deleted,
					'bale_total'   => $deleted,
				), $logs_url ) );
				exit;

			default:
				// Unknown action value: ignore (no redirect, no deletion).
				return;
		}
	}

	/**
	 * Testable core of the log-delete handlers.
	 *
	 * Performs capability check, nonce verification and the deletion itself,
	 * and RETURNS the deleted count instead of redirecting/exiting — the
	 * thin public wrapper above owns the HTTP concerns. Nonce failure dies
	 * with -1 (exactly like check_admin_referer() in WP), and a failed
	 * capability check dies with 0 — both observable in tests via a
	 * throwing wp_die() mock.
	 *
	 * @param string $action   One of 'delete', 'delete_bulk', 'delete_all'.
	 * @param array  $post     Emulated $_POST subset (already unslashed).
	 * @return int Number of rows deleted.
	 */
	public function process_log_delete( $action, $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bale-connector' ), '', array( 'exit_code' => 0 ) );
		}

		$nonce_action_map = array(
			'delete'      => 'bale_delete_log',
			'delete_bulk' => 'bale_bulk_logs',
			'delete_all'  => 'bale_delete_all_logs',
		);

		$action = sanitize_key( (string) $action );

		if ( ! isset( $nonce_action_map[ $action ] ) ) {
			return 0; // Unknown action: nothing to verify, nothing to delete.
		}

		check_admin_referer( $nonce_action_map[ $action ], 'bale_log_nonce' );

		switch ( $action ) {
			case 'delete':
				$id = isset( $post['log_id'] ) ? absint( $post['log_id'] ) : 0;
				return $id ? Bale_Logger::delete_by_ids( $id ) : 0;

			case 'delete_bulk':
				$ids = isset( $post['log_ids'] ) ? array_map( 'absint', (array) $post['log_ids'] ) : array();
				return Bale_Logger::delete_by_ids( $ids );

			case 'delete_all':
				return Bale_Logger::delete_all();
		}

		return 0;
	}

	/**
	 * Render admin notices for completed log deletions.
	 */
	public function render_log_action_notices() {
		if ( isset( $_GET['bale_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback, no state change.
			$deleted = absint( $_GET['bale_deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$total   = isset( $_GET['bale_total'] ) ? absint( $_GET['bale_total'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$message = ( 1 === $total )
				? __( 'Log entry deleted.', 'bale-connector' )
				: sprintf(
					/* translators: %s: number of deleted log entries */
					__( '%s log entries deleted.', 'bale-connector' ),
					number_format_i18n( $deleted )
				);

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Enqueue admin styles and scripts for Bale Connector pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only enqueue on our plugin admin pages.
		if ( false === strpos( $hook, 'bale-connector' ) ) {
			return;
		}

		wp_enqueue_style(
			'bale-connector-admin',
			BALE_CONNECTOR_PLUGIN_URL . 'admin/css/recipients.css',
			array(),
			BALE_CONNECTOR_VERSION
		);

		if ( false !== strpos( $hook, 'bale-connector-logs' ) ) {
			wp_enqueue_style(
				'bale-connector-logs',
				BALE_CONNECTOR_PLUGIN_URL . 'admin/css/logs.css',
				array( 'bale-connector-admin' ),
				BALE_CONNECTOR_VERSION
			);

			wp_enqueue_script(
				'bale-connector-logs',
				BALE_CONNECTOR_PLUGIN_URL . 'admin/js/logs.js',
				array(),
				BALE_CONNECTOR_VERSION,
				true
			);

			wp_localize_script(
				'bale-connector-logs',
				'baleConnectorLogs',
				array(
					'i18n' => array(
						'confirmDelete'     => __( 'Delete this log entry? This cannot be undone.', 'bale-connector' ),
						'confirmBulkDelete' => __( 'Delete the selected log entries? This cannot be undone.', 'bale-connector' ),
						'confirmDeleteAll'  => __( 'Delete ALL log entries? This cannot be undone.', 'bale-connector' ),
					),
				)
			);
		}

		if ( false !== strpos( $hook, 'bale-connector-recipients' ) ) {
			wp_enqueue_script(
				'bale-connector-recipients',
				BALE_CONNECTOR_PLUGIN_URL . 'admin/js/recipients.js',
				array(),
				BALE_CONNECTOR_VERSION,
				true
			);

			wp_localize_script(
				'bale-connector-recipients',
				'baleConnectorRecipients',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'bale_recipients_nonce' ),
					'i18n'      => array(
						'confirmDelete' => __( 'Are you sure you want to delete this recipient?', 'bale-connector' ),
						'testing'       => __( 'Testing...', 'bale-connector' ),
						'saving'        => __( 'Saving...', 'bale-connector' ),
						'save'          => __( 'Save Recipient', 'bale-connector' ),
						'edit'          => __( 'Update Recipient', 'bale-connector' ),
						'addNew'        => __( 'Add New Recipient', 'bale-connector' ),
						'editTitle'     => __( 'Edit Recipient', 'bale-connector' ),
						'cancel'        => __( 'Cancel', 'bale-connector' ),
						'errorGeneric'  => __( 'An unexpected error occurred. Please try again.', 'bale-connector' ),
					),
				)
			);
		}
	}

	/**
	 * AJAX handler: Save or update a recipient.
	 */
	public function ajax_save_recipient() {
		check_ajax_referer( 'bale_recipients_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bale-connector' ) ), 403 );
			return;
		}

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$chat_id = isset( $_POST['chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_id'] ) ) : '';
		$type    = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		$data = array(
			'label'   => $label,
			'chat_id' => $chat_id,
			'type'    => $type,
		);

		if ( $id > 0 ) {
			$result = Bale_Recipients::update( $id, $data );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return;
			}
			$recipient = Bale_Recipients::get( $id );
			wp_send_json_success( array(
				'message'   => __( 'Recipient updated successfully.', 'bale-connector' ),
				'recipient' => $recipient,
				'is_new'    => false,
			) );
			return;
		} else {
			$inserted_id = Bale_Recipients::add( $data );
			if ( is_wp_error( $inserted_id ) ) {
				wp_send_json_error( array( 'message' => $inserted_id->get_error_message() ) );
				return;
			}
			$recipient = Bale_Recipients::get( $inserted_id );
			wp_send_json_success( array(
				'message'   => __( 'Recipient added successfully.', 'bale-connector' ),
				'recipient' => $recipient,
				'is_new'    => true,
			) );
			return;
		}
	}

	/**
	 * AJAX handler: Delete a recipient.
	 */
	public function ajax_delete_recipient() {
		check_ajax_referer( 'bale_recipients_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bale-connector' ) ), 403 );
			return;
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid recipient ID.', 'bale-connector' ) ) );
			return;
		}

		$result = Bale_Recipients::delete( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( array(
			'message' => __( 'Recipient deleted successfully.', 'bale-connector' ),
			'id'      => $id,
		) );
		return;
	}

	/**
	 * AJAX handler: Test connection to a chat.
	 */
	public function ajax_test_recipient_connection() {
		check_ajax_referer( 'bale_recipients_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bale-connector' ) ), 403 );
			return;
		}

		$recipient_id = isset( $_POST['recipient_id'] ) ? absint( $_POST['recipient_id'] ) : 0;
		if ( ! $recipient_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid recipient ID.', 'bale-connector' ) ) );
			return;
		}

		$recipient = Bale_Recipients::get( $recipient_id );
		if ( ! $recipient ) {
			wp_send_json_error( array( 'message' => __( 'Recipient not found.', 'bale-connector' ) ) );
			return;
		}

		$chat_id = $recipient['chat_id'];

		$recipient_type = isset( $recipient['type'] ) ? $recipient['type'] : '';

		$result = Bale_Recipients::test_connection( $chat_id, $recipient_id, $recipient_type );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message'    => $result->get_error_message(),
				'error_code' => $result->get_error_code(),
				'status'     => 'failed',
			) );
			return;
		}

		$chat_title = isset( $result['title'] ) ? $result['title'] : ( isset( $result['first_name'] ) ? $result['first_name'] : '' );
		$username   = isset( $result['username'] ) ? '@' . $result['username'] : '';
		$details    = trim( $chat_title . ' ' . $username );

		$msg = __( 'Connection verified successfully.', 'bale-connector' );
		if ( ! empty( $details ) ) {
			$msg .= ' (' . $details . ')';
		}

		wp_send_json_success( array(
			'message'   => $msg,
			'chat_info' => $result,
			'status'    => 'success',
			'tested_at' => current_time( 'mysql' ),
		) );
		return;
	}
}