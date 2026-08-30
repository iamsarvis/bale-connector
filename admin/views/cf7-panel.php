<?php
/**
 * Bale Notification panel inside the CF7 form editor.
 *
 * Rendered by Bale_CF7_Admin_Panel::render_editor_panel(). Variables in
 * scope: $form_id (int), $enabled (bool), $template (string),
 * $selected_ids (int[]), $recipients (array of recipient rows).
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="bale-cf7-panel-wrap" class="bale-cf7-panel">
	<h2><?php esc_html_e( 'Bale Notification', 'bale-connector' ); ?></h2>
	<p><?php esc_html_e( 'Send a Bale message to your recipients whenever this form is successfully submitted.', 'bale-connector' ); ?></p>

	<p>
		<label>
			<input type="checkbox" id="bale-cf7-enabled" <?php checked( true, $enabled ); ?> />
			<?php esc_html_e( 'Send a Bale message when this form is submitted', 'bale-connector' ); ?>
		</label>
	</p>

	<h3><?php esc_html_e( 'Recipients', 'bale-connector' ); ?></h3>

	<?php if ( empty( $recipients ) ) : ?>
		<p>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: URL to the recipients admin page */
					__( 'No recipients defined yet. <a href="%s">Add recipients</a> first.', 'bale-connector' ),
					esc_url( admin_url( 'admin.php?page=bale-connector-recipients' ) )
				)
			);
			?>
		</p>
	<?php else : ?>
		<ul class="bale-cf7-recipients">
		<?php foreach ( $recipients as $recipient ) : ?>
			<?php
			$rid  = isset( $recipient['id'] ) ? absint( $recipient['id'] ) : 0;
			$cid  = isset( $recipient['chat_id'] ) ? $recipient['chat_id'] : '';
			$rlabel = isset( $recipient['label'] ) ? $recipient['label'] : '';
			$rtype = isset( $recipient['type'] ) ? $recipient['type'] : '';
			?>
			<li>
				<label>
					<input type="checkbox" class="bale-cf7-recipient" value="<?php echo esc_attr( $rid ); ?>" <?php checked( in_array( $rid, $selected_ids, true ) ); ?> />
					<?php echo esc_html( $rlabel ); ?>
					<span class="bale-cf7-recipient-meta"><?php echo esc_html( $cid . ' (' . $rtype . ')' ); ?></span>
				</label>
			</li>
		<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Message Template', 'bale-connector' ); ?></h3>
	<p>
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: 1: bold example, 2: italic example, 3: link example */
				__( 'Use %1$s tags and at most three formatting constructs: %2$s bold, %3$s italic, %4$s link. Submitted field values always render as plain text.', 'bale-connector' ),
				'<code>[tag]</code>',
				'<code>*text*</code>',
				'<code>_text_</code>',
				'<code>[text](url)</code>'
			)
		);
		?>
	</p>

	<textarea id="bale-cf7-template" rows="8" class="large-text code"><?php echo esc_textarea( $template ); ?></textarea>
	<p class="description">
		<span id="bale-cf7-counter">0</span>
	</p>

	<p>
		<button type="button" class="button button-secondary" id="bale-cf7-preview-btn"><?php esc_html_e( 'Preview Message', 'bale-connector' ); ?></button>
		<?php if ( ! empty( $recipients ) ) : ?>
			<select id="bale-cf7-test-recipient">
				<option value=""><?php esc_html_e( 'Test send to…', 'bale-connector' ); ?></option>
				<?php foreach ( $recipients as $recipient ) : ?>
					<option value="<?php echo esc_attr( $recipient['id'] ); ?>"><?php echo esc_html( $recipient['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button button-secondary" id="bale-cf7-test-send"><?php esc_html_e( 'Send Test', 'bale-connector' ); ?></button>
		<?php endif; ?>
	</p>

	<div id="bale-cf7-preview-box" class="bale-cf7-preview" hidden></div>

	<input type="hidden" id="bale-cf7-form-id" value="<?php echo esc_attr( $form_id ); ?>" />
</div>
