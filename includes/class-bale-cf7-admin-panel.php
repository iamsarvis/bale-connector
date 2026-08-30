<?php
/**
 * Contact Form 7 editor panel for Bale Connector.
 *
 * Adds a "Bale Notification" tab to the CF7 form editor. Every state change
 * goes through admin-ajax with a nonce and a manage_options capability check
 * (AGENTS.md §6).
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_CF7_Admin_Panel {

	/**
	 * Nonce action for all panel AJAX requests.
	 */
	const NONCE_ACTION = 'bale_cf7_panel_nonce';

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_filter( 'wpcf7_editor_panels', array( $this, 'add_editor_panel' ) );
		add_action( 'wpcf7_admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_bale_cf7_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_bale_cf7_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_bale_cf7_test_send', array( $this, 'ajax_test_send' ) );
	}

	/**
	 * Add the Bale panel to the CF7 form editor.
	 *
	 * @param array $panels Existing editor panels.
	 * @return array Modified panels.
	 */
	public function add_editor_panel( $panels ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $panels;
		}

		$panels['bale-connector-panel'] = array(
			'title'    => __( 'Bale Notification', 'bale-connector' ),
			'callback' => array( $this, 'render_editor_panel' ),
		);

		return $panels;
	}

	/**
	 * Enqueue panel JS + data on CF7 editor screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'contact_page_contact-form-7' ) ) {
			return;
		}

		wp_enqueue_script(
			'bale-connector-cf7-panel',
			BALE_CONNECTOR_PLUGIN_URL . 'admin/js/cf7-panel.js',
			array(),
			BALE_CONNECTOR_VERSION,
			true
		);

		wp_localize_script(
			'bale-connector-cf7-panel',
			'baleCf7Panel',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bale_cf7_panel_nonce' ),
				'i18n'    => array(
					'saving'          => __( 'Saving...', 'bale-connector' ),
					'saved'           => __( 'Saved.', 'bale-connector' ),
					'sending'         => __( 'Sending test message...', 'bale-connector' ),
					'errorGeneric'    => __( 'An unexpected error occurred. Please try again.', 'bale-connector' ),
					'charLimit'       => __( 'Template exceeds the 4096 character Bale limit.', 'bale-connector' ),
				),
			)
		);
	}

	/**
	 * Render the Bale Notification panel inside the CF7 editor.
	 */
	public function render_editor_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$form = WPCF7_ContactForm::get_current();
		if ( ! $form || ! $form->id() ) {
			return;
		}

		$form_id     = absint( $form->id() );
		$settings    = Bale_CF7_Integration::get_form_settings( $form_id );
		$recipients  = bale_connector_recipients();

		$enabled     = ! empty( $settings ) && ! empty( $settings['enabled'] );
		$template    = is_array( $settings ) && isset( $settings['message_template'] )
			? $settings['message_template']
			: $this->default_template();
		$selected_ids = is_array( $settings ) && ! empty( $settings['recipient_ids'] )
			? $settings['recipient_ids']
			: array();

		require BALE_CONNECTOR_PLUGIN_DIR . 'admin/views/cf7-panel.php';
	}

	/**
	 * Default message template for new forms.
	 *
	 * @return string Template text.
	 */
	private function default_template() {
		return __( 'New form submission' . "\n" . 'Form: [form-title]' . "\n" . 'Name: [your-name]' . "\n" . 'Message: [your-message]', 'bale-connector' );
	}

	/**
	 * Verify nonce + capability for every panel AJAX request.
	 *
	 * @return void Sends JSON error and exits when the request is not authorized.
	 */
	private function verify_request() {
		check_ajax_referer( 'bale_cf7_panel_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'bale-connector' ) ),
				403
			);
		}
	}

	/**
	 * AJAX handler: save per-form settings.
	 */
	public function ajax_save_settings() {
		$this->verify_request();

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		if ( ! $form_id || 'wpcf7_contact_form' !== get_post_type( $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'bale-connector' ) ) );
		}

		$enabled          = ! empty( $_POST['enabled'] );
		$recipient_ids    = isset( $_POST['recipient_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['recipient_ids'] ) ) : array();
		$message_template = isset( $_POST['message_template'] ) ? wp_unslash( $_POST['message_template'] ) : '';

		$template = Bale_CF7_Form_Settings::sanitize_template( $message_template );

		$saved = Bale_CF7_Integration::save_form_settings(
			$form_id,
			array(
				'enabled'          => $enabled,
				'recipient_ids'    => $recipient_ids,
				'message_template' => $template,
			)
		);

		if ( ! $saved ) {
			wp_send_json_error( array( 'message' => __( 'Could not save settings. Please try again.', 'bale-connector' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Bale notification settings saved.', 'bale-connector' ) ) );
	}

	/**
	 * AJAX handler: render a live preview of the template with sample data.
	 *
	 * Read-only, but still nonce + capability gated.
	 */
	public function ajax_preview() {
		$this->verify_request();

		$template = isset( $_POST['template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template'] ) ) : '';
		$form_id  = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		$sample_fields = array(
			'your-name'    => 'Test User',
			'your-email'   => 'test@example.com',
			'your-subject' => __( 'Sample subject', 'bale-connector' ),
			'your-message' => __( 'This is a preview message.', 'bale-connector' ),
		);

		$rendered = Bale_Template::render(
			$template,
			$sample_fields,
			array(
				'form-title' => get_the_title( $form_id ),
				'form-id'    => (string) $form_id,
			)
		);

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $rendered ) : strlen( $rendered );

		wp_send_json_success(
			array(
				'rendered' => $rendered,
				'chars'    => $length,
				'over'     => $length > 4096,
			)
		);
	}

	/**
	 * AJAX handler: send a test message to a recipient.
	 */
	public function ajax_test_send() {
		$this->verify_request();

		$recipient_id = isset( $_POST['recipient_id'] ) ? absint( $_POST['recipient_id'] ) : 0;
		$form_id      = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		$recipient = $recipient_id ? Bale_Recipients::get( $recipient_id ) : null;
		if ( ! $recipient ) {
			wp_send_json_error( array( 'message' => __( 'Recipient not found.', 'bale-connector' ) ) );
		}

		$client = bale_connector_get_client();
		if ( is_wp_error( $client ) ) {
			wp_send_json_error( array( 'message' => $client->get_error_message() ) );
		}

		$text = __( '[Bale Connector] Test message — your form settings work!', 'bale-connector' );

		$result = $client->sendMessage( $recipient['chat_id'], $text );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Bale_Logger::log(
			array(
				'source_type'       => 'cf7',
				'source_ref'        => (string) $form_id,
				'recipient_chat_id' => $recipient['chat_id'],
				'payload'           => array( 'text' => $text, 'test' => true ),
				'response'          => $result,
				'status'            => 'success',
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Test message delivered to Bale.', 'bale-connector' ),
			)
		);
	}
}
