<?php
/**
 * Recipients admin view.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$recipients = Bale_Recipients::get_all( array( 'orderby' => 'id', 'order' => 'DESC' ) );
?>
<div class="wrap bale-connector-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Bale Recipients', 'bale-connector' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Manage your notification recipients (users, groups, and channels) and test their reachability.', 'bale-connector' ); ?>
	</p>

	<div class="bale-recipients-container">
		<!-- Left Column: Add / Edit Form -->
		<div class="bale-recipient-form-wrap card">
			<h2 id="bale-form-title"><?php esc_html_e( 'Add New Recipient', 'bale-connector' ); ?></h2>
			<form id="bale-recipient-form">
				<input type="hidden" name="recipient_id" id="recipient_id" value="0" />

				<div class="bale-form-group">
					<label for="recipient_label"><?php esc_html_e( 'Label / Name', 'bale-connector' ); ?> <span class="required">*</span></label>
					<input type="text"
						   id="recipient_label"
						   name="recipient_label"
						   class="regular-text"
						   placeholder="<?php esc_attr_e( 'e.g. Sales Team or Admin', 'bale-connector' ); ?>"
						   required />
					<p class="description"><?php esc_html_e( 'A human-friendly name to identify this recipient in form mappings.', 'bale-connector' ); ?></p>
				</div>

				<div class="bale-form-group">
					<label for="recipient_type"><?php esc_html_e( 'Recipient Type', 'bale-connector' ); ?> <span class="required">*</span></label>
					<select id="recipient_type" name="recipient_type" class="regular-text">
						<option value="user"><?php esc_html_e( 'Person (User)', 'bale-connector' ); ?></option>
						<option value="group"><?php esc_html_e( 'Group', 'bale-connector' ); ?></option>
						<option value="channel"><?php esc_html_e( 'Channel', 'bale-connector' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Select whether the target is a private user, group chat, or channel.', 'bale-connector' ); ?></p>
				</div>

				<div class="bale-form-group">
					<label for="recipient_chat_id"><?php esc_html_e( 'Chat ID / Username', 'bale-connector' ); ?> <span class="required">*</span></label>
					<input type="text"
						   id="recipient_chat_id"
						   name="recipient_chat_id"
						   class="regular-text"
						   placeholder="<?php esc_attr_e( 'e.g. 123456789 or @channelusername', 'bale-connector' ); ?>"
						   required />
					<p class="description">
						<?php esc_html_e( 'User/Group numerical Chat ID, or @channelusername for public channels.', 'bale-connector' ); ?>
					</p>
				</div>

				<div class="bale-form-actions">
					<button type="submit" class="button button-primary" id="bale-save-recipient-btn">
						<?php esc_html_e( 'Save Recipient', 'bale-connector' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="bale-cancel-edit-btn" style="display: none;">
						<?php esc_html_e( 'Cancel', 'bale-connector' ); ?>
					</button>
				</div>

				<div id="bale-form-feedback" class="bale-feedback" style="display: none;"></div>
			</form>
		</div>

		<!-- Right Column: Recipients Table -->
		<div class="bale-recipients-list-wrap">
			<table class="wp-list-table widefat fixed striped table-view-list" id="bale-recipients-table">
				<thead>
					<tr>
						<th scope="col" class="manage-column column-id" style="width: 50px;"><?php esc_html_e( 'ID', 'bale-connector' ); ?></th>
						<th scope="col" class="manage-column column-label"><?php esc_html_e( 'Label', 'bale-connector' ); ?></th>
						<th scope="col" class="manage-column column-chat-id"><?php esc_html_e( 'Chat ID', 'bale-connector' ); ?></th>
						<th scope="col" class="manage-column column-type" style="width: 90px;"><?php esc_html_e( 'Type', 'bale-connector' ); ?></th>
						<th scope="col" class="manage-column column-status" style="width: 140px;"><?php esc_html_e( 'Connection', 'bale-connector' ); ?></th>
						<th scope="col" class="manage-column column-actions" style="width: 180px;"><?php esc_html_e( 'Actions', 'bale-connector' ); ?></th>
					</tr>
				</thead>
				<tbody id="bale-recipients-tbody">
					<?php if ( empty( $recipients ) ) : ?>
						<tr class="no-items">
							<td colspan="6"><?php esc_html_e( 'No recipients configured yet. Add your first recipient using the form on the left.', 'bale-connector' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $recipients as $recipient ) : ?>
							<?php
							$row_id      = (int) $recipient['id'];
							$type_label  = ucfirst( $recipient['type'] );
							if ( 'user' === $recipient['type'] ) {
								$type_label = __( 'Person', 'bale-connector' );
							} elseif ( 'group' === $recipient['type'] ) {
								$type_label = __( 'Group', 'bale-connector' );
							} elseif ( 'channel' === $recipient['type'] ) {
								$type_label = __( 'Channel', 'bale-connector' );
							}

							$test_status = ! empty( $recipient['last_test_status'] ) ? $recipient['last_test_status'] : 'untested';
							$status_text = __( 'Untested', 'bale-connector' );
							if ( 'success' === $test_status ) {
								$status_text = __( 'Connected', 'bale-connector' );
							} elseif ( 'failed' === $test_status ) {
								$status_text = __( 'Failed', 'bale-connector' );
							}
							?>
							<tr id="bale-recipient-row-<?php echo esc_attr( $row_id ); ?>"
								data-id="<?php echo esc_attr( $row_id ); ?>"
								data-label="<?php echo esc_attr( $recipient['label'] ); ?>"
								data-chat-id="<?php echo esc_attr( $recipient['chat_id'] ); ?>"
								data-type="<?php echo esc_attr( $recipient['type'] ); ?>">
								<td><?php echo esc_html( $row_id ); ?></td>
								<td><strong><?php echo esc_html( $recipient['label'] ); ?></strong></td>
								<td><code><?php echo esc_html( $recipient['chat_id'] ); ?></code></td>
								<td><span class="bale-badge bale-badge-<?php echo esc_attr( $recipient['type'] ); ?>"><?php echo esc_html( $type_label ); ?></span></td>
								<td class="bale-status-cell">
									<span class="bale-status-indicator bale-status-<?php echo esc_attr( $test_status ); ?>" title="<?php echo esc_attr( ! empty( $recipient['last_tested_at'] ) ? sprintf( __( 'Last tested: %s', 'bale-connector' ), $recipient['last_tested_at'] ) : '' ); ?>">
										<?php echo esc_html( $status_text ); ?>
									</span>
								</td>
								<td class="bale-actions-cell">
									<button type="button" class="button button-small bale-test-btn" data-id="<?php echo esc_attr( $row_id ); ?>" data-chat-id="<?php echo esc_attr( $recipient['chat_id'] ); ?>">
										<?php esc_html_e( 'Test', 'bale-connector' ); ?>
									</button>
									<button type="button" class="button button-small bale-edit-btn" data-id="<?php echo esc_attr( $row_id ); ?>">
										<?php esc_html_e( 'Edit', 'bale-connector' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete bale-delete-btn" data-id="<?php echo esc_attr( $row_id ); ?>">
										<?php esc_html_e( 'Delete', 'bale-connector' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<div id="bale-list-feedback" class="bale-feedback" style="display: none; margin-top: 15px;"></div>
		</div>
	</div>
</div>
