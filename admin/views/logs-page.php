<?php
/**
 * Logs admin view: WP_List_Table log viewer with filters and POST-only
 * delete actions.
 *
 * SECURITY: every value rendered from the logs table (payload, response,
 * recipient_chat_id, source_ref, source_type, status, dates) is escaped at
 * render time inside Bale_Log_List_Table — raw data is stored untouched and
 * escaped only here, at display.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$log_table = new Bale_Log_List_Table();
$log_table->prepare_items();
?>
<div class="wrap bale-connector-wrap">
	<h1><?php esc_html_e( 'Bale Delivery Logs', 'bale-connector' ); ?></h1>
	<p><?php esc_html_e( 'View outbound message delivery statuses and payloads.', 'bale-connector' ); ?></p>

	<?php $admin_notices = new Bale_Admin(); ?>
	<?php $admin_notices->render_log_action_notices(); ?>

	<form method="get" class="bale-log-filter-form">
		<input type="hidden" name="page" value="<?php echo esc_attr( 'bale-connector-logs' ); ?>" />
		<?php $log_table->render_filter_bar(); ?>
	</form>

	<form method="post" id="bale-logs-bulk-form">
		<?php wp_nonce_field( 'bale_bulk_logs', 'bale_log_nonce' ); ?>
		<?php $log_table->display(); ?>
	</form>

	<form method="post" class="bale-delete-all-form">
		<?php wp_nonce_field( 'bale_delete_all_logs', 'bale_log_nonce' ); ?>
		<input type="hidden" name="bale_log_action" value="delete_all" />
		<button type="submit" class="button bale-delete-all" id="bale-delete-all-btn">
			<?php esc_html_e( 'Delete All Logs', 'bale-connector' ); ?>
		</button>
	</form>
</div>
