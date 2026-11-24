<?php // phpcs:ignore Generic.Commenting.DocComment.MissingShort
/**
 * @wordpress-plugin
 * Plugin Name:       Fluent Forms - WeoInvoice
 * Description:       Issues a WeoInvoice document when a Fluent Forms payment is paid. Works with any Fluent Forms payment method, such as ifthenpay.
 * Plugin URI:        https://outstand.site/?utm_source=wp-plugins&utm_medium=fluentform-weoinvoice&utm_campaign=plugin-uri
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Version:           1.0.0
 * Author:            Outstand
 * Author URI:        https://outstand.site/?utm_source=wp-plugins&utm_medium=fluentform-weoinvoice&utm_campaign=author-uri
 * License:           GPL-3.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-3.0-or-later.html
 * Update URI:        https://outstand.site/
 * GitHub Plugin URI: https://github.com/pixelalbatross/fluentform-weoinvoice
 * Text Domain:       fluentform-weoinvoice
 * Domain Path:       /languages
 * Requires Plugins:  fluentform
 *
 * @package Outstand
 */

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$plugin_version = '1.0.0';
if ( file_exists( __DIR__ . '/.commit' ) ) {
	$plugin_version .= '-' . trim( (string) file_get_contents( __DIR__ . '/.commit' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

define( 'OUTSTAND_FLUENTFORM_WEOINVOICE_VERSION', $plugin_version );
define( 'OUTSTAND_FLUENTFORM_WEOINVOICE_BASENAME', plugin_basename( __FILE__ ) );
define( 'OUTSTAND_FLUENTFORM_WEOINVOICE_URL', plugin_dir_url( __FILE__ ) );
define( 'OUTSTAND_FLUENTFORM_WEOINVOICE_PATH', plugin_dir_path( __FILE__ ) );

if ( file_exists( OUTSTAND_FLUENTFORM_WEOINVOICE_PATH . 'vendor/autoload.php' ) ) {
	require_once OUTSTAND_FLUENTFORM_WEOINVOICE_PATH . 'vendor/autoload.php';
}

if ( class_exists( PucFactory::class ) ) {
	PucFactory::buildUpdateChecker(
		'https://github.com/pixelalbatross/fluentform-weoinvoice/',
		__FILE__,
		'fluentform-weoinvoice'
	)->setBranch( 'main' );
}

add_action(
	'plugins_loaded',
	function () {
		Plugin::get_instance()->enable();
	}
);
