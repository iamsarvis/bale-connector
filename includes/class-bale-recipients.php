<?php
/**
 * Recipient (Person, Group, Channel) data management and connection testing.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_Recipients {

	/**
	 * Allowed recipient types per Bale Bot API specifications.
	 *
	 * @var array
	 */
	const ALLOWED_TYPES = array( 'user', 'group', 'channel' );

	/**
	 * Get table name for recipients.
	 *
	 * @return string Table name with WordPress prefix.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bale_connector_recipients';
	}

	/**
	 * Retrieve all recipients from database.
	 *
	 * @param array $args Query arguments (orderby, order, limit, offset).
	 * @return array Array of recipient row objects/arrays.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$defaults = array(
			'orderby' => 'id',
			'order'   => 'ASC',
			'limit'   => 0,
			'offset'  => 0,
		);

		$r = wp_parse_args( $args, $defaults );

		$allowed_order_by = array( 'id', 'label', 'chat_id', 'type', 'created_at', 'last_tested_at' );
		$orderby = in_array( $r['orderby'], $allowed_order_by, true ) ? $r['orderby'] : 'id';
		$order   = 'DESC' === strtoupper( $r['order'] ) ? 'DESC' : 'ASC';

		$sql = "SELECT * FROM {$table_name} ORDER BY {$orderby} {$order}";

		if ( ! empty( $r['limit'] ) && (int) $r['limit'] > 0 ) {
			$limit  = (int) $r['limit'];
			$offset = (int) $r['offset'];
			$sql   .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		$results = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get a single recipient by ID.
	 *
	 * @param int $id Recipient ID.
	 * @return array|null Recipient data array or null if not found.
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$table_name = self::get_table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Add a new recipient.
	 *
	 * Validates label, type, and chat_id format.
	 * Does NOT require a successful test_connection() to save.
	 *
	 * @param array $data Data array (label, chat_id, type).
	 * @return int|WP_Error Inserted recipient ID on success, WP_Error on validation/db failure.
	 */
	public static function add( $data ) {
		global $wpdb;

		$label   = isset( $data['label'] ) ? sanitize_text_field( trim( $data['label'] ) ) : '';
		$chat_id = isset( $data['chat_id'] ) ? sanitize_text_field( trim( $data['chat_id'] ) ) : '';
		$type    = isset( $data['type'] ) ? sanitize_text_field( trim( $data['type'] ) ) : '';

		if ( empty( $label ) ) {
			return new WP_Error(
				'bale_recipient_empty_label',
				__( 'Recipient label is required.', 'bale-connector' )
			);
		}

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return new WP_Error(
				'bale_recipient_invalid_type',
				__( 'Invalid recipient type. Must be user, group, or channel.', 'bale-connector' )
			);
		}

		$chat_id_valid = Bale_Api_Client::validate_chat_id( $chat_id );
		if ( is_wp_error( $chat_id_valid ) ) {
			return $chat_id_valid;
		}

		$table_name = self::get_table_name();
		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'label'            => $label,
				'chat_id'          => $chat_id,
				'type'             => $type,
				'last_tested_at'   => null,
				'last_test_status' => null,
				'created_at'       => $now,
			),
			array( '%s', '%s', '%s', null, null, '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'bale_recipient_insert_failed',
				__( 'Failed to save recipient to database.', 'bale-connector' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing recipient.
	 *
	 * Validates label, type, and chat_id format.
	 * Does NOT require a successful test_connection() to save.
	 *
	 * @param int   $id   Recipient ID.
	 * @param array $data Data array (label, chat_id, type).
	 * @return bool|WP_Error True on success, WP_Error on validation/db failure.
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return new WP_Error(
				'bale_recipient_invalid_id',
				__( 'Invalid recipient ID.', 'bale-connector' )
			);
		}

		$existing = self::get( $id );
		if ( ! $existing ) {
			return new WP_Error(
				'bale_recipient_not_found',
				__( 'Recipient not found.', 'bale-connector' )
			);
		}

		$label   = isset( $data['label'] ) ? sanitize_text_field( trim( $data['label'] ) ) : $existing['label'];
		$chat_id = isset( $data['chat_id'] ) ? sanitize_text_field( trim( $data['chat_id'] ) ) : $existing['chat_id'];
		$type    = isset( $data['type'] ) ? sanitize_text_field( trim( $data['type'] ) ) : $existing['type'];

		if ( empty( $label ) ) {
			return new WP_Error(
				'bale_recipient_empty_label',
				__( 'Recipient label is required.', 'bale-connector' )
			);
		}

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return new WP_Error(
				'bale_recipient_invalid_type',
				__( 'Invalid recipient type. Must be user, group, or channel.', 'bale-connector' )
			);
		}

		$chat_id_valid = Bale_Api_Client::validate_chat_id( $chat_id );
		if ( is_wp_error( $chat_id_valid ) ) {
			return $chat_id_valid;
		}

		$table_name = self::get_table_name();

		$updated = $wpdb->update(
			$table_name,
			array(
				'label'   => $label,
				'chat_id' => $chat_id,
				'type'    => $type,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'bale_recipient_update_failed',
				__( 'Failed to update recipient in database.', 'bale-connector' )
			);
		}

		return true;
	}

	/**
	 * Delete a recipient by ID.
	 *
	 * @param int $id Recipient ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return new WP_Error(
				'bale_recipient_invalid_id',
				__( 'Invalid recipient ID.', 'bale-connector' )
			);
		}

		$table_name = self::get_table_name();
		$deleted = $wpdb->delete(
			$table_name,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error(
				'bale_recipient_delete_failed',
				__( 'Failed to delete recipient from database.', 'bale-connector' )
			);
		}

		return true;
	}

	/**
	 * Update last tested status for a recipient.
	 *
	 * @param int    $id     Recipient ID.
	 * @param string $status 'success' or 'failed'.
	 * @return bool True on success, false on failure.
	 */
	public static function update_test_status( $id, $status ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id || ! in_array( $status, array( 'success', 'failed' ), true ) ) {
			return false;
		}

		$table_name = self::get_table_name();
		$now = current_time( 'mysql' );

		$updated = $wpdb->update(
			$table_name,
			array(
				'last_tested_at'   => $now,
				'last_test_status' => $status,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Test connection to a chat_id via getChat().
	 *
	 * Separate handling for:
	 * 1. Missing bot token (WP_Error from bale_connector_get_client()): returns explicit message.
	 * 2. Invalid chat_id format: returns validation error.
	 * 3. Chat lookup errors (e.g. Chat Not Found, bot not started/added).
	 *
	 * Updates the recipient's test status in DB if recipient ID is provided.
	 *
	 * @param string   $chat_id      Chat ID or username.
	 * @param int|null $recipient_id Optional recipient ID to record test status.
	 * @return array|WP_Error Chat info array on success, WP_Error on failure.
	 */
	public static function test_connection( $chat_id, $recipient_id = null ) {
		$client = bale_connector_get_client();

		if ( is_wp_error( $client ) ) {
			if ( $recipient_id ) {
				self::update_test_status( $recipient_id, 'failed' );
			}
			return new WP_Error(
				'bale_token_missing',
				__( 'Please configure your Bot Token first.', 'bale-connector' )
			);
		}

		$chat_id = sanitize_text_field( trim( $chat_id ) );
		$chat_id_valid = Bale_Api_Client::validate_chat_id( $chat_id );
		if ( is_wp_error( $chat_id_valid ) ) {
			if ( $recipient_id ) {
				self::update_test_status( $recipient_id, 'failed' );
			}
			return $chat_id_valid;
		}

		$result = $client->getChat( $chat_id );

		if ( is_wp_error( $result ) ) {
			if ( $recipient_id ) {
				self::update_test_status( $recipient_id, 'failed' );
			}
			return $result;
		}

		if ( $recipient_id ) {
			self::update_test_status( $recipient_id, 'success' );
		}

		return $result;
	}
}
