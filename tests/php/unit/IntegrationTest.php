<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\Integration;
use Outstand\WP\FluentForm\Addons\WeoInvoice\Feed;
use WP_UnitTestCase;

/**
 * Feed field mapping and global-settings resolution.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\Integration
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\Feed
 */
class IntegrationTest extends WP_UnitTestCase {

	/**
	 * Skips when Fluent Forms is not loaded.
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
	 * Pulls mapped values into a client and a single line item.
	 *
	 * @return void
	 */
	public function test_map_submission_builds_client_and_lines(): void {
		$feed = [
			'name_field'  => 'names',
			'email_field' => 'email',
			'nif_field'   => 'nif',
			'item_label'  => 'Donativo',
		];
		$data = [
			'names' => 'Ana Example',
			'email' => 'ana@example.test',
			'nif'   => 'PT123456789',
		];

		$mapped = Feed::map_submission( $feed, $data, '10.00' );

		$this->assertSame( 'Ana Example', $mapped['client']['name'] );
		$this->assertSame( 'ana@example.test', $mapped['client']['email'] );
		$this->assertSame( 'Donativo', $mapped['lines'][0]['item'] );
		$this->assertSame( '10.00', $mapped['lines'][0]['price'] );
	}

	/**
	 * The global api key comes from the plugin's own settings and never falls back to the
	 * WeoInvoice WooCommerce plugin option.
	 *
	 * @return void
	 */
	public function test_global_api_key_reads_own_settings_only(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'FROM_OWN' ] );
		update_option( 'weoinvoice_api_key', 'FROM_WEO' );

		$this->assertSame( 'FROM_OWN', ( new Feed( wpFluentForm() ) )->global_api_key() );

		update_option( Feed::OPTION_KEY, [ 'api_key' => '' ] );
		$this->assertSame( '', ( new Feed( wpFluentForm() ) )->global_api_key() );

		delete_option( 'weoinvoice_api_key' );
		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * The can_register gate is true because the Fluent Forms integration base class is present.
	 *
	 * @return void
	 */
	public function test_can_register_true_when_fluent_forms_present(): void {
		$this->assertTrue( ( new Integration() )->can_register() );
	}

	/**
	 * Registering wires the concrete feed onto init, and boot registers the feed's admin hooks.
	 *
	 * @return void
	 */
	public function test_register_and_boot(): void {
		$integration = new Integration();
		$integration->register();

		$this->assertNotFalse( has_action( 'init', [ $integration, 'boot' ] ) );

		$addons_callbacks = function (): int {
			$hook = $GLOBALS['wp_filter']['fluentform/global_addons'] ?? null;

			return $hook ? count( $hook->callbacks[90] ?? [] ) : 0;
		};
		$before           = $addons_callbacks();

		$integration->boot();

		// Boot registers the feed's admin hooks, which list the add-on at the feed's priority.
		$this->assertSame( $before + 1, $addons_callbacks() );
		$this->assertInstanceOf( Feed::class, new Feed( wpFluentForm() ) );

		remove_action( 'init', [ $integration, 'boot' ], 9 );
	}
}
