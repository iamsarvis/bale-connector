<?php
/**
 * Per-form settings sanitization helpers for Bale Connector.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_CF7_Form_Settings {

	/**
	 * Sanitize a message template.
	 *
	 * Uses sanitize_textarea_field: strips tags/control chars but preserves
	 * newlines (needed for multi-line Bale messages) and, critically, does
	 * NOT strip Bale's formatting characters (* _ [ ] ( )) — those belong to
	 * the admin-authored template and must survive sanitization. Escaping of
	 * SUBMITTED values happens later, at render time (Bale_Template).
	 *
	 * @param string $template Raw template from the admin.
	 * @return string Sanitized template.
	 */
	public static function sanitize_template( $template ) {
		return sanitize_textarea_field( wp_unslash( (string) $template ) );
	}
}
