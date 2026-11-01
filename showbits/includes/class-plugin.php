<?php
/**
 * Hooks.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/** Where the plugin plugs into WordPress. */
final class Plugin {
	public const CRON = 'showbits_process_imports';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'translations' ) );
		Imports::maybe_upgrade();

		add_action( 'rest_api_init', array( Push_Endpoint::class, 'register' ) );
		add_action( 'rest_api_init', array( Library_Endpoint::class, 'register' ) );
		add_action( self::CRON, array( Importer::class, 'process_queue' ) );

		add_action( 'admin_menu', array( Library_Page::class, 'menu' ) );
		add_action( 'admin_menu', array( Settings_Page::class, 'menu' ) );
		add_action( 'admin_post_showbits_connect', array( Connection::class, 'start' ) );
		add_action( 'admin_post_showbits_callback', array( Connection::class, 'callback' ) );
		add_action( 'admin_post_showbits_disconnect', array( Connection::class, 'disconnect' ) );
		add_action( 'admin_post_showbits_settings', array( Settings_Page::class, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHOWBITS_FILE ), array( Settings_Page::class, 'action_links' ) );
	}

	/**
	 * The plugin's own translations (languages/, German included). It is handed out as a ZIP, not from WordPress.org:
	 * translate.wordpress.org has none of its strings, so WordPress would find no translation by itself.
	 */
	public static function translations(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- bundled translations, see above
		load_plugin_textdomain( 'showbits', false, dirname( plugin_basename( SHOWBITS_FILE ) ) . '/languages' );
	}

	public static function activate(): void {
		Imports::install();
	}

	/** Pushes still queued wait for the next activation; nothing runs meanwhile. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Who may browse the library and import (Authors and up, as for uploads); the filter showbits_capability changes it. */
	public static function capability(): string {
		return (string) apply_filters( 'showbits_capability', 'upload_files' );
	}

	/** The menu's name: ShowBits, or another for white-label setups (filter showbits_menu_label). */
	public static function label(): string {
		return (string) apply_filters( 'showbits_menu_label', 'ShowBits' );
	}
}
