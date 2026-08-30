<?php
/**
 * Message template renderer for Bale Connector.
 *
 * Substitutes [tag] placeholders into an admin-authored template and enforces
 * the Bale formatting security boundary:
 *
 * - Only the ADMIN-AUTHORED template text may contain Bale markup
 *   (*bold*, _italic_, [text](url)).
 * - USER-SUBMITTED field values are ALWAYS escaped: the six special
 *   formatting characters (*, _, [, ], (, )) are prefixed with U+200B
 *   (zero-width space). Bale's Markdown-style parser no longer recognizes
 *   them as formatting constructs, so they render as literal plain text.
 *   U+200B is invisible in Bale and is not part of Bale's documented
 *   formatting syntax, so the display text is unchanged.
 *
 * Threat model: without escaping, a malicious form submitter could type
 * [Free Prize](https://evil.example) into a text field and have it rendered
 * as a clickable phishing link inside the notification sent to the site
 * owner's Bale account. Escaping at render time (not at storage time) keeps
 * the raw submitted data intact in the logs for debugging.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_Template {

	/**
	 * Characters that trigger Bale's formatting parser.
	 *
	 * Only these three constructs are documented by Bale (AGENTS.md §5):
	 * *bold*, _italic_, and [text](url). All six delimiters get escaped in
	 * user-submitted values.
	 */
	const BALE_FORMATTING_CHARS = array( '*', '_', '[', ']', '(', ')' );

	/**
	 * Zero-width space used to break up Bale formatting sequences.
	 */
	const ZWSP = "\xE2\x80\x8B"; // UTF-8 bytes for U+200B.

	/**
	 * Render a template with tag substitution.
	 *
	 * Tags may be plain ([your-name]) or post-fixed with a length limit,
	 * e.g. [your-name:100] renders at most 100 characters of that value.
	 *
	 * @param string $template   Admin-authored template with [tags].
	 * @param array  $field_values Map of tag name => submitted value. Values
	 *                             are user-submitted and get Bale-escaped.
	 * @param array  $extra_tags   Optional. Additional trusted tag =>
	 *                             literal value pairs injected verbatim
	 *                             WITHOUT escaping (site-owner controlled,
	 *                             e.g. form title). Default empty array.
	 * @return string Rendered message, ready for Bale sendMessage.
	 */
	public static function render( $template, $field_values, $extra_tags = array() ) {
		$template = (string) $template;

		/**
		 * Filter the template before rendering.
		 *
		 * @param string $template     Raw template.
		 * @param array  $field_values Submitted field values.
		 * @param array  $extra_tags   Trusted extra tags.
		 */
		$template = apply_filters( 'bale_connector_template_raw', $template, $field_values, $extra_tags );

		$callback = function ( $matches ) use ( $field_values, $extra_tags ) {
			$tag   = isset( $matches[1] ) ? $matches[1] : '';
			$limit = isset( $matches[2] ) ? (int) $matches[2] : 0;

			// Trusted site-owner tags are substituted verbatim.
			if ( array_key_exists( $tag, $extra_tags ) ) {
				$value = (string) $extra_tags[ $tag ];
			} else {
				$value = isset( $field_values[ $tag ] ) ? $field_values[ $tag ] : '';

				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}

				$value = self::escape_bale_markup( (string) $value );
			}

			if ( $limit > 0 && function_exists( 'mb_substr' ) ) {
				$value = mb_substr( $value, 0, $limit );
			}

			return $value;
		};

		$rendered = preg_replace_callback( '/\[([a-zA-Z0-9._-]+)(?::(\d+))?\]/', $callback, $template );

		if ( null === $rendered ) {
			// Regex failure is not expected here; fail safe to a sanitized template.
			$rendered = self::escape_bale_markup( $template );
		}

		/**
		 * Filter the rendered message before it is sent to Bale.
		 *
		 * @param string $rendered     Rendered message text.
		 * @param string $template     Original template.
		 * @param array  $field_values Submitted field values.
		 */
		return apply_filters( 'bale_connector_message', $rendered, $template, $field_values );
	}

	/**
	 * Escape Bale's special formatting characters in user-submitted text.
	 *
	 * Every one of * _ [ ] ( ) is followed by a zero-width space so that no
	 * formatting construct can be formed:
	 *   [text](url) becomes [​text]​(​url)  — no link is parsed.
	 *   *bold*      becomes *​bold*​      — no bold is applied.
	 *
	 * Only submitted field values pass through here. The admin template
	 * itself is NEVER escaped, so admin formatting still renders.
	 *
	 * @param string $value User-submitted value.
	 * @return string Escaped value (displays identically in Bale).
	 */
	public static function escape_bale_markup( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		// Append ZWSP after every formatting-significant character.
		return preg_replace(
			'/[*_\[\]()]/u',
			'$0' . self::ZWSP,
			$value
		);
	}
}
