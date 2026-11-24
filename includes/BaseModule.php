<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for plugin modules.
 *
 * Provides the registration lifecycle: hooks are wired in register(), never in a constructor, and a
 * module can opt out of registration via can_register(). Plugin::enable() drives both.
 */
abstract class BaseModule {

	/**
	 * Registers the module's hooks.
	 *
	 * @return void
	 */
	abstract public function register(): void;

	/**
	 * Whether this module can be registered.
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return true;
	}
}
