<?php
/**
 * Shared logger for all Bale Connector triggers.
 *
 * Single write path into the shared logs table (AGENTS.md §7). CF7 writes
 * here in Phase 4; Pro triggers (order, OTP, ...) reuse the exact same path
 * via the documented bale_connector_log() function.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_Logger {

	/**
	 * Allowed log sources, shared by core and Pro triggers.
	 *
	 * @var array
	 */
	const ALLOWED_SOURCES = array( 'cf7' );

	/**
	 * Get table name for logs.
	 *
	 * @return string Table name with WordPress prefix.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bale_connector_logs';
	}

	/**
	 * Register a new trigger type into the shared admin UI and log table.
	 *
	 * Extension point for bale-connector-pro (AGENTS.md §7): Pro add-ons call
	 * this so their sends appear in the same log table and report UI as CF7,
	 * without modifying this repo's code.
	 *
	 * @param string $slug Unique source_type slug (e.g. 'woocommerce_order').
	 * @param array  $args {
	 *     Trigger definition.
	 *
	 *     @type string   $label       Human-readable name for admin UI.
	 *     @type callable $callback    Optional. Send handler receiving
	 *                                 ( $entry, $client ). Not required for
	 *                                 logging-only triggers.
	 *     @type string   $source_ref  Optional. Static source_ref value.
	 * }
	 * @return true|WP_Error True on success, WP_Error on invalid input.
	 */
	public static function register_trigger( $slug, $args ) {
		static $registered = array();

		$slug = sanitize_key( $slug );

		if ( empty( $slug ) ) {
			return new WP_Error(
				'bale_trigger_invalid_slug',
				__( 'Trigger slug is required and cannot be empty.', 'bale-connector' )
			);
		}

		if ( ! is_array( $args ) || empty( $args ) ) {
			return new WP_Error(
				'bale_trigger_invalid_args',
				__( 'Trigger args are required and must be an array.', 'bale-connector' )
			);
		}

		if ( isset( $registered[ $slug ] ) ) {
			return new WP_Error(
				'bale_trigger_already_registered',
				sprintf(
					/* translators: %s: trigger slug */
					__( 'Trigger "%s" is already registered.', 'bale-connector' ),
					$slug
				)
			);
		}

		$registered[ $slug ] = array(
			'label'      => isset( $args['label'] ) ? sanitize_text_field( $args['label'] ) : $slug,
			'callback'   => isset( $args['callback'] ) && is_callable( $args['callback'] ) ? $args['callback'] : null,
			'source_ref' => isset( $args['source_ref'] ) ? sanitize_text_field( $args['source_ref'] ) : '',
		);

		/**
		 * Fires after a trigger type is registered.
		 *
		 * @param string $slug Trigger slug (source_type).
		 * @param array  $args Registered trigger configuration.
		 */
		do_action( 'bale_connector_trigger_registered', $slug, $registered[ $slug ] );

		/**
		 * Filter the list of registered trigger slugs.
		 *
		 * Lets Pro add-ons register trigger slugs for the shared log write
		 * path and the shared admin UI without touching this repo's code.
		 *
		 * @param array $slugs Registered trigger slugs.
		 */
		add_filter(
			'bale_connector_registered_trigger_slugs',
			static function ( $slugs ) use ( $slug ) {
				$slugs[] = $slug;
				return array_unique( $slugs );
			}
		);

		return true;
	}

	/**
	 * Write an entry into the logs table.
	 *
	 * The single write path for every trigger — CF7 today, Pro add-ons later.
	 *
	 * @param array $entry {
	 *     Log entry.
	 *
	 *     @type string $source_type       One of ALLOWED_SOURCES (or a
	 *                                     trigger slug registered via
	 *                                     bale_connector_register_trigger()).
	 *     @type string $source_ref        Optional. Form ID, order ID, etc.
	 *     @type string $recipient_chat_id Target chat ID.
	 *     @type mixed  $payload           JSON-encodable data that was sent.
	 *     @type mixed  $response          Optional. JSON-encodable response data.
	 *     @type string $status            'success' or 'failed'.
	 * }
	 * @return int|WP_Error Inserted log ID on success, WP_Error on failure.
	 */
	public static function log( $entry ) {
		global $wpdb;

		$defaults = array(
			'source_type'        => '',
			'source_ref'         => '',
			'recipient_chat_id'  => '',
			'payload'            => '',
			'response'           => '',
			'status'             => 'failed',
		);

		$entry = wp_parse_args( $entry, $defaults );

		$source_type = sanitize_key( $entry['source_type'] );
		$source_ref  = sanitize_text_field( (string) $entry['source_ref'] );
		$chat_id     = sanitize_text_field( (string) $entry['recipient_chat_id'] );
		$status      = ( 'success' === $entry['status'] ) ? 'success' : 'failed';

		if ( '' === $source_type ) {
			return new WP_Error(
				'bale_log_invalid_source_type',
				__( 'Log entry source_type is required.', 'bale-connector' )
			);
		}

		if ( ! in_array( $source_type, self::ALLOWED_SOURCES, true ) ) {
			$registered = apply_filters( 'bale_connector_registered_trigger_slugs', array() );
			if ( ! in_array( $source_type, $registered, true ) ) {
				return new WP_Error(
					'bale_log_unknown_source_type',
					sprintf(
						/* translators: %s: attempted source_type value */
						__( 'Unknown log source_type "%s". Register the trigger first via bale_connector_register_trigger().', 'bale-connector' ),
						$source_type
					)
				);
			}
		}

		if ( '' === $chat_id ) {
			return new WP_Error(
				'bale_log_invalid_chat_id',
				__( 'Log entry recipient_chat_id is required.', 'bale-connector' )
			);
		}

		$payload_json  = wp_json_encode( $entry['payload'] );
		$response_json = ! empty( $entry['response'] ) ? wp_json_encode( $entry['response'] ) : '';

		if ( false === $payload_json ) {
			$payload_json = '{"log_error":"payload_not_json_encodable"}';
		}

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			self::get_table_name(),
			array(
				'source_type'        => $source_type,
				'source_ref'         => $source_ref,
				'recipient_chat_id'  => $chat_id,
				'payload'            => $payload_json,
				'response'           => $response_json,
				'status'             => $status,
				'created_at'         => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'bale_log_insert_failed',
				__( 'Failed to write to the Bale Connector logs table.', 'bale-connector' )
			);
		}

		/**
		 * Fires after a log entry is successfully written.
		 *
		 * @param int   $log_id Inserted log row ID.
		 * @param array $entry  The sanitized log entry.
		 */
		do_action( 'bale_connector_entry_logged', (int) $wpdb->insert_id, $entry );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get slugs of all triggers registered in this request.
	 *
	 * @return array Array of trigger slugs.
	 */
	public static function get_registered_trigger_slugs() {
		$slugs = array();

		/**
		 * Filter the list of registered trigger slugs.
		 *
		 * Lets Pro add-ons register trigger slugs for the shared log write
		 * path and the shared admin UI without touching this repo's code.
		 *
		 * @param array $slugs Registered trigger slugs.
		 */
		return apply_filters( 'bale_connector_registered_trigger_slugs', $slugs );
	}
}
