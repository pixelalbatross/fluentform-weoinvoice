<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WeoInvoice integration with Fluent Forms' IntegrationManager.
 *
 * Registers nothing unless Fluent Forms is active. The concrete feed is instantiated on `init`
 * (priority 9), and its admin hooks register the global-settings tab and the per-form feed UI.
 */
class Integration extends BaseModule {

	/**
	 * Registers nothing unless the Fluent Forms integration base class is present.
	 */
	public function can_register(): bool {
		return class_exists( \FluentForm\App\Http\Controllers\IntegrationManagerController::class );
	}

	/**
	 * Hooks the concrete feed onto `init`.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'boot' ], 9 );
	}

	/**
	 * Instantiates the concrete Fluent Forms feed and registers its admin hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		$feed = new Feed( null );
		$feed->registerAdminHooks();
	}
}
