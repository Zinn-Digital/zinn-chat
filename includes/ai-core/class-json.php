<?php
/**
 * Reading JSON out of a model's answer.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-json.php by wp/bin/build-ai-core.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnChat\AiCore
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\AiCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON helpers.
 */
final class Json {

	/**
	 * The JSON object in a model's text, or null.
	 *
	 * ⭐ Providers that enforce a schema return bare JSON. The ones that only honour a prompt often
	 * wrap it in a code fence or a sentence, so the first balanced object is taken — but ONLY an
	 * object that decodes; a half-answer is refused rather than guessed at.
	 *
	 * @param string $text Model output.
	 * @return array<mixed>|null
	 */
	public static function object_from( string $text ): ?array {
		$text    = trim( $text );
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}
		$depth  = 0;
		$quoted = false;
		$escape = false;
		$length = strlen( $text );
		for ( $i = $start; $i < $length; $i++ ) {
			$char = $text[ $i ];
			if ( $quoted ) {
				if ( $escape ) {
					$escape = false;
				} elseif ( '\\' === $char ) {
					$escape = true;
				} elseif ( '"' === $char ) {
					$quoted = false;
				}
				continue;
			}
			if ( '"' === $char ) {
				$quoted = true;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					$decoded = json_decode( substr( $text, $start, $i - $start + 1 ), true );
					return is_array( $decoded ) ? $decoded : null;
				}
			}
		}

		return null;
	}
}
