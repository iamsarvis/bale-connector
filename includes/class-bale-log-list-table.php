<?php
/**
 * WP_List_Table based admin log viewer for Bale Connector.
 *
 * SECURITY (Phase 5 Definition of Done): every value rendered here comes
 * straight from the logs table and may contain arbitrary text submitted by
 * anonymous form visitors (payload/response hold raw JSON of what was sent,
 * including personal data). Every single field MUST pass through esc_html()
 * / esc_attr() AT RENDER TIME — data is never sanitized before storage so
 * raw log data stays intact for debugging. No exceptions, no "trusted"
 * columns.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the base class — it also lives in an admin-only include. The
// class_exists guard is the standard plugin idiom; the require is still
// guaranteed before any instantiation in real WP (the class is never
// pre-defined there).
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin log viewer list table.
 */
class Bale_Log_List_Table extends WP_List_Table {

	/**
	 * Rows for the current page.
	 *
	 * @var array
	 */
	private $items_data = array();

	/**
	 * Total matching rows (all pages).
	 *
	 * @var int
	 */
	private $total_items = 0;

	/**
	 * Human-readable labels for registered trigger slugs (incl. Pro).
	 *
	 * @var array
	 */
	private static $trigger_labels = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'bale_log',
				'plural'   => 'bale_logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Human-readable label for a source_type slug.
	 *
	 * @param string $slug Raw source_type slug.
	 * @return string Label (untranslated slug when unknown).
	 */
	public static function get_source_type_label( $slug ) {
		if ( null === self::$trigger_labels ) {
			$labels = array();

			foreach ( Bale_Logger::ALLOWED_SOURCES as $core_slug ) {
				$labels[ $core_slug ] = 'cf7' === $core_slug
					? __( 'Contact Form 7', 'bale-connector' )
					: sanitize_key( $core_slug );
			}

			$registered = apply_filters( 'bale_connector_registered_trigger_slugs', array() );
			if ( is_array( $registered ) ) {
				foreach ( $registered as $slug_value ) {
					$slug_value = sanitize_key( $slug_value );
					if ( '' === $slug_value || isset( $labels[ $slug_value ] ) ) {
						continue;
					}
					$labels[ $slug_value ] = sanitize_text_field( $slug_value );
				}
			}

			self::$trigger_labels = $labels;
		}

		$slug = (string) $slug;

		return isset( self::$trigger_labels[ $slug ] ) ? self::$trigger_labels[ $slug ] : $slug;
	}

	/**
	 * Read + normalize the current admin filters.
	 *
	 * @return array
	 */
	public static function get_current_filters() {
		$source_type = isset( $_REQUEST['source_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['source_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change.
		$status      = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from   = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to     = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search      = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby     = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order       = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array(
			'source_type' => $source_type,
			'status'      => in_array( $status, array( 'success', 'failed' ), true ) ? $status : '',
			'date_from'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ? $date_from : '',
			'date_to'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ? $date_to : '',
			'search'      => $search,
			'orderby'     => $orderby,
			'order'       => 'asc' === $order ? 'asc' : 'desc',
		);
	}

	/**
	 * Load data and set up pagination.
	 */
	public function prepare_items() {
		$filters = self::get_current_filters();

		$per_page_option = get_option( 'bale_connector_logs_per_page', 20 );
		$per_page        = max( 1, min( 200, (int) ( is_numeric( $per_page_option ) ? $per_page_option : 20 ) ) );

		$filtered = $filters;
		$filtered['per_page'] = $per_page;
		$filtered['paged']    = $this->get_pagenum();

		$this->items_data  = Bale_Logger::query_items( $filtered );
		$this->total_items = Bale_Logger::count_items( $filters );

		// WP_List_Table::display() iterates $this->items.
		$this->items = $this->items_data;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'created_at' );

		$this->set_pagination_args(
			array(
				'total_items' => $this->total_items,
				'per_page'    => $per_page,
				'total_pages' => ( $per_page > 0 ) ? (int) ceil( $this->total_items / $per_page ) : 1,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'                => '<input type="checkbox" />',
			'created_at'        => __( 'Date', 'bale-connector' ),
			'source_type'       => __( 'Source', 'bale-connector' ),
			'source_ref'        => __( 'Ref', 'bale-connector' ),
			'recipient_chat_id' => __( 'Recipient', 'bale-connector' ),
			'status'            => __( 'Status', 'bale-connector' ),
			'payload'           => __( 'Payload', 'bale-connector' ),
			'response'          => __( 'Response', 'bale-connector' ),
			'actions'           => __( 'Actions', 'bale-connector' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at'  => array( 'created_at', true ),
			'source_type' => array( 'source_type', false ),
			'status'      => array( 'status', false ),
		);
	}

	/**
	 * Bulk actions (checkbox column + dropdown). Deletion itself is handled
	 * by a POST handler in Bale_Admin — this only declares the option.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'bale_delete_logs' => __( 'Delete', 'bale-connector' ),
			'bale_delete_all_logs' => __( 'Delete All Logs', 'bale-connector' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="log_ids[]" value="%s" />',
			esc_attr( isset( $item['id'] ) ? $item['id'] : '' )
		);
	}

	/**
	 * Date column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$raw = isset( $item['created_at'] ) ? (string) $item['created_at'] : '';

		$timestamp = $raw ? mysql2date( 'U', $raw ) : false;
		if ( $timestamp ) {
			return esc_html( date_i18n( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $timestamp ) );
		}

		// Unparseable date: still escaped raw output.
		return esc_html( $raw );
	}

	/**
	 * Source type column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_source_type( $item ) {
		return esc_html( self::get_source_type_label( isset( $item['source_type'] ) ? $item['source_type'] : '' ) );
	}

	/**
	 * Source ref column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_source_ref( $item ) {
		return esc_html( isset( $item['source_ref'] ) ? $item['source_ref'] : '' );
	}

	/**
	 * Recipient column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_recipient_chat_id( $item ) {
		return esc_html( isset( $item['recipient_chat_id'] ) ? $item['recipient_chat_id'] : '' );
	}

	/**
	 * Status column with a badge.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_status( $item ) {
		$status = isset( $item['status'] ) ? (string) $item['status'] : '';

		if ( 'success' === $status ) {
			return '<span class="bale-log-badge bale-log-badge-success">' . esc_html__( 'Success', 'bale-connector' ) . '</span>';
		}

		if ( 'failed' === $status ) {
			return '<span class="bale-log-badge bale-log-badge-failed">' . esc_html__( 'Failed', 'bale-connector' ) . '</span>';
		}

		// Unknown status value: escape the raw value, never trust the DB.
		return esc_html( $status );
	}

	/**
	 * Payload column: truncated single-line preview + full JSON in an
	 * expandable block. ALL escaped at render time.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_payload( $item ) {
		return $this->render_json_cell( isset( $item['payload'] ) ? (string) $item['payload'] : '' );
	}

	/**
	 * Response column: same treatment as payload.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_response( $item ) {
		return $this->render_json_cell( isset( $item['response'] ) ? (string) $item['response'] : '' );
	}

	/**
	 * Shared renderer for the payload/response cells.
	 *
	 * @param string $json Raw JSON text from the DB.
	 * @return string HTML (fully escaped).
	 */
	private function render_json_cell( $json ) {
		if ( '' === $json || null === $json ) {
			return '&mdash;';
		}

		// Pretty-print when valid JSON (escaped below), otherwise show raw.
		// Direct json_encode() (not wp_json_encode()) so JSON_PRETTY_PRINT
		// renders with real characters on PHP 7.4, where the flag combination
		// JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE was added in 7.1+ but
		// wp_json_encode's arg contract predates it. Escaping to safe HTML
		// happens on every branch below regardless.
		$decoded = json_decode( $json, true );
		$pretty  = ( null !== $decoded || 'null' === trim( $json ) )
			? json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
			: $json;

		if ( ! is_string( $pretty ) || '' === $pretty ) {
			$pretty = $json;
		}

		$single = preg_replace( '/\s+/', ' ', $pretty );
		$full   = esc_html( $pretty );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			$length  = mb_strlen( $single, 'UTF-8' );
			$preview = ( $length > 120 ) ? mb_substr( $single, 0, 120, 'UTF-8' ) . '…' : $single;
		} else {
			$length  = strlen( $single );
			$preview = ( $length > 120 ) ? substr( $single, 0, 120 ) . '…' : $single;
		}

		$has_more = $length > 120;

		$html  = '<div class="bale-log-json">';
		$html .= '<span class="bale-log-json-preview">' . esc_html( $preview ) . '</span>';
		$html .= '<pre class="bale-log-json-full" hidden>' . $full . '</pre>';

		if ( $has_more ) {
			$html .= '<button type="button" class="button-link bale-log-toggle">' . esc_html__( 'Show more', 'bale-connector' ) . '</button>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Actions column: per-row Delete as an inline POST form (never a GET
	 * link — CSRF-safe, immune to link prefetchers and accidental clicks).
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_actions( $item ) {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		if ( ! $id ) {
			return '&mdash;';
		}

		$html  = '<form method="post" class="bale-row-delete-form">';
		$html .= wp_nonce_field( 'bale_delete_log', 'bale_log_nonce', true, false );
		$html .= '<input type="hidden" name="bale_log_action" value="delete" />';
		$html .= '<input type="hidden" name="log_id" value="' . esc_attr( $id ) . '" />';
		$html .= '<button type="submit" class="button-link bale-row-delete-btn">' . esc_html__( 'Delete', 'bale-connector' ) . '</button>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * Fallback column renderer: EVERY unknown column is escaped — this is
	 * the safety net that makes "no unescaped DB output" structural.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		$value = isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';

		if ( is_scalar( $value ) || null === $value ) {
			return esc_html( (string) $value );
		}

		return esc_html( wp_json_encode( $value ) );
	}

	/**
	 * No-items message.
	 */
	public function no_items() {
		esc_html_e( 'No log entries found.', 'bale-connector' );
	}

	/**
	 * Render the filter bar (source_type / status / date range).
	 */
	public function render_filter_bar() {
		$filters  = self::get_current_filters();
		$selected = $filters['source_type'];

		// Build the slug => label map once.
		self::get_source_type_label( '' );
		$map = self::$trigger_labels;

		echo '<div class="bale-log-filters">';
		echo '<input type="text" name="date_from" placeholder="YYYY-MM-DD" value="' . esc_attr( $filters['date_from'] ) . '" size="10" />';
		echo '<input type="text" name="date_to" placeholder="YYYY-MM-DD" value="' . esc_attr( $filters['date_to'] ) . '" size="10" />';

		echo '<select name="source_type"><option value="">' . esc_html__( 'All sources', 'bale-connector' ) . '</option>';
		foreach ( $map as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $selected, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'bale-connector' ) . '</option>';
		echo '<option value="success" ' . selected( $filters['status'], 'success', false ) . '>' . esc_html__( 'Success', 'bale-connector' ) . '</option>';
		echo '<option value="failed" ' . selected( $filters['status'], 'failed', false ) . '>' . esc_html__( 'Failed', 'bale-connector' ) . '</option>';
		echo '</select>';

		submit_button( __( 'Filter', 'bale-connector' ), 'secondary action', 'filter_action', false );
		echo '</div>';
	}
}
