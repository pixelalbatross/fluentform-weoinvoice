<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Portuguese NIF (tax number) normalization and checksum validation.
 */
class Nif {

	/**
	 * Strips whitespace and a leading PT country prefix, returning the bare digits.
	 *
	 * @param string $raw Raw tax-number input.
	 * @return string Normalized digits.
	 */
	public static function normalize( string $raw ): string {
		$trimmed = (string) preg_replace( '/\s+/', '', $raw );
		$trimmed = (string) preg_replace( '/^PT/i', '', $trimmed );

		return $trimmed;
	}

	/**
	 * Whether the value is a structurally valid PT NIF (9 digits, correct check digit).
	 *
	 * @param string $raw Raw tax-number input.
	 * @return bool
	 */
	public static function is_valid( string $raw ): bool {
		$nif    = self::normalize( $raw );
		$length = strlen( $nif );

		if ( ! ctype_digit( $nif ) || 9 !== $length ) {
			return false;
		}

		$check = (int) $nif[0] * 9;
		for ( $i = 2; $i <= 8; $i++ ) {
			$check += (int) $nif[ $i - 1 ] * ( 10 - $i );
		}
		$check = 11 - ( $check % 11 );
		if ( $check >= 10 ) {
			$check = 0;
		}

		return $check === (int) $nif[8];
	}
}
