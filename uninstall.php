<?php
/**
 * Uninstall cleanup.
 *
 * Deletes the global settings, which hold the WeoInvoice API key. The per-form feeds and the
 * emit results live in Fluent Forms' own tables, and Fluent Forms removes them with their form
 * or entry.
 *
 * @package Outstand
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Mirrors Feed::OPTION_KEY; hardcoded because the plugin's autoloader may not run on uninstall.
delete_option( 'os_ff_weoinvoice_settings' );
