<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\Plugin;
use WP_UnitTestCase;

/**
 * Plugin singleton and module wiring.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\Plugin
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * The get_instance accessor returns the same singleton on repeated calls.
	 *
	 * @return void
	 */
	public function test_get_instance_is_singleton(): void {
		$this->assertSame( Plugin::get_instance(), Plugin::get_instance() );
	}

	/**
	 * Enabling wires the registerable modules; the always-on retry handler is observable.
	 *
	 * @return void
	 */
	public function test_enable_registers_modules(): void {
		// EntryStatus registers unconditionally, so its admin-post handler is present after enable.
		Plugin::get_instance()->enable();

		$this->assertNotFalse(
			has_action( 'admin_post_outstand_fluentform_weoinvoice_retry' )
		);
	}
}
