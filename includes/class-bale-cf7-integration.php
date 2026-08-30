<?php
/**
 * Contact Form 7 to Bale integration.
 *
 * Fires on wpcf7_mail_sent only (never wpcf7_before_send_mail — AGENTS.md
 * §10), so Bale notifications are sent only for submissions that passed
 * CF7's own validation and spam checks.
 *
 * All outbound sends are dispatched through Action Scheduler (AGENTS.md §3
 * and §5): a slow or failing Bale API call never blocks the page load that
 * submitted the form. Failed sends are logged and retried with backoff.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_CF7_Integration {

	/**
	 * Action Scheduler hook name for the async send.
	 */
	const ACTION_HOOK = 'bale_connector_cf7_send';

	/**
	 * Action Scheduler group for all scheduled actions.
	 */
	const ACTION_GROUP = 'bale-connector';

	/**
	 * Minimum delay in seconds before the first retry.
	 */
	const MIN_RETRY_DELAY = 60;

	/**
	 * Maximum number of automatic retries per submission.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Cached instance of the admin panel class.
	 *
	 * @var Bale_CF7_Admin_Panel
	 */
	private $panel;

	/**
	 * Bootstrap the integration. Called after plugins_loaded.
	 */
	public function register() {
		// Hook on 'init': Action Scheduler initializes at 'init' priority 1,
		// and its API functions must not be called before that.
		add_action( 'init', array( $this, 'register_hooks' ) );
	}

	/**
	 * Register the CF7 hooks (only when CF7 is active).
	 */
	public function register_hooks() {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			// Contact Form 7 is not active — nothing to integrate with.
			return;
		}

		add_action( 'wpcf7_mail_sent', array( $this, 'handle_form_submission' ), 10, 1 );
		add_action( self::ACTION_HOOK, array( $this, 'action_send' ), 10, 1 );

		if ( is_admin() ) {
			require_once BALE_CONNECTOR_PLUGIN_DIR . 'includes/class-bale-cf7-admin-panel.php';
			$this->panel = new Bale_CF7_Admin_Panel();
			$this->panel->register();
		}
	}

	/**
	 * Get per-form settings from the form settings table.
	 *
	 * @param int $form_id CF7 form post ID.
	 * @return array|null Settings array or null when not configured.
	 */
	public static function get_form_settings( $form_id ) {
		global $wpdb;

		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return null;
		}

		$table = $wpdb->prefix . 'bale_connector_form_settings';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT enabled, recipient_ids, message_template FROM {$table} WHERE form_type = %s AND form_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name built from $wpdb->prefix only.
				'cf7',
				(string) $form_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$recipient_ids = json_decode( (string) $row['recipient_ids'], true );

		return array(
			'enabled'          => (int) $row['enabled'],
			'recipient_ids'    => is_array( $recipient_ids ) ? array_map( 'absint', $recipient_ids ) : array(),
			'message_template' => (string) $row['message_template'],
		);
	}

	/**
	 * Save per-form settings (upsert) keyed by (form_type, form_id).
	 *
	 * @param int   $form_id CF7 form post ID.
	 * @param array $data    Settings: enabled (bool), recipient_ids (int[]), message_template (string).
	 * @return bool True on success.
	 */
	public static function save_form_settings( $form_id, $data ) {
		global $wpdb;

		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return false;
		}

		$enabled  = ! empty( $data['enabled'] ) ? 1 : 0;
		$recipients = isset( $data['recipient_ids'] ) ? array_map( 'absint', (array) $data['recipient_ids'] ) : array();
		$recipients = array_values( array_filter( $recipients ) );
		$template = isset( $data['message_template'] )
			? sanitize_textarea_field( wp_unslash( (string) $data['message_template'] ) )
			: '';

		$table = $wpdb->prefix . 'bale_connector_form_settings';

		$values = array(
			'form_type'        => 'cf7',
			'form_id'          => (string) $form_id,
			'enabled'          => $enabled,
			'recipient_ids'    => (string) wp_json_encode( $recipients ),
			'message_template' => $template,
		);

		$existing_id = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_type = %s AND form_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name built from $wpdb->prefix.
				'cf7',
				(string) $form_id
			),
			ARRAY_A
		);

		$existing_id = is_array( $existing_id ) && isset( $existing_id['id'] ) ? $existing_id['id'] : 0;

		if ( $existing_id ) {
			$result = $wpdb->update(
				$table,
				$values,
				array(
					'form_type' => 'cf7',
					'form_id'   => (string) $form_id,
				),
				array( '%s', '%s', '%d', '%s', '%s' ),
				array( '%s', '%s' )
			);
		} else {
			$result = $wpdb->insert(
				$table,
				$values,
				array( '%s', '%s', '%d', '%s', '%s' )
			);
		}

		return false !== $result;
	}

	/**
	 * wpcf7_mail_sent handler: collect data and schedule the async send.
	 *
	 * Must stay lightweight — the actual Bale API call happens in an
	 * Action Scheduler background action, not here.
	 *
	 * @param WPCF7_ContactForm $contact_form The submitted form.
	 */
	public function handle_form_submission( $contact_form ) {
		if ( ! $contact_form instanceof WPCF7_ContactForm ) {
			return;
		}

		$form_id = absint( $contact_form->id() );
		if ( ! $form_id ) {
			return;
		}

		$settings = self::get_form_settings( $form_id );

		if ( empty( $settings ) || empty( $settings['enabled'] ) || empty( $settings['recipient_ids'] ) ) {
			return;
		}

		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();
		if ( ! is_array( $posted_data ) || empty( $posted_data ) ) {
			return;
		}

		$args = array(
			'form_id'       => $form_id,
			'posted_data'   => $posted_data,
			'recipient_ids' => $settings['recipient_ids'],
			'retries'       => 0,
		);

		/**
		 * Filter the arguments scheduled for the Bale send.
		 *
		 * @param array             $args         Scheduled arguments.
		 * @param WPCF7_ContactForm $contact_form The submitted form.
		 */
		$args = apply_filters( 'bale_connector_cf7_send_args', $args, $contact_form );

		$this->schedule_send( $args );
	}

	/**
	 * Schedule the async Bale send via Action Scheduler.
	 *
	 * @param array $args Form id, posted data, recipient ids, retries.
	 * @return bool True if scheduled.
	 */
	private function schedule_send( $args ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Bale_Logger::log(
				array(
					'source_type'        => 'cf7',
					'source_ref'         => isset( $args['form_id'] ) ? (string) $args['form_id'] : '',
					'recipient_chat_id'  => 'unknown',
					'payload'            => array( 'error' => 'action_scheduler_unavailable' ),
					'response'           => '',
					'status'             => 'failed',
				)
			);
			return false;
		}

		$action_id = as_schedule_single_action(
			time(),
			self::ACTION_HOOK,
			array( $args ),
			self::ACTION_GROUP
		);

		return is_numeric( $action_id ) && $action_id > 0;
	}

	/**
	 * Action Scheduler callback: perform the actual Bale send.
	 *
	 * Public on purpose — Action Scheduler invokes it as a hook callback.
	 *
	 * @param array $args Form id, posted data, recipient ids, retries.
	 */
	public function action_send( $args ) {
		$form_id       = isset( $args['form_id'] ) ? absint( $args['form_id'] ) : 0;
		$posted_data   = isset( $args['posted_data'] ) && is_array( $args['posted_data'] ) ? $args['posted_data'] : array();
		$recipient_ids = isset( $args['recipient_ids'] ) && is_array( $args['recipient_ids'] ) ? array_map( 'absint', $args['recipient_ids'] ) : array();

		if ( ! $form_id || empty( $recipient_ids ) ) {
			return;
		}

		$settings = self::get_form_settings( $form_id );
		if ( empty( $settings ) || empty( $settings['enabled'] ) || empty( $settings['recipient_ids'] ) ) {
			return;
		}

		$client = bale_connector_get_client();
		if ( is_wp_error( $client ) ) {
			$this->log_and_requeue( $args, $client, null );
			return;
		}

		// Resolve configured recipients.
		$recipients = array();
		foreach ( $recipient_ids as $rid ) {
			$row = Bale_Recipients::get( $rid );
			if ( $row ) {
				$recipients[] = $row;
			}
		}

		if ( empty( $recipients ) ) {
			$this->log_and_requeue(
				$args,
				new WP_Error( 'bale_no_recipients', __( 'No valid recipients configured for this form.', 'bale-connector' ) ),
				null
			);
			return;
		}

		$message = Bale_Template::render(
			$settings['message_template'],
			$posted_data,
			array(
				'form-title' => get_the_title( $form_id ),
				'form-id'    => (string) $form_id,
			)
		);

		$failure = null;

		foreach ( $recipients as $recipient ) {
			$chat_id = isset( $recipient['chat_id'] ) ? $recipient['chat_id'] : '';

			$result = $client->sendMessage( $chat_id, $message );

			if ( is_wp_error( $result ) ) {
				Bale_Logger::log(
					array(
						'source_type'       => 'cf7',
						'source_ref'        => (string) $form_id,
						'recipient_chat_id' => $chat_id,
						'payload'           => array( 'text' => $message ),
						'response'          => array(
							'error_code'  => $result->get_error_code(),
							'description' => $result->get_error_message(),
						),
						'status'            => 'failed',
					)
				);
				$failure = $result;
				continue;
			}

			Bale_Logger::log(
				array(
					'source_type'       => 'cf7',
					'source_ref'        => (string) $form_id,
					'recipient_chat_id' => $chat_id,
					'payload'           => array( 'text' => $message, 'chat_id' => $chat_id ),
					'response'          => $result,
					'status'            => 'success',
				)
			);
		}

		if ( null !== $failure ) {
			$this->schedule_retry( $args, $failure );
		}
	}

	/**
	 * Log a failed send attempt and requeue for retry when appropriate.
	 *
	 * @param array       $args  Original scheduled args.
	 * @param WP_Error    $error The failure.
	 * @param array|null  $row   Recipient row when known.
	 */
	private function log_and_requeue( $args, $error, $row ) {
		$chat_id = is_array( $row ) && isset( $row['chat_id'] ) ? $row['chat_id'] : 'unknown';

		Bale_Logger::log(
			array(
				'source_type'       => 'cf7',
				'source_ref'        => isset( $args['form_id'] ) ? (string) $args['form_id'] : '',
				'recipient_chat_id' => $chat_id,
				'payload'           => array(
					'text'          => isset( $args['posted_data'] ) ? wp_json_encode( $args['posted_data'] ) : '',
					'recipient_ids' => isset( $args['recipient_ids'] ) ? $args['recipient_ids'] : array(),
				),
				'response'          => array(
					'error_code'  => $error->get_error_code(),
					'description' => $error->get_error_message(),
				),
				'status'            => 'failed',
			)
		);

		$this->schedule_retry( $args, $error );
	}

	/**
	 * Schedule a retry with exponential backoff.
	 *
	 * Honors Bale's retry_after when present; otherwise doubles the delay
	 * per attempt, starting at MIN_RETRY_DELAY. Gives up after MAX_RETRIES
	 * automatic retries (the final failure stays logged as failed).
	 *
	 * @param array    $args  Original scheduled args.
	 * @param WP_Error $error The failure.
	 */
	private function schedule_retry( $args, $error ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$retries = isset( $args['retries'] ) ? (int) $args['retries'] : 0;

		if ( $retries >= self::MAX_RETRIES ) {
			return;
		}

		$args['retries'] = $retries + 1;

		$delay    = self::MIN_RETRY_DELAY * ( 2 ** $retries );
		$retry_after = 0;
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
			$retry_after = (int) $data['retry_after'];
		}

		if ( $retry_after > 0 ) {
			$delay = max( $retry_after, self::MIN_RETRY_DELAY );
		}

		as_schedule_single_action(
			time() + $delay,
			self::ACTION_HOOK,
			array( $args ),
			self::ACTION_GROUP
		);
	}
}
