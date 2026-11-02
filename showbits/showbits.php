<?php
/**
 * Plugin Name:       ShowBits
 * Plugin URI:        https://showbits.ai/en/integrations#cms
 * Description:       Released clips and quote cards from ShowBits in the media library: pick them in WordPress, or receive what your ShowBits admins send.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            HBC Hohn Business Consulting UG
 * Author URI:        https://www.hbc-berlin.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       showbits
 * Domain Path:       /languages
 *
 * @package ShowBits
 */

// The plugin's way into ShowBits (docs/wordpress.md in the showbits-app repository): connected with a site key made in
// ShowBits (or through ShowBits' consent page), it has a key of its own that opens only the plugin's routes (not
// ShowBits' REST API), lists the workspace's released clips, and takes their files when ShowBits pushes them to this
// site, signed and with a ticket. No file leaves ShowBits unreleased or before its embargo ends: ShowBits checks that on
// every file, this plugin only asks.

defined( 'ABSPATH' ) || exit;

const SHOWBITS_VERSION = '1.0.0';
const SHOWBITS_FILE    = __FILE__;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ShowBits\\WordPress\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/class-' . strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( ShowBits\WordPress\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( ShowBits\WordPress\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( ShowBits\WordPress\Plugin::class, 'boot' ) );
