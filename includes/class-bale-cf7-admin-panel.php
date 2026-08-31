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

		// wpcf7_admin_enqueue_scripts is NOT a public hook — it is the NAME
		// of CF7's own internal callback, which CF7 registers against the
		// native 'admin_enqueue_scripts' hook (no do_action() ever fires
		// under that name). Subscribe to the native hook instead and gate on
		// the same substring CF7 itself uses.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Persist our panel settings when CF7 itself saves the form. CF7 has
		// already verified its own save nonce (wpcf7-save-contact-form_<id>)
		// and the wpcf7_edit_contact_form capability before this fires.
		add_action( 'wpcf7_save_contact_form', array( $this, 'save_settings_on_cf7_save' ), 10, 3 );

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
	 * CF7's own admin/admin.php gates on strpos( $hook_suffix, 'wpcf7' ) —
	 * its menu pages are registered under the 'wpcf7' page slug, so the
	 * hook suffixes (e.g. toplevel_page_wpcf7) contain 'wpcf7', not
	 * 'contact-form-7'.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		// TEMPORARY DEBUG: confirm the exact real hook suffix once on a live
		// site, then remove this line.
		error_log( 'BALE_DEBUG hook: ' . $hook_suffix ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- temporary diagnostic, remove after one confirmation.

		if ( false === strpos( (string) $hook_suffix, 'wpcf7' ) ) {
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
	 * Persist per-form settings when CF7 itself saves a form.
	 *
	 * Hooked to wpcf7_save_contact_form, which CF7 fires (with the
	 * WPCF7_ContactForm object, the merged request data, and the context)
	 * right after property sanitization and just before
	 * $contact_form->save(). By the time it runs, CF7 has already verified
	 * its own save nonce (wpcf7-save-contact-form_<id>) and the
	 * wpcf7_edit_contact_form capability in wpcf7_load_contact_form_admin(),
	 * so this handler does not re-verify a Bale-specific nonce — the save is
	 * CF7's own authenticated request.
	 *
	 * Reads the panel fields from $_POST directly (the panel inputs carry
	 * name attributes for exactly this purpose) and reuses the same
	 * sanitization path the former AJAX save used.
	 *
	 * @param WPCF7_ContactForm $contact_form The form being saved.
	 * @param array             $data         Merged request data CF7 assembled.
	 * @param string            $context      Save context ('save' or 'validate').
	 */
	public function save_settings_on_cf7_save( $contact_form, $data, $context ) {
		// Only persist on real saves, not on CF7's internal validation pass.
		if ( 'save' !== $context ) {
			return;
		}

		if ( ! $contact_form instanceof WPCF7_ContactForm || ! $contact_form->id() ) {
			return;
		}

		// Defense in depth: CF7 has already checked its own capability, but
		// keep the explicit plugin-config gate here too (AGENTS.md §6).
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$form_id = absint( $contact_form->id() );

		$enabled = ! empty( $_POST['bale_cf7_enabled'] );
		$recipient_ids = isset( $_POST['bale_cf7_recipient_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['bale_cf7_recipient_ids'] ) )
			: array();
		$message_template = isset( $_POST['bale_cf7_message_template'] )
			? wp_unslash( (string) $_POST['bale_cf7_message_template'] )
			: '';

		$template = Bale_CF7_Form_Settings::sanitize_template( $message_template );

		Bale_CF7_Integration::save_form_settings(
			$form_id,
			array(
				'enabled'          => $enabled,
				'recipient_ids'    => $recipient_ids,
				'message_template' => $template,
			)
		);
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
