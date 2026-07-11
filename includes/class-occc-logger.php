<?php
/**
 * Redacted, protected-folder logger.
 *
 * Writes to wp-content/uploads/occc-logs/ behind a deny-all .htaccess + index.php,
 * and NEVER logs a full PAN, CVV or the raw token value. Only card-safe metadata
 * (last 4, expiry, brand) and API status codes go to disk.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Logger {

	/**
	 * Absolute path to the log directory.
	 *
	 * @return string
	 */
	private static function dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'occc-logs';
	}

	/**
	 * Create the protected log directory on activation.
	 *
	 * @return void
	 */
	public static function install() {
		$dir = self::dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Belt-and-braces: block direct web access.
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	/**
	 * Append a log line, only when debug logging is enabled in settings.
	 *
	 * @param string $label Short label.
	 * @param mixed  $data  Payload (arrays/objects are JSON-encoded and redacted).
	 * @return void
	 */
	public static function log( $label, $data = '' ) {
		if ( ! OCCC_Settings::get_bool( 'debug_logging' ) ) {
			return;
		}

		self::install();

		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $label . ' :: ' . self::stringify( $data ) . "\n";
		file_put_contents(
			trailingslashit( self::dir() ) . 'occc-' . gmdate( 'Y-m-d' ) . '.log',
			self::redact( $line ),
			FILE_APPEND | LOCK_EX
		);
	}

	/**
	 * Normalise any payload to a string.
	 *
	 * @param mixed $data Payload.
	 * @return string
	 */
	private static function stringify( $data ) {
		if ( is_string( $data ) ) {
			return $data;
		}
		return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Redact anything that looks like a PAN, CVV, or a full token value.
	 *
	 * @param string $text Line to redact.
	 * @return string
	 */
	private static function redact( $text ) {
		// 13-19 digit card numbers -> keep last 4.
		$text = preg_replace_callback(
			'/\b(\d{9,15})(\d{4})\b/',
			function ( $m ) {
				return str_repeat( '*', strlen( $m[1] ) ) . $m[2];
			},
			$text
		);
		// Token values in JSON ("Token":"...") -> mask the middle.
		$text = preg_replace( '/("Token"\s*:\s*")([^"]{0,6})[^"]*(")/i', '$1$2***$3', $text );
		// CVV-ish fields.
		$text = preg_replace( '/("(?:CVV|CVV2|Cvv)"\s*:\s*")[^"]*(")/i', '$1***$2', $text );
		return $text;
	}
}
