<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\BaseModule;
use WP_UnitTestCase;

/**
 * BaseModule registration lifecycle defaults.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\BaseModule
 */
class BaseModuleTest extends WP_UnitTestCase {

	/**
	 * A minimal subclass inherits the default can_register of true.
	 *
	 * @return void
	 */
	public function test_can_register_defaults_to_true(): void {
		$module = new class() extends BaseModule {
			/**
			 * No-op registration for the test double.
			 *
			 * @return void
			 */
			public function register(): void {}
		};

		$this->assertTrue( $module->can_register() );
	}
}
