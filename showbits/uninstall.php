<?php
/**
 * Deleting the plugin: the connection ends in ShowBits too (as far as ShowBits can be reached), its options, table and
 * scheduled work go. Attachments it imported stay in the media library.
 *
 * @package ShowBits
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-crypto.php';
require_once __DIR__ . '/includes/class-options.php';
require_once __DIR__ . '/includes/class-api-client.php';
require_once __DIR__ . '/includes/class-imports.php';

$showbits_client = ShowBits\WordPress\Api_Client::connected();
if ( $showbits_client ) {
	$showbits_client->leave();
}

delete_option( ShowBits\WordPress\Options::SETTINGS );
delete_option( ShowBits\WordPress\Options::CONNECTION );
delete_transient( 'showbits_status' );
delete_transient( 'showbits_pairing' );
wp_clear_scheduled_hook( 'showbits_process_imports' );
ShowBits\WordPress\Imports::drop();
