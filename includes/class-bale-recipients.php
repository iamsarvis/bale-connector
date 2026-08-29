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
	 * 4. Group/channel recipients: after getChat() succeeds, getChatMember()
	 *    verifies the bot itself is a member — a resolvable chat does not
	 *    guarantee the bot can post to it.
	 *
	 * For 'user' recipients only getChat() runs: there is no reliable way to
	 * confirm a private user has started the bot (Bale's getChatMember()
	 * semantics for private chats are not documented, and probing
	 * non-members would produce misleading "not found" failures).
	 *
	 * Updates the recipient's test status in DB if recipient ID is provided.
	 *
	 * @param string   $chat_id      Chat ID or username.
	 * @param int|null $recipient_id Optional recipient ID to record test status.
	 * @param string   $recipient_type Optional recipient type ('user', 'group', 'channel').
	 *                                 Group/channel types additionally verify bot membership.
	 * @return array|WP_Error Chat info array on success, WP_Error on failure.
	 */
	public static function test_connection( $chat_id, $recipient_id = null, $recipient_type = '' ) {
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

		// Group/channel: verify the bot itself is a member via getChatMember().
		if ( 'group' === $recipient_type || 'channel' === $recipient_type ) {
			$bot_member_result = self::verify_bot_membership( $client, $chat_id );

			if ( is_wp_error( $bot_member_result ) ) {
				if ( $recipient_id ) {
					self::update_test_status( $recipient_id, 'failed' );
				}
				return $bot_member_result;
			}
		}

		if ( $recipient_id ) {
			self::update_test_status( $recipient_id, 'success' );
		}

		return $result;
	}

	/**
	 * Verify the bot is a member of the given chat via getChatMember().
	 *
	 * Calls getMe() once (per-request static cache) to obtain the bot's own
	 * user ID, then asks the API whether that user is a member of $chat_id.
	 *
	 * @param Bale_Api_Client $client  Configured API client.
	 * @param string          $chat_id Chat ID or username.
	 * @return true|WP_Error True if the bot is a member, WP_Error on failure.
	 */
	private static function verify_bot_membership( $client, $chat_id ) {
		static $bot_user_id = null;

		if ( null === $bot_user_id ) {
			$me = $client->getMe();

			if ( is_wp_error( $me ) ) {
				return new WP_Error(
					'bale_getme_failed',
					__( 'Could not verify the bot identity (getMe failed): ', 'bale-connector' ) . $me->get_error_message(),
					array( 'wp_error' => $me )
				);
			}

			$bot_user_id = isset( $me['id'] ) ? $me['id'] : 0;
		}

		if ( empty( $bot_user_id ) ) {
			return new WP_Error(
				'bale_getme_failed',
				__( 'Could not determine the bot user ID (getMe returned no id).', 'bale-connector' )
			);
		}

		$member = $client->getChatMember( $chat_id, $bot_user_id );

		if ( is_wp_error( $member ) ) {
			return new WP_Error(
				'bale_bot_not_member',
				__( 'This chat_id is valid, but the bot is not currently a member of this group/channel — please add it first.', 'bale-connector' ),
				array( 'api_error' => $member->get_error_message() )
			);
		}

		return true;
	}
}
