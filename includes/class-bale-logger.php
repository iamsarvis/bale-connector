<?php
/**
 * Shared logger for all Bale Connector triggers.
 *
 * Single write path into the shared logs table (AGENTS.md §7). CF7 writes
 * here in Phase 4; Pro triggers (order, OTP, ...) reuse the exact same path
 * via the documented bale_connector_log() function.
 *
 * Also provides the read/query/delete surface used by the admin log viewer
 * (Phase 5) and the storage-cap based auto-cleanup.
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
	 * Hour (in seconds) between opportunistic retention sweeps.
	 *
	 * @var int
	 */
	const RETENTION_SWEEP_INTERVAL = 3600;

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
	 * Honors the `bale_connector_log_level` setting: when set to
	 * `failed_only`, successful sends are not persisted.
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

		// Log-level gate: skip persisting successful sends when configured.
		if ( 'success' === $status && 'failed_only' === self::get_log_level() ) {
			return 0;
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

		$log_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a log entry is successfully written.
		 *
		 * @param int   $log_id Inserted log row ID.
		 * @param array $entry  The sanitized log entry.
		 */
		do_action( 'bale_connector_entry_logged', $log_id, $entry );

		self::maybe_sweep_retention();

		return $log_id;
	}

	/**
	 * Get the configured log level.
	 *
	 * @return string 'all' or 'failed_only'.
	 */
	public static function get_log_level() {
		$level = get_option( 'bale_connector_log_level', 'all' );
		return ( 'failed_only' === $level ) ? 'failed_only' : 'all';
	}

	/**
	 * Build the WHERE clause + params shared by query/count for the admin
	 * log filters (source_type, status, date range, search).
	 *
	 * All values are strictly whitelisted/absint'd before interpolation, so
	 * the resulting SQL is safe; placeholders are still used for strings.
	 *
	 * @param array $args Filter args (source_type, status, date_from, date_to, search).
	 * @return array { string $where, array $params }
	 */
	private static function build_filter_where( $args ) {
		$where  = 'WHERE 1=1';
		$params = array();

		$source_type = isset( $args['source_type'] ) ? sanitize_key( $args['source_type'] ) : '';
		if ( '' !== $source_type ) {
			$where   .= ' AND source_type = %s';
			$params[] = $source_type;
		}

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		if ( in_array( $status, array( 'success', 'failed' ), true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		$date_from = isset( $args['date_from'] ) ? sanitize_text_field( (string) $args['date_from'] ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		$date_to = isset( $args['date_to'] ) ? sanitize_text_field( (string) $args['date_to'] ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$search = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$escaped  = str_replace( array( '%', '_' ), array( '\\%', '\\_' ), $search );
			$like     = '%' . $escaped . '%';
			$where   .= ' AND ( source_ref LIKE %s OR recipient_chat_id LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		return array( $where, $params );
	}

	/**
	 * Query log rows for the admin list table.
	 *
	 * @param array $args {
	 *     @type string $source_type Filter by source_type.
	 *     @type string $status      Filter by status.
	 *     @type string $date_from   Y-m-d lower bound (inclusive).
	 *     @type string $date_to     Y-m-d upper bound (inclusive).
	 *     @type string $search      Match source_ref / recipient_chat_id.
	 *     @type int    $per_page    Rows per page.
	 *     @type int    $paged       1-based page number.
	 *     @type string $orderby     Column to order by (whitelisted).
	 *     @type string $order       ASC|DESC.
	 * }
	 * @return array Array of row arrays.
	 */
	public static function query_items( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'per_page' => 20,
				'paged'    => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		list( $where, $params ) = self::build_filter_where( $args );

		$allowed_orderby = array( 'id', 'created_at', 'source_type', 'status' );
		$orderby         = in_array( (string) $args['orderby'], $allowed_orderby, true ) ? (string) $args['orderby'] : 'created_at';
		$order           = ( 'ASC' === strtoupper( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$table = self::get_table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders fully prepared; identifiers whitelisted.
		$sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) );
		} else {
			$sql = $wpdb->prepare( $sql, $per_page, $offset );
		}

		$results = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count log rows matching the admin filters.
	 *
	 * @param array $args Same filter args as query_items() (per_page etc. ignored).
	 * @return int Row count.
	 */
	public static function count_items( $args ) {
		global $wpdb;

		list( $where, $params ) = self::build_filter_where( $args );

		$table = self::get_table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders fully prepared.
		$sql = "SELECT COUNT(*) FROM {$table} {$where}";
		if ( ! empty( $params ) ) {
			$sql     = $wpdb->prepare( $sql, $params );
		}
		$count = $wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Delete specific log rows by ID.
	 *
	 * @param array|int $ids Single ID or array of IDs.
	 * @return int Number of deleted rows.
	 */
	public static function delete_by_ids( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table  = self::get_table_name();
		$format = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders prepared below; table name code-defined.
		$sql      = "DELETE FROM {$table} WHERE id IN ( {$format} )";
		$deleted  = $wpdb->query( call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $ids ) ) );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Delete ALL log rows.
	 *
	 * @return int Number of deleted rows (best effort).
	 */
	public static function delete_all() {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name built from $wpdb->prefix only.
		$deleted = $wpdb->query( "DELETE FROM {$table}" );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Estimate the storage size of the logs table in bytes.
	 *
	 * Uses information_schema, which some restrictive shared hosts block.
	 *
	 * @return int|null Bytes, or null when the size cannot be determined.
	 */
	public static function get_table_size_bytes() {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fully prepared below.
		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ( data_length + index_length ) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);

		return is_numeric( $size ) ? (int) $size : null;
	}

	/**
	 * Prune oldest log rows until the table fits under the retention cap.
	 *
	 * MB-based cap (`bale_connector_log_retention_mb`); 0 disables cleanup.
	 * Deletes in chunks of 500 so a huge backlog never builds one giant
	 * query. Gracefully degrades when information_schema is unavailable
	 * (shared hosts commonly block it): the sweep is skipped and a notice is
	 * written to the error log — never an unhandled DB error.
	 *
	 * @param bool $force Run even when the hourly debounce window has not elapsed.
	 * @return int|bool Rows deleted, true when under cap, false on skip/failure.
	 */
	public static function prune_to_retention( $force = false ) {
		global $wpdb;

		$cap_mb = (int) get_option( 'bale_connector_log_retention_mb', 5 );
		if ( $cap_mb <= 0 ) {
			return true; // Cleanup disabled.
		}

		// Debounce: at most once per hour unless forced (e.g. AS daily sweep).
		if ( ! $force ) {
			$last = (int) get_option( 'bale_connector_last_retention_sweep', 0 );
			if ( ( time() - $last ) < self::RETENTION_SWEEP_INTERVAL ) {
				return true;
			}
		}
		update_option( 'bale_connector_last_retention_sweep', time() );

		$size_bytes = self::get_table_size_bytes();
		if ( null === $size_bytes ) {
			// information_schema blocked/unavailable — skip silently-ish.
			error_log( 'Bale Connector: log table size unavailable (information_schema blocked?) — retention cleanup skipped this cycle.' );
			return false;
		}

		$cap_bytes = $cap_mb * 1024 * 1024;
		if ( $size_bytes <= $cap_bytes ) {
			return true;
		}

		$total_deleted = 0;
		$chunk         = 500;

		while ( true ) {
			// Delete the oldest rows in chunks, then re-check size.
			$oldest = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id FROM ' . self::get_table_name() . ' ORDER BY id ASC LIMIT %d',
					$chunk
				),
				ARRAY_A
			);

			if ( ! is_array( $oldest ) || empty( $oldest ) ) {
				break;
			}

			$ids = wp_list_pluck( $oldest, 'id' );
			self::delete_by_ids( $ids );
			$total_deleted += count( $ids );

			$size_bytes = self::get_table_size_bytes();
			if ( null === $size_bytes || $size_bytes <= $cap_bytes ) {
				break;
			}
		}

		return $total_deleted;
	}

	/**
	 * Debounced retention sweep triggered after log writes.
	 */
	private static function maybe_sweep_retention() {
		self::prune_to_retention( false );
	}

	/**
	 * Schedule the daily Action Scheduler retention sweep.
	 *
	 * Uses Action Scheduler (AGENTS.md §3 convention), not raw wp-cron.
	 */
	public static function schedule_daily_sweep() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		if ( false === as_next_scheduled_action( 'bale_connector_retention_sweep', array(), 'bale-connector' ) ) {
			as_schedule_recurring_action(
				time() + 3600,
				86400,
				'bale_connector_retention_sweep',
				array(),
				'bale-connector'
			);
		}

		return true;
	}

	/**
	 * Action Scheduler callback: forced daily retention sweep.
	 */
	public static function run_daily_sweep() {
		self::prune_to_retention( true );
	}
}
