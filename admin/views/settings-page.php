<?php
/**
 * Settings admin view.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap bale-connector-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<?php settings_errors(); ?>
	<form action="options.php" method="post">
		<?php
		settings_fields( 'bale_connector_settings_group' );
		do_settings_sections( 'bale-connector' );
		submit_button( __( 'Save Changes', 'bale-connector' ) );
		?>
	</form>
</div>
