<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\Feed;
use WP_UnitTestCase;

/**
 * Submission field mapping and global settings.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\Feed
 */
class FeedTest extends WP_UnitTestCase {

	/**
	 * Skips when Fluent Forms is not loaded (Feed extends an FF base class).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( \FluentForm\App\Http\Controllers\IntegrationManagerController::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}
	}

	/**
	 * A compound field mapped by dot-notation resolves the nested value.
	 *
	 * @return void
	 */
	public function test_map_submission_reads_dotted_field(): void {
		$feed = [
			'name_field' => 'names.first_name',
			'item_label' => 'Donativo',
		];

		$form_data = [
			'names' => [
				'first_name' => 'Ana',
				'last_name'  => 'Example',
			],
		];

		$mapped = Feed::map_submission( $feed, $form_data, '25.50' );

		$this->assertSame( 'Ana', $mapped['client']['name'] );
		$this->assertSame( '25.50', $mapped['lines'][0]['price'] );
		$this->assertSame( 'Donativo', $mapped['lines'][0]['item'] );
	}

	/**
	 * Missing optional field mappings degrade to empty strings.
	 *
	 * @return void
	 */
	public function test_map_submission_degrades_gracefully(): void {
		$feed = [
			'name_field' => 'names',
			'item_label' => 'Donativo',
		];

		$mapped = Feed::map_submission( $feed, [ 'names' => 'Ana Example' ], '10.00' );

		$this->assertSame( 'Ana Example', $mapped['client']['name'] );
		$this->assertSame( '', $mapped['client']['email'] );
		$this->assertSame( '', $mapped['client']['nif'] );
		$this->assertSame( '', $mapped['client']['country'] );
		$this->assertSame( '10.00', $mapped['lines'][0]['price'] );
	}

	/**
	 * The isConfigured check tracks whether the global API key is set.
	 *
	 * @return void
	 */
	public function test_is_configured_reflects_api_key(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );
		$this->assertTrue( ( new Feed( wpFluentForm() ) )->isConfigured() );

		update_option( Feed::OPTION_KEY, [ 'api_key' => '' ] );
		$this->assertFalse( ( new Feed( wpFluentForm() ) )->isConfigured() );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * The VAT M-code reads the stored value and defaults to M07 when empty.
	 *
	 * @return void
	 */
	public function test_global_taxreason_stored_and_default(): void {
		update_option( Feed::OPTION_KEY, [ 'taxreason' => 'M10' ] );
		$this->assertSame( 'M10', Feed::global_taxreason() );

		update_option( Feed::OPTION_KEY, [ 'taxreason' => '' ] );
		$this->assertSame( 'M07', Feed::global_taxreason() );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * The default country reads the stored value and defaults to PT when empty.
	 *
	 * @return void
	 */
	public function test_global_country_stored_and_default(): void {
		update_option( Feed::OPTION_KEY, [ 'country' => 'ES' ] );
		$this->assertSame( 'ES', Feed::global_country() );

		update_option( Feed::OPTION_KEY, [ 'country' => '' ] );
		$this->assertSame( 'PT', Feed::global_country() );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * The per-form feed defaults expose the expected mapping keys and enabled flag.
	 *
	 * @return void
	 */
	public function test_get_integration_defaults_shape(): void {
		$defaults = ( new Feed( wpFluentForm() ) )->getIntegrationDefaults( [], 7 );

		$this->assertArrayHasKey( 'enabled', $defaults );
		$this->assertTrue( $defaults['enabled'] );
		$this->assertArrayHasKey( 'nif_field', $defaults );
		$this->assertArrayNotHasKey( 'amount_field', $defaults );
		$this->assertArrayHasKey( 'conditionals', $defaults );
	}

	/**
	 * The notify method is a deliberate no-op (emission happens on payment status change).
	 *
	 * @return void
	 */
	public function test_notify_is_a_noop(): void {
		$this->expectNotToPerformAssertions();
		( new Feed( wpFluentForm() ) )->notify( [], [], (object) [], (object) [] );
	}
}
