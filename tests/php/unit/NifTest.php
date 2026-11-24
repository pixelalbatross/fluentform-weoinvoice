<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\Nif;
use WP_UnitTestCase;

/**
 * PT NIF normalization and checksum validation.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\Nif
 */
class NifTest extends WP_UnitTestCase {

	/**
	 * Normalize strips a leading PT prefix and all whitespace.
	 *
	 * @return void
	 */
	public function test_strips_pt_prefix_and_spaces(): void {
		$this->assertSame( '123456789', Nif::normalize( ' PT 123 456 789 ' ) );
	}

	/**
	 * A number with a genuine check digit validates, with or without the PT prefix.
	 *
	 * @return void
	 */
	public function test_valid_nif_passes_checksum(): void {
		// Fabricated placeholder: 123456789 carries a genuine PT check digit (9).
		$this->assertTrue( Nif::is_valid( '123456789' ) );
		$this->assertTrue( Nif::is_valid( 'PT123456789' ) );
	}

	/**
	 * A number whose check digit does not match fails.
	 *
	 * @return void
	 */
	public function test_invalid_checksum_fails(): void {
		// 111111111 fails the checksum: computed check digit is 0, not the trailing 1.
		$this->assertFalse( Nif::is_valid( '111111111' ) );
	}

	/**
	 * Empty or wrong-length input fails.
	 *
	 * @return void
	 */
	public function test_wrong_length_or_empty_fails(): void {
		$this->assertFalse( Nif::is_valid( '' ) );
		$this->assertFalse( Nif::is_valid( '12345' ) );
	}

	/**
	 * Normalize strips a lowercase `pt` prefix (case-insensitive).
	 *
	 * @return void
	 */
	public function test_normalize_strips_lowercase_pt(): void {
		$this->assertSame( '123456789', Nif::normalize( 'pt123456789' ) );
		$this->assertSame( '123456789', Nif::normalize( "\t123 456 789\n" ) );
	}

	/**
	 * Non-numeric input (letters or exponent notation) fails the digit guard.
	 *
	 * @return void
	 */
	public function test_non_numeric_input_fails(): void {
		// The PT prefix is stripped, leaving non-digits behind.
		$this->assertFalse( Nif::is_valid( 'PT1234ABCD9' ) );
		$this->assertFalse( Nif::is_valid( '12E456789' ) );
	}

	/**
	 * Nine-digit values that fail the mod-11 checksum are rejected regardless of leading digit.
	 *
	 * @return void
	 */
	public function test_bad_checksum_values_fail(): void {
		// Both are fabricated nine-digit values whose trailing digit does not match the checksum.
		$this->assertFalse( Nif::is_valid( '012345678' ) );
		$this->assertFalse( Nif::is_valid( '412345678' ) );
	}

	/**
	 * Validation rests on length plus checksum only; any leading digit passes when the checksum does.
	 *
	 * @return void
	 */
	public function test_any_leading_digit_validates_on_checksum(): void {
		// Fabricated placeholder: 450000001 carries a genuine PT check digit (1).
		$this->assertTrue( Nif::is_valid( '450000001' ) );
	}

	/**
	 * A NIF whose computed check digit collapses to 0 (mod-11 remainder <= 1) validates.
	 *
	 * @return void
	 */
	public function test_check_digit_collapse_to_zero_is_valid(): void {
		// Fabricated placeholder: for 10000001 the weighted sum yields a check digit >= 10,
		// which the algorithm collapses to 0, matching the trailing 0.
		$this->assertTrue( Nif::is_valid( '100000010' ) );
	}
}
