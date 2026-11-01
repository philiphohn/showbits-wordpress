<?php
/**
 * What the plugin keeps: its settings and the connection.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Two options, neither loaded on every request: `showbits_settings` (where ShowBits is, the defaults for imports) and
 * `showbits_connection` (the workspace, the site's id in ShowBits, the site's own key and the push secret, both sealed).
 */
final class Options {
	public const SETTINGS   = 'showbits_settings';
	public const CONNECTION = 'showbits_connection';

	public const DEFAULT_APP_URL = 'https://app.showbits.ai';
	/** A quote card's pictures; 4:5 is what feeds and most article layouts take. */
	public const CARD_FORMATS = array( '4x5', '1x1', '9x16' );

	/**
	 * The settings, with their defaults.
	 *
	 * @return array{app_url: string, card_format: string, import_poster: bool}
	 */
	public static function settings(): array {
		$stored = get_option( self::SETTINGS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$url    = isset( $stored['app_url'] ) && is_string( $stored['app_url'] ) ? self::app_url( $stored['app_url'] ) : null;
		$format = isset( $stored['card_format'] ) && in_array( $stored['card_format'], self::CARD_FORMATS, true ) ? $stored['card_format'] : '4x5';
		return array(
			'app_url'       => $url ?? self::DEFAULT_APP_URL,
			'card_format'   => $format,
			'import_poster' => ! isset( $stored['import_poster'] ) || (bool) $stored['import_poster'],
		);
	}

	/**
	 * Stores the settings that are given (the rest stay).
	 *
	 * @param array<string, mixed> $changes What changed.
	 */
	public static function save_settings( array $changes ): void {
		update_option( self::SETTINGS, array_merge( self::settings(), $changes ), false );
	}

	/**
	 * ShowBits' address as the plugin keeps it: https, no user, password, query or fragment, no trailing slash. null for
	 * anything else (OnPremise installations have their own address; it is never plain http).
	 *
	 * @param string $raw What was entered.
	 */
	public static function app_url( string $raw ): ?string {
		$raw   = trim( $raw );
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		if ( ! preg_match( '/^[A-Za-z0-9.-]+$/', $parts['host'] ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = rtrim( $parts['path'] ?? '', '/' );
		return 'https://' . strtolower( $parts['host'] ) . $port . $path;
	}

	/**
	 * The connection with its secrets opened, or null: not connected, or the secrets can no longer be opened (the
	 * salts changed).
	 *
	 * @return array{app_url: string, api: string, site_id: string, workspace_id: string, workspace_slug: string, workspace_name: string, key: string, push_secret: string, connected_at: string, connected_by: int}|null
	 */
	public static function connection(): ?array {
		$stored = get_option( self::CONNECTION );
		if ( ! is_array( $stored ) || empty( $stored['key'] ) || empty( $stored['push_secret'] ) ) {
			return null;
		}
		$key    = Crypto::open( (string) $stored['key'] );
		$secret = Crypto::open( (string) $stored['push_secret'] );
		if ( null === $key || null === $secret ) {
			return null;
		}
		return array(
			'app_url'        => (string) ( $stored['app_url'] ?? '' ),
			'api'            => (string) ( $stored['api'] ?? '' ),
			'site_id'        => (string) ( $stored['site_id'] ?? '' ),
			'workspace_id'   => (string) ( $stored['workspace_id'] ?? '' ),
			'workspace_slug' => (string) ( $stored['workspace_slug'] ?? '' ),
			'workspace_name' => (string) ( $stored['workspace_name'] ?? '' ),
			'key'            => $key,
			'push_secret'    => $secret,
			'connected_at'   => (string) ( $stored['connected_at'] ?? '' ),
			'connected_by'   => (int) ( $stored['connected_by'] ?? 0 ),
		);
	}

	/** Whether a connection is stored, even one whose secrets can no longer be opened (then: connect again). */
	public static function has_connection(): bool {
		return is_array( get_option( self::CONNECTION ) );
	}

	/**
	 * Stores a new connection, sealing its secrets.
	 *
	 * @param array<string, mixed> $connection What the exchange answered, plus where ShowBits is and who connected.
	 */
	public static function save_connection( array $connection ): void {
		$connection['key']         = Crypto::seal( (string) $connection['key'] );
		$connection['push_secret'] = Crypto::seal( (string) $connection['push_secret'] );
		update_option( self::CONNECTION, $connection, false );
	}

	public static function forget_connection(): void {
		delete_option( self::CONNECTION );
	}
}
