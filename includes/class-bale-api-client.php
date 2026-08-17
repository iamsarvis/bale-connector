<?php
/**
 * Thin wrapper around the Bale Bot API.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bale_Api_Client
 *
 * A single, reusable, testable class wrapping the Bale Bot API.
 * Source for API behavior: https://docs.bale.ai/
 */
class Bale_Api_Client {

	/**
	 * HTTP timeout in seconds for every API call.
	 * Overrides WordPress's 5-second default.
	 */
	const HTTP_TIMEOUT = 15;

	/**
	 * Base URL for the Bale Bot API.
	 */
	const API_BASE = 'https://tapi.bale.ai/bot';

	/**
	 * Maximum text length for sendMessage (in characters).
	 * Source: docs.bale.ai §"sendMessage" — "بین ۱ تا ۴۰۹۶ کاراکتر".
	 */
	const TEXT_MAX = 4096;

	/**
	 * Maximum caption length for media methods (in characters).
	 *
	 * Source: docs.bale.ai §"InputMediaPhoto" and §"InputMediaDocument" type
	 * definitions — "بین ۰ تا ۱۰۲۴ کاراکتر".
	 *
	 * Note: The sendPhoto/sendDocument method parameter tables say 4096, but the
	 * InputMedia* type definitions say 1024. We use 1024 (the lower/conservative
	 * limit) per AGENTS.md §5. If the actual limit is higher, our guard is
	 * just stricter than necessary — safe.
	 */
	const CAPTION_MAX = 1024;

	/**
	 * Maximum file size for image uploads in bytes (10 MB).
	 * Source: docs.bale.ai §"ارسال فایل ها" —
	 * "حداکثر اندازه ممکن برای تصاویر ۱۰ مگابایت است" (multipart upload).
	 */
	const IMAGE_MAX_SIZE = 10485760;

	/**
	 * Maximum file size for non-image uploads in bytes (50 MB).
	 * Source: docs.bale.ai §"ارسال فایل ها" —
	 * "برای فایل‌های دیگر ۵۰ مگابایت" (multipart upload).
	 */
	const FILE_MAX_SIZE = 52428800;

	/**
	 * The plaintext bot token (decrypted by caller before passing in).
	 *
	 * @var string
	 */
	private $token = '';

	/**
	 * Constructor.
	 *
	 * @param string $bot_token Plaintext bot token.
	 */
	public function __construct( $bot_token ) {
		$this->token = $bot_token;
	}

	/**
	 * Validate the bot token by calling getMe.
	 *
	 * Source: docs.bale.ai §"getMe" — "یک متد ساده برای تست توکن احراز هویت بازو
	 * است. این متد به هیچ پارامتری نیاز ندارد و اطلاعات پایه بازو را به شکل
	 * یک شی User بر می‌گرداند."
	 *
	 * @return array|WP_Error User info array on success, WP_Error on failure.
	 */
	public function getMe() {
		return $this->request( 'getMe', array() );
	}

	/**
	 * Get up-to-date info about a chat (validates reachability).
	 *
	 * Source: docs.bale.ai §"getChat" — "این متد به منظور دریافت اطلاعات به‌روز
	 * در مورد گفتگو استفاده می‌شود. در صورت اجرای موفق، یک شی ChatFullInfo
	 * بر می‌گرداند."
	 *
	 * @param mixed $chat_id Integer or @username string.
	 * @return array|WP_Error ChatFullInfo array on success, WP_Error on failure.
	 */
	public function getChat( $chat_id ) {
		$valid = $this->validate_chat_id( $chat_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return $this->request( 'getChat', array( 'chat_id' => $chat_id ) );
	}

	/**
	 * Send a text message.
	 *
	 * @param mixed  $chat_id Integer or @username string.
	 * @param string $text    Message text (1–4096 chars).
	 * @param array  $args    Optional. Extra params (reply_to_message_id, reply_markup, etc.).
	 * @return array|WP_Error Sent message array on success, WP_Error on failure.
	 */
	public function sendMessage( $chat_id, $text, $args = array() ) {
		$valid = $this->validate_chat_id( $chat_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$limit_check = $this->enforce_char_limit( $text, self::TEXT_MAX, 'text' );
		if ( is_wp_error( $limit_check ) ) {
			return $limit_check;
		}

		$params = array_merge( array( 'chat_id' => $chat_id, 'text' => $text ), $args );

		return $this->request( 'sendMessage', $params );
	}

	/**
	 * Send a photo.
	 *
	 * @param mixed  $chat_id    Integer or @username string.
	 * @param mixed  $photo      One of: a file_id (string), an HTTP URL (string),
	 *                           or an ABSOLUTE filesystem path (string) to upload
	 *                           via multipart/form-data. Relative paths are
	 *                           unreliable — they resolve against PHP's cwd,
	 *                           which differs between CLI, admin, and cron
	 *                           contexts. Always pass realpath()-resolved paths
	 *                           for uploads.
	 * @param string $caption    Optional. Caption (0–1024 chars).
	 * @param array  $args       Optional. Extra params.
	 * @return array|WP_Error Sent message array on success, WP_Error on failure.
	 */
	public function sendPhoto( $chat_id, $photo, $caption = '', $args = array() ) {
		$valid = $this->validate_chat_id( $chat_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( '' !== $caption ) {
			$limit_check = $this->enforce_char_limit( $caption, self::CAPTION_MAX, 'caption' );
			if ( is_wp_error( $limit_check ) ) {
				return $limit_check;
			}
		}

		/*
		 * File upload mode detection.
		 *
		 * Source: docs.bale.ai §"InputFile" type and §"ارسال فایل ها" —
		 * "باید با استفاده از multipart/form-data... ارسال شود" for file uploads.
		 * File_id and URL are sent as string values via JSON (no multipart needed).
		 *
		 * WordPress's WP_Http does NOT auto-build multipart bodies from array
		 * parameters. Both transports (Curl.php, Fsockopen.php) call
		 * http_build_query() on array bodies, producing application/x-www-form-urlencoded.
		 * We must manually construct the multipart body with an explicit boundary.
		 * Verified against WP 6.5 core source:
		 *   wp-includes/Requests/src/Transport/Curl.php: http_build_query($data, '', '&')
		 *   wp-includes/Requests/src/Transport/Fsockopen.php: http_build_query($data, '', '&')
		 */
		if ( $this->is_local_file( $photo ) ) {
			$params = array_merge( array( 'chat_id' => $chat_id ), $args );
			if ( '' !== $caption ) {
				$params['caption'] = $caption;
			}

			return $this->request( 'sendPhoto', $params, 'photo', $photo );
		}

		$params = array_merge( array( 'chat_id' => $chat_id, 'photo' => $photo ), $args );
		if ( '' !== $caption ) {
			$params['caption'] = $caption;
		}

		return $this->request( 'sendPhoto', $params );
	}

	/**
	 * Send a document.
	 *
	 * @param mixed  $chat_id   Integer or @username string.
	 * @param mixed  $document One of: a file_id (string), an HTTP URL (string),
	 *                          or an ABSOLUTE filesystem path (string) to upload
	 *                          via multipart/form-data. Relative paths are
	 *                          unreliable — they resolve against PHP's cwd,
	 *                          which differs between CLI, admin, and cron
	 *                          contexts. Always pass realpath()-resolved paths
	 *                          for uploads.
	 * @param string $caption   Optional. Caption (0–1024 chars).
	 * @param array  $args      Optional. Extra params.
	 * @return array|WP_Error Sent message array on success, WP_Error on failure.
	 */
	public function sendDocument( $chat_id, $document, $caption = '', $args = array() ) {
		$valid = $this->validate_chat_id( $chat_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( '' !== $caption ) {
			$limit_check = $this->enforce_char_limit( $caption, self::CAPTION_MAX, 'caption' );
			if ( is_wp_error( $limit_check ) ) {
				return $limit_check;
			}
		}

		// File upload mode detection — same rationale as sendPhoto above.
		if ( $this->is_local_file( $document ) ) {
			$params = array_merge( array( 'chat_id' => $chat_id ), $args );
			if ( '' !== $caption ) {
				$params['caption'] = $caption;
			}

			return $this->request( 'sendDocument', $params, 'document', $document );
		}

		$params = array_merge( array( 'chat_id' => $chat_id, 'document' => $document ), $args );
		if ( '' !== $caption ) {
			$params['caption'] = $caption;
		}

		return $this->request( 'sendDocument', $params );
	}

	/**
	 * Single HTTP gateway for all API calls.
	 *
	 * @param string $method     Bale API method name (e.g. 'getMe', 'sendMessage').
	 * @param array  $params     Request parameters.
	 * @param string $file_field Optional. Field name for file upload (e.g. 'photo', 'document').
	 *                           When set, $file_field_path is required.
	 * @param string $file_path  Optional. Local file path for multipart upload.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	private function request( $method, $params, $file_field = '', $file_path = '' ) {
		$url = self::API_BASE . $this->token . '/' . $method;

		$defaults = array(
			'timeout' => self::HTTP_TIMEOUT,
		);

		if ( '' !== $file_field && '' !== $file_path ) {
			// Multipart mode for file uploads.
			$body_result = $this->build_multipart_body( $params, $file_field, $file_path );
			if ( is_wp_error( $body_result ) ) {
				return $body_result;
			}

			$defaults['headers'] = array(
				'Content-Type' => 'multipart/form-data; boundary=' . $body_result['boundary'],
			);
			$defaults['body']    = $body_result['body'];
		} else {
			// JSON mode for all non-file calls.
			$defaults['headers'] = array(
				'Content-Type' => 'application/json',
			);
			$defaults['body']    = wp_json_encode( $params );
		}

		$response = wp_remote_post( $url, $defaults );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'bale_http_error',
				$response->get_error_message(),
				array( 'wp_error' => $response )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			return new WP_Error(
				'bale_invalid_response',
				sprintf(
					/* translators: 1: HTTP method name, 2: HTTP response code */
					__( 'Bale API returned an invalid (non-JSON) response for %1$s (HTTP %2$d).', 'bale-connector' ),
					$method,
					$code
				),
				array( 'raw_body' => $body, 'http_code' => $code )
			);
		}

		if ( ! isset( $decoded['ok'] ) || ! $decoded['ok'] ) {
			return $this->build_wp_error( $decoded, $method, $code );
		}

		if ( ! isset( $decoded['result'] ) ) {
			return new WP_Error(
				'bale_empty_result',
				sprintf(
					/* translators: %s: Bale API method name */
					__( 'Bale API call to %s succeeded but returned no result field.', 'bale-connector' ),
					$method
				),
				array( 'decoded' => $decoded )
			);
		}

		return $decoded['result'];
	}

	/**
	 * Build a multipart/form-data body manually.
	 *
	 * WordPress's WP_Http does NOT auto-build multipart bodies — both the cURL
	 * and Fsockopen transports call http_build_query() on array bodies, producing
	 * application/x-www-form-urlencoded. We construct the multipart body
	 * ourselves with an explicit boundary string.
	 *
	 * Per RFC 2046, CRLF (\r\n) is used between all header lines and boundary
	 * delimiters. The body terminates with the closing delimiter --<boundary>--
	 * (trailing --), not just --<boundary>.
	 *
	 * File validation: checks file exists, is readable, and size against Bale's
	 * documented multipart limits (10 MB images, 50 MB other files per
	 * docs.bale.ai §"ارسال فایل ها"). Rejects locally with a clear error rather
	 * than letting Bale's API return an obscure failure.
	 *
	 * @param array  $params     Non-file form fields.
	 * @param string $file_field Field name for the file (e.g. 'photo', 'document').
	 * @param string $file_path  Local filesystem path to the file.
	 * @return array|WP_Error Array with 'boundary' and 'body' keys, or WP_Error.
	 */
	private function build_multipart_body( $params, $file_field, $file_path ) {
		// Validate file exists and is readable.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'bale_file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'File not found: %s', 'bale-connector' ),
					$file_path
				),
				array( 'file_path' => $file_path )
			);
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'bale_file_not_readable',
				sprintf(
					/* translators: %s: file path */
					__( 'File is not readable: %s', 'bale-connector' ),
					$file_path
				),
				array( 'file_path' => $file_path )
			);
		}

		// Check file size against Bale's documented multipart limits.
		$file_size = filesize( $file_path );
		if ( false === $file_size ) {
			return new WP_Error(
				'bale_file_size_unknown',
				sprintf(
					/* translators: %s: file path */
					__( 'Unable to determine file size: %s', 'bale-connector' ),
					$file_path
				),
				array( 'file_path' => $file_path )
			);
		}

		$max_size = ( 'photo' === $file_field ) ? self::IMAGE_MAX_SIZE : self::FILE_MAX_SIZE;
		if ( $file_size > $max_size ) {
			return new WP_Error(
				'bale_file_too_large',
				sprintf(
					/* translators: 1: file size in bytes, 2: max allowed size in bytes, 3: file path */
					__( 'File size (%1$s bytes) exceeds the maximum allowed (%2$s bytes) for Bale API uploads: %3$s', 'bale-connector' ),
					number_format( $file_size ),
					number_format( $max_size ),
					$file_path
				),
				array(
					'file_path' => $file_path,
					'file_size' => $file_size,
					'max_size'  => $max_size,
				)
			);
		}

		$file_contents = file_get_contents( $file_path );
		if ( false === $file_contents ) {
			return new WP_Error(
				'bale_file_read_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to read file contents: %s', 'bale-connector' ),
					$file_path
				),
				array( 'file_path' => $file_path )
			);
		}

		// Generate a safe alphanumeric boundary string.
		$boundary = wp_generate_password( 24, false );

		// Detect MIME type.
		$mime_type = function_exists( 'mime_content_type' ) ? mime_content_type( $file_path ) : 'application/octet-stream';
		if ( false === $mime_type ) {
			$mime_type = 'application/octet-stream';
		}

		$file_name = basename( $file_path );

		// Build the multipart body using CRLF per RFC 2046.
		$body = '';

		// Non-file fields.
		foreach ( $params as $name => $value ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= $value . "\r\n";
		}

		// File field.
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $file_name . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $file_contents . "\r\n";

		// Closing delimiter — must end with -- (per RFC 2046).
		$body .= '--' . $boundary . '--' . "\r\n";

		return array(
			'boundary' => $boundary,
			'body'     => $body,
		);
	}

	/**
	 * Validate chat_id format before every outbound call.
	 *
	 * Source: docs.bale.ai parameter tables for sendMessage, sendPhoto,
	 * sendDocument, getChat — all document chat_id as:
	 *   Type: "String یا Integer"
	 *   Description: "شناسه منحصربه‌فرد گفتگو هدف یا نام‌ کاربری کانال هدف
	 *   (با فرمت @channelusername)"
	 * Source: Chat type definition — id is Integer, type is
	 * "private"/"group"/"channel".
	 *
	 * Valid formats:
	 * 1. Integer (or numeric string) — unique chat ID for any chat type.
	 * 2. String starting with @ — channel username (e.g. @channelusername).
	 *
	 * The docs do NOT document negative-integer group IDs (unlike Telegram).
	 * Integers of any sign are accepted; no special-casing for negative values.
	 *
	 * @param mixed $chat_id The chat_id to validate.
	 * @return true|WP_Error True if valid, WP_Error on invalid format.
	 */
	private function validate_chat_id( $chat_id ) {
		if ( '' === $chat_id || null === $chat_id ) {
			return new WP_Error(
				'invalid_chat_id',
				__( 'chat_id is required and cannot be empty.', 'bale-connector' )
			);
		}

		// Integer or numeric string.
		if ( is_numeric( $chat_id ) ) {
			return true;
		}

		// @channelusername format.
		if ( is_string( $chat_id ) && preg_match( '/^@[a-zA-Z0-9_]+$/', $chat_id ) ) {
			return true;
		}

		return new WP_Error(
			'invalid_chat_id',
			sprintf(
				/* translators: %s: the invalid chat_id value */
				__( 'Invalid chat_id format: %s. Must be an integer or @username string.', 'bale-connector' ),
				$chat_id
			),
			array( 'chat_id' => $chat_id )
		);
	}

	/**
	 * Enforce character limits before the HTTP call.
	 *
	 * Source: docs.bale.ai §"sendMessage" — text is "بین ۱ تا ۴۰۹۶ کاراکتر".
	 * Source: docs.bale.ai §"InputMediaPhoto"/"InputMediaDocument" — caption is
	 * "بین ۰ تا ۱۰۲۴ کاراکتر".
	 *
	 * @param string $text    The text or caption to check.
	 * @param int    $limit   Character limit.
	 * @param string $context 'text' or 'caption' (for error message).
	 * @return true|WP_Error True if within limit, WP_Error if exceeded.
	 */
	private function enforce_char_limit( $text, $limit, $context ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		if ( $length > $limit ) {
			return new WP_Error(
				'bale_char_limit_exceeded',
				sprintf(
					/* translators: 1: field context (text/caption), 2: current length, 3: max allowed */
					__( '%1$s length (%2$d) exceeds the maximum allowed (%3$d) for the Bale API.', 'bale-connector' ),
					$context,
					$length,
					$limit
				),
				array(
					'context' => $context,
					'length'  => $length,
					'limit'   => $limit,
				)
			);
		}

		return true;
	}

	/**
	 * Normalize an API failure response into a WP_Error.
	 *
	 * Source: docs.bale.ai §"ایجاد درخواست" — response format:
	 *   ok: boolean
	 *   result: (on success)
	 *   error_code: integer (on failure)
	 *   description: string (explains the result)
	 *   parameters: optional ResponseParameters with retry_after (integer)
	 *
	 * Source: docs.bale.ai §"ResponseParameters" type — "این شیء حاوی اطلاعات
	 * مربوط به یک درخواست ناموفق است" — retry_after: "در صورت عبور از محدودیت
	 * نرخ ارسال درخواست، تعداد ثانیه‌های باقیمانده تا زمانی که درخواست دوباره
	 * قابل ارسال باشد".
	 *
	 * The returned WP_Error carries:
	 *   - error code: "bale_api_error_<error_code>" (machine-readable for logging)
	 *   - message: Bale's description (human-readable)
	 *   - error data: array with error_code (int), description (string),
	 *     retry_after (int|null)
	 *
	 * @param array  $decoded        Decoded JSON response body.
	 * @param string $method         API method name.
	 * @param int    $http_code      HTTP response code.
	 * @return WP_Error Normalized error.
	 */
	private function build_wp_error( $decoded, $method, $http_code ) {
		$error_code    = isset( $decoded['error_code'] ) ? (int) $decoded['error_code'] : 0;
		$description   = isset( $decoded['description'] ) ? $decoded['description'] : '';
		$retry_after   = null;

		if ( isset( $decoded['parameters']['retry_after'] ) ) {
			$retry_after = (int) $decoded['parameters']['retry_after'];
		}

		$error_code_string = $error_code > 0 ? 'bale_api_error_' . $error_code : 'bale_api_error';

		$message = $description;
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: 1: Bale API method name, 2: HTTP status code */
				__( 'Bale API call to %1$s failed (HTTP %2$d).', 'bale-connector' ),
				$method,
				$http_code
			);
		}

		return new WP_Error(
			$error_code_string,
			$message,
			array(
				'error_code'  => $error_code,
				'description' => $description,
				'retry_after' => $retry_after,
				'http_code'   => $http_code,
				'method'      => $method,
			)
		);
	}

	/**
	 * Check if a value refers to a local file that should be uploaded via multipart.
	 *
	 * A file_id is a long opaque string (not a path). An HTTP URL starts with
	 * http:// or https://. A local file path is anything else that exists on
	 * the filesystem.
	 *
	 * The @ error-suppression operator on file_exists() is intentional: on
	 * servers with open_basedir or safe_mode restrictions, file_exists() can
	 * emit a PHP warning when the path falls outside the allowed directories.
	 * file_id strings (which are opaque tokens, not real paths) can trigger
	 * this. The suppression ensures no warning is ever logged or echoed —
	 * we only care about the boolean return, not the diagnostic. The result
	 * is correct in all cases: outside open_basedir -> false (not a file we
	 * can upload), inside and exists -> true.
	 *
	 * Callers must pass absolute filesystem paths for real uploads. Relative
	 * paths resolve against PHP's cwd, which varies between CLI, admin, and
	 * cron contexts — a relative path that works in admin may silently fail
	 * under Action Scheduler. Use realpath() before calling sendPhoto() or
	 * sendDocument() with a file.
	 *
	 * @param string $value The photo/document parameter.
	 * @return bool True if $value is a local file path.
	 */
	private function is_local_file( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		// URLs are sent as strings, not uploaded.
		if ( 0 === strpos( $value, 'http://' ) || 0 === strpos( $value, 'https://' ) ) {
			return false;
		}

		// file_id strings are opaque tokens, not file paths — they won't exist on disk.
		// The @ suppresses open_basedir warnings on paths outside allowed dirs.
		return @file_exists( $value );
	}
}
